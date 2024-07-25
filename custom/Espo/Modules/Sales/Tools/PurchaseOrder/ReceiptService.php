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

namespace Espo\Modules\Sales\Tools\PurchaseOrder;

use Espo\Core\Utils\Metadata;
use Espo\Modules\Sales\Entities\PurchaseOrder;
use Espo\Modules\Sales\Entities\PurchaseOrderItem;
use Espo\Modules\Sales\Entities\ReceiptOrder;
use Espo\Modules\Sales\Entities\ReceiptOrderItem;
use Espo\Modules\Sales\Entities\ReturnOrder;
use Espo\Modules\Sales\Entities\ReturnOrderItem;
use Espo\ORM\EntityManager;
use stdClass;

class ReceiptService
{
    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata
    ) {}

    public function controlReceiptFullyCreated(PurchaseOrder|ReturnOrder $purchaseOrder, bool $noSave = false): void
    {
        $items = $this->getItems($purchaseOrder, $noSave);

        if (count($items) === 0) {
            if (!$purchaseOrder->isReceiptFullyCreated()) {
                return;
            }

            $purchaseOrder->set('isReceiptFullyCreated', false);

            if ($noSave) {
                return;
            }

            $this->entityManager->saveEntity($purchaseOrder);

            return;
        }

        $map1 = [];
        $map2 = [];

        foreach ($items as $item) {
            $quantity = $item->getQuantity();
            $productId = $item->getProduct()?->getId();

            if (!$productId) {
                continue;
            }

            $map1[$productId] ??= 0.0;
            $map1[$productId] += $quantity;
        }

        $idAttribute = lcfirst($purchaseOrder->getEntityType()) . 'Id';

        $receiptOrders = $this->entityManager
            ->getRDBRepository(ReceiptOrder::ENTITY_TYPE)
            ->where([
                $idAttribute => $purchaseOrder->getId(),
                'status!=' => $this->getCanceledStatusList(),
            ])
            ->find();

        foreach ($receiptOrders as $receiptOrder) {
            $receiptItems = $this->entityManager
                ->getRDBRepository(ReceiptOrderItem::ENTITY_TYPE)
                ->where(['receiptOrderId' => $receiptOrder->getId()])
                ->find();

            foreach ($receiptItems as $item) {
                /** @var ReceiptOrderItem $item */
                $quantity = $item->getQuantity();
                $productId = $item->getProduct()?->getId();

                if (!$productId) {
                    continue;
                }

                $map2[$productId] ??= 0.0;
                $map2[$productId] += $quantity;
            }
        }

        $isFullyCreated = true;

        foreach ($map1 as $id => $quantity) {
            $receiptQuantity = $map2[$id] ?? 0.0;

            if ($quantity > $receiptQuantity) {
                $isFullyCreated = false;
            }
        }

        if ($purchaseOrder->isReceiptFullyCreated() === $isFullyCreated) {
            return;
        }

        $purchaseOrder->set('isReceiptFullyCreated', $isFullyCreated);

        if ($noSave) {
            return;
        }

        $this->entityManager->saveEntity($purchaseOrder);
    }

    private function getCanceledStatusList(): array
    {
        return $this->metadata->get('scopes.ReceiptOrder.canceledStatusList') ?? [];
    }

    /**
     * @return PurchaseOrderItem[]|ReturnOrderItem[]
     */
    private function getItems(PurchaseOrder|ReturnOrder $purchaseOrder, bool $noSave): array
    {
        $itemEntityType = $purchaseOrder->getEntityType() . 'Item';
        $idAttribute = lcfirst($purchaseOrder->getEntityType()) . 'Id';

        if (!$noSave) {
            $items = $this->entityManager
                ->getRDBRepository($itemEntityType)
                ->where([$idAttribute => $purchaseOrder->getId()])
                ->find();

            return iterator_to_array($items);
        }

        $items = [];

        /** @var stdClass[] $list */
        $rawList = $purchaseOrder->get('itemList') ?? [];

        foreach ($rawList as $rawItem) {
            $item = $this->entityManager
                ->getRDBRepository($itemEntityType)
                ->getNew();

            $item->set($rawItem);
            $item->setAsNotNew();

            $items[] = $item;
        }

        return $items;
    }
}
