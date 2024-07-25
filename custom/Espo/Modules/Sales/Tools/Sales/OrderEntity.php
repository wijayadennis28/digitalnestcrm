<?php
/***********************************************************************************
 * The contents of this file are subject to the Extension License Agreement
 * ("Agreement") which can be viewed at
 * https://www.espocrm.com/extension-license-agreement/.
 * By copying, installing downloading, or using this file, You have unconditionally
 * agreed to the terms and conditions of the Agreement, and You may not use this
 * file except in compliance with the Agreement. Under the terms of the Agreement,
 * You shall not license, sublicense, sell, resell, rent, lease, lend, distribute,
 * redistribute, market, publish, commercialize, or otherwise transfer rights or
 * usage to the software or any modified version or derivative work of the software
 * created by or for you.
 *
 * Copyright (C) 2015-2024 Letrium Ltd.
 *
 * License ID: bcd3361258b6d66fc350488ed9575786
 ************************************************************************************/

namespace Espo\Modules\Sales\Tools\Sales;

use Espo\Core\Field\Date;
use Espo\Core\Field\DateTime;
use Espo\Core\Field\Link;
use Espo\Core\ORM\Entity;
use Espo\Modules\Sales\Entities\QuoteItem;

abstract class OrderEntity extends Entity
{
    public const INVENTORY_STATUS_AVAILABLE = 'Available';
    public const INVENTORY_STATUS_ON_HAND = 'On Hand';
    public const INVENTORY_STATUS_NOT_AVAILABLE = 'Not Available';

    public function getName(): ?string
    {
        return $this->get('name');
    }

    public function getNumber(): ?string
    {
        return $this->get('number');
    }

    /**
     * @return OrderItem[]
     */
    public function getItems(): array
    {
        return array_map(
            fn ($item) => OrderItem::fromRaw($item),
            $this->get('itemList') ?? []
        );
    }

    /**
     * @param OrderItem[] $items
     */
    public function setItems(array $items): self
    {
        $rawItems = array_map(
            fn ($item) => $item->toRaw(),
            $items
        );

        $this->set('itemList', $rawItems);

        return $this;
    }

    public function getStatus(): string
    {
        return $this->get('status');
    }

    public function getFetchedStatus(): ?string
    {
        return $this->getFetched('status');
    }

    public function getAccount(): ?Link
    {
        /** @var ?Link */
        return $this->getValueObject('account');
    }

    public function loadItemListField(): void
    {
        $itemEntityType = $this->getEntityType() . 'Item';
        $itemParentIdAttribute = lcfirst($this->getEntityType()) . 'Id';

        $items = $this->entityManager
            ->getRDBRepository($itemEntityType)
            ->where([$itemParentIdAttribute => $this->getId()])
            ->order('order')
            ->find();

        foreach ($items as $item) {
            /** @var QuoteItem $item */
            $item->loadAllLinkMultipleFields();
        }

        $mapList = $items->getValueMapList();

        $this->set('itemList', $mapList);

        if (!$this->hasFetched('itemList')) {
            $this->setFetched('itemList', $mapList);
        }
    }

    /**
     * @return array<string, float>
     */
    public function getProductIdQuantityMap(): array
    {
        $map = [];

        foreach ($this->get('itemList') ?? [] as $item) {
            $productId = $item->productId ?? null;
            $quantity = $item->quantity ?? null;

            if (!$productId || !$quantity) {
                continue;
            }

            $map[$productId] ??= 0.0;
            $map[$productId] += $quantity;
        }

        return $map;
    }

    /**
     * @return string[]
     */
    public function getInventoryProductIds(): array
    {
        return array_keys($this->getProductIdQuantityMap());
    }

    public function getProductIds(): array
    {
        $ids = [];

        foreach ($this->get('itemList') ?? [] as $item) {
            $productId = $item->productId ?? null;

            if (!$productId || in_array($productId, $ids)) {
                continue;
            }

            $ids[] = $productId;
        }

        return $ids;
    }

    public function getDateCreatedAt(): Date
    {
        /** @var DateTime $createdAt */
        $createdAt = $this->getValueObject('createdAt');

        return Date::fromDateTime($createdAt->getDateTime());
    }

    public function setStatus(string $value): self
    {
        $this->set('status', $value);

        return $this;
    }

    public function isLocked(): bool
    {
        return $this->get('isLocked');
    }

    public function isNotActual(): bool
    {
        return $this->get('isNotActual');
    }
}
