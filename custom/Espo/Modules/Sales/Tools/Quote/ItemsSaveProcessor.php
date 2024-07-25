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

namespace Espo\Modules\Sales\Tools\Quote;

use Espo\Modules\Crm\Entities\Opportunity;
use Espo\Modules\Sales\Entities\Product;
use Espo\Modules\Sales\Entities\QuoteItem;
use Espo\Modules\Sales\Entities\OpportunityItem;
use Espo\Modules\Sales\Tools\Sales\OrderEntity;
use Espo\ORM\Collection;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

use stdClass;

class ItemsSaveProcessor
{
    public function __construct(
        private EntityManager $entityManager
    ) {}

    public function process(OrderEntity|Opportunity $quote, bool $isNew): void
    {
        $itemEntityType = $quote->getEntityType() . 'Item';
        $itemParentIdAttribute = lcfirst($quote->getEntityType()) . 'Id';

        if (!$quote->has('itemList')) {
            if (!$quote->isAttributeChanged('accountId')) {
                return;
            }

            $quoteItemList = $this->entityManager
                ->getRDBRepository($itemEntityType)
                ->where([$itemParentIdAttribute => $quote->getId()])
                ->find();

            foreach ($quoteItemList as $item) {
                $item->set('accountId', $quote->get('accountId'));

                $this->entityManager->saveEntity($item);
            }

            return;
        }

        $itemList = $quote->get('itemList');
        $currency = $quote->get('amountCurrency');

        if (!is_array($itemList)) {
            return;
        }

        $toCreateList = [];
        $toUpdateList = [];
        $toRemoveList = [];

        $this->startTransactionAndLock($quote->getId(), $itemEntityType, $itemParentIdAttribute);

        if (!$isNew) {
            /** @var Collection<Entity> $prevItemCollection */
            $prevItemCollection = $this->entityManager
                ->getRDBRepository($itemEntityType)
                ->where([$itemParentIdAttribute => $quote->getId()])
                ->order('order')
                ->find();

            foreach ($prevItemCollection as $item) {
                $exists = false;

                $item->loadAllLinkMultipleFields();

                foreach ($itemList as $data) {
                    if ($item->getId() === $data->id) {
                        $exists = true;
                    }
                }

                if (!$exists) {
                    $toRemoveList[] = $item;
                }
            }

            $quote->setFetched('itemList', $prevItemCollection->getValueMapList());
        }

        $order = 0;

        foreach ($itemList as $rawItem) {
            $order++;
            $exists = false;

            if (!$isNew) {
                foreach ($prevItemCollection as $prevItem) {
                    /** @var QuoteItem $prevItem */

                    if ($rawItem->id !== $prevItem->getId()) {
                        continue;
                    }

                    $isChanged = $this->isItemChanged($itemEntityType, $prevItem, $rawItem);

                    if (!$isChanged && $prevItem->getOrder() !== $order) {
                        $isChanged = true;
                    }

                    $exists = true;

                    if (!$isChanged) {
                        break;
                    }

                    $this->setItemWithData($prevItem, $rawItem, $itemParentIdAttribute, $currency);

                    $prevItem->set('order', $order);
                    $prevItem->set($itemParentIdAttribute, $quote->getId());

                    $toUpdateList[] = $prevItem;

                    break;
                }
            }

            if (!$exists) {
                /** @var QuoteItem|OpportunityItem $item */
                $item = $this->entityManager->getNewEntity($itemEntityType);

                $this->setItemWithData($item, $rawItem, $itemParentIdAttribute, $currency);

                $item->set('order', $order);
                $item->set($itemParentIdAttribute, $quote->getId());
                /** @noinspection PhpRedundantOptionalArgumentInspection */
                $item->set('id', null);

                $toCreateList[] = $item;
            }
        }

        if ($isNew) {
            foreach ($toUpdateList as $item) {
                /** @noinspection PhpRedundantOptionalArgumentInspection */
                $item->set('id', null);

                $toCreateList[] = $item;
            }

            $toUpdateList = [];
        }

        foreach ($toRemoveList as $item) {
            $this->entityManager->removeEntity($item);
        }

        foreach ($toUpdateList as $item) {
            $this->entityManager->saveEntity($item);
        }

        foreach ($toCreateList as $item) {
            $this->entityManager->saveEntity($item);
        }

        $itemCollection = $this->entityManager
            ->getRDBRepository($itemEntityType)
            ->where([$itemParentIdAttribute => $quote->getId()])
            ->order('order')
            ->find();

        foreach ($itemCollection as $item) {
            $item->loadAllLinkMultipleFields();
        }

        $quote->set('itemList', $itemCollection->getValueMapList());

        $this->entityManager
            ->getTransactionManager()
            ->commit();
    }

    private function setItemWithData(
        QuoteItem|OpportunityItem $item,
        stdClass $o,
        string $itemParentIdAttribute,
        ?string $currency
    ): void {

        $data = [
            'id' => $o->id ?? null,
            'name' => $this->getAttributeFromItemObject($o, 'name'),
            'listPrice' => $this->getAttributeFromItemObject($o, 'listPrice'),
            'unitPrice' => $this->getAttributeFromItemObject($o, 'unitPrice'),
            'amount' => $this->getAttributeFromItemObject($o, 'amount'),
            'amountCurrency' => $this->getAttributeFromItemObject($o, 'amountCurrency'),
            'taxRate' => $this->getAttributeFromItemObject($o, 'taxRate'),
            'productId' => $this->getAttributeFromItemObject($o, 'productId'),
            'productName' => $this->getAttributeFromItemObject($o, 'productName'),
            'quantity' => $this->getAttributeFromItemObject($o, 'quantity'),
            'unitWeight' => $this->getAttributeFromItemObject($o, 'unitWeight'),
            'weight' => $this->getAttributeFromItemObject($o, 'weight'),
            'description' => $this->getAttributeFromItemObject($o, 'description'),
            'discount' => $this->getAttributeFromItemObject($o, 'discount'),
            'accountId' => $this->getAttributeFromItemObject($o, 'accountId'),
            'accountName' => $this->getAttributeFromItemObject($o, 'accountName'),
        ];

        $currencyAttributeList = [
            'listPrice',
            'unitPrice',
            'amount',
        ];

        foreach ($currencyAttributeList as $attribute) {
            if ($data[$attribute] === null) {
                $data[$attribute . 'Currency'] = null;

                continue;
            }

            $data[$attribute . 'Currency'] = $currency ?? $data['amountCurrency'];
        }

        $data['listPrice'] ??= $data['unitPrice'];
        $data['listPriceCurrency'] ??= $data['unitPriceCurrency'];

        $ignoreAttributeList = [
            $itemParentIdAttribute,
            'id',
            'name',
            'createdAt',
            'modifiedAt',
            'createdById',
            'createdByName',
            'modifiedById',
            'modifiedByName',
            'listPriceConverted',
            'unitPriceConverted',
            'amountConverted',
            'deleted',
        ];

        $productAttributeList = $this->entityManager
            ->getNewEntity(Product::ENTITY_TYPE)
            ->getAttributeList();

        foreach ($productAttributeList as $attribute) {
            if (in_array($attribute, $ignoreAttributeList) || array_key_exists($attribute, $data)) {
                continue;
            }

            if (!$item->hasAttribute($attribute)) {
                continue;
            }

            $item->set($attribute, $this->getAttributeFromItemObject($o, $attribute));

            if (
                $item->getAttributeType($attribute) === Entity::BOOL &&
                $item->get($attribute) === null
            ) {
                $item->set($attribute, false);
            }
        }

        foreach (get_object_vars($o) as $attribute => $value) {
            if (array_key_exists($attribute, $data)) {
                continue;
            }

            if (in_array($attribute, $ignoreAttributeList)) {
                continue;
            }

            $data[$attribute] = $value;
        }

        $item->set($data);
    }

    private function getAttributeFromItemObject(stdClass $data, string $attribute): mixed
    {
        return $data->$attribute ?? null;
    }

    private function startTransactionAndLock(string $id, string $itemEntityType, string $itemParentIdAttribute): void
    {
        $this->entityManager
            ->getTransactionManager()
            ->start();

        $this->entityManager
            ->getRDBRepository($itemEntityType)
            ->sth()
            ->select('id')
            ->forUpdate()
            ->where([$itemParentIdAttribute => $id])
            ->find();
    }

    private function isItemChanged(string $itemEntityType, QuoteItem $prevItem, stdClass $rawItem): bool
    {
        $seed = $this->entityManager->getNewEntity($itemEntityType);
        $seed->set($prevItem->getValueMap());
        $seed->setAsFetched();
        $seed->setAsNotNew();
        $seed->set($rawItem);

        foreach ($seed->getAttributeList() as $attr) {
            if ($prevItem->getAttributeType($attr) === Entity::FOREIGN) {
                continue;
            }

            if ($seed->isAttributeChanged($attr)) {
                return true;
            }

            /*
            $v1 = $seed->get($attr);
            $v0 = $prevItem->get($attr);
            //$bothNumeric = is_numeric($v1) && is_numeric($v0);

            if (
                ($bothNumeric && abs($v1 - $v0) > 0.00001) || // @todo Revise.
                (!$bothNumeric && $seed->isAttributeChanged($attr))
            ) {
                return true;
            }
            */
        }

        return false;
    }
}
