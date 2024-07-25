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

use Espo\Core\Utils\Metadata;
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\Modules\Sales\Entities\Product;
//use Espo\Modules\Sales\Entities\ReceiptOrder;
//use Espo\Modules\Sales\Entities\TransferOrder;
//use Espo\Modules\Sales\Entities\DeliveryOrder;
use Espo\Modules\Sales\Tools\Sales\OrderEntity;
use Espo\ORM\EntityManager;
use stdClass;

class BeforeSaveProcessor
{
    private const ROUND_PRECISION = 2;
    private const ROUND_INTERMEDIATE_PRECISION = 4;

    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata
    ) {}

    public function process(OrderEntity $quote): void
    {
        $this->setItems($quote);
        $this->setAccount($quote);
        $this->setNumber($quote);
        $this->setName($quote);
    }

    private function setItems(OrderEntity $quote): void
    {
        $itemList = $quote->get('itemList');

        if ($quote->has('itemList') && is_array($itemList)) {
            foreach ($itemList as $i => $o) {
                if (!is_array($o)) {
                    continue;
                }

                $itemList[$i] = (object) $o;

                $quote->set('itemList', $itemList);
            }

            $this->calculateItems($quote);

            if ($quote->hasAttribute('hasInventoryItems')) {
                $quote->set('hasInventoryItems', $quote->getInventoryProductIds() !== []);
            }

            return;
        }

        if ($quote->isAttributeChanged('shippingCost')) {;
            $quote->loadItemListField();

            $this->calculateItems($quote);
        }
    }

    private function calculateItems(OrderEntity $order): void
    {
        $itemList = $order->get('itemList');

        if ($order->has('amountCurrency')) {
            foreach ($itemList as $o) {
                $o->listPriceCurrency = $order->get('amountCurrency');
                $o->unitPriceCurrency = $order->get('amountCurrency');
                $o->amountCurrency = $order->get('amountCurrency');
            }
        }

        foreach ($itemList as $o) {
            $o->quantity ??= null;

            if (
                !isset($o->amount) && // @todo Revise.
                isset($o->unitPrice) && isset($o->quantity)
            ) {
                $o->amount = (float) $o->unitPrice * $o->quantity;
            }
        }

        $discountAmount = 0.0;
        $taxAmount = 0.0;

        foreach ($itemList as $o) {
            $this->calculateItem(
                $o,
                $order,
                $discountAmount,
                $taxAmount
            );
        }

        $taxAmount = round($taxAmount, self::ROUND_PRECISION);
        $discountAmount = round($discountAmount, self::ROUND_PRECISION);

        if (count($itemList)) {
            $amount = 0.0;
            $weight = 0.0;

            foreach ($itemList as $o) {
                $itemAmount = (float) ($o->amount ?? 0.0);
                $amount += round($itemAmount, self::ROUND_INTERMEDIATE_PRECISION);

                if (!is_null($o->weight)) {
                    $weight += round($o->weight, self::ROUND_INTERMEDIATE_PRECISION);
                }
            }

            $amount = round($amount, self::ROUND_PRECISION);

            $order->set('amount', $amount);
            $order->set('weight', $weight);

            $shippingCost = (float) $order->get('shippingCost');

            $grandTotalAmount = $amount + $taxAmount + $shippingCost;
            $grandTotalAmount = round($grandTotalAmount, self::ROUND_PRECISION);
        }
        else {
            $amount = (float) $order->get('amount');

            $grandTotalAmount = $amount;
        }

        $preDiscountedAmount = $amount + $discountAmount;
        $preDiscountedAmount = round($preDiscountedAmount, self::ROUND_PRECISION);

        $itemList = $this->sanitizeItemList($itemList, $order->getEntityType());

        $order->set('itemList', $itemList);
        $order->set('grandTotalAmount', $grandTotalAmount);
        $order->set('discountAmount', $discountAmount);
        $order->set('taxAmount', $taxAmount);
        $order->set('preDiscountedAmount', $preDiscountedAmount);

        if ($order->has('amountCurrency')) {
            $order->set('discountAmountCurrency', $order->get('amountCurrency'));
            $order->set('grandTotalAmountCurrency', $order->get('amountCurrency'));
            $order->set('taxAmountCurrency', $order->get('amountCurrency'));
            $order->set('preDiscountedAmountCurrency', $order->get('amountCurrency'));
        }
    }

    private function setAccount(OrderEntity $quote): void
    {
        if ($quote->get('accountId')) {
            return;
        }

        $opportunityId = $quote->get('opportunityId');

        if (!$opportunityId) {
            return;
        }

        $opportunity = $this->entityManager->getEntityById(Opportunity::ENTITY_TYPE, $opportunityId);

        if (!$opportunity) {
            return;
        }

        $accountId = $opportunity->get('accountId');

        if (!$accountId) {
            return;
        }

        $quote->set('accountId', $accountId);
    }

    private function setNumber(OrderEntity $quote): void
    {
        if (
            !$quote->isNew() ||
            !$this->metadata->get(['entityDefs', $quote->getEntityType(), 'fields', 'number', 'useAutoincrement'])
        ) {
            return;
        }

        if ($quote->get('number')) {
            return;
        }

        $quote->set('number', $quote->get('numberA'));
    }

    private function setName(OrderEntity $quote): void
    {
        if ($quote->get('name')) {
            return;
        }

        $quote->set('name', $quote->get('number'));
    }

    /**
     * @param float $discountAmount
     * @param float $taxAmount
     * @return float[]
     */
    public function calculateItem(
        stdClass $item,
        OrderEntity $order,
        float &$discountAmount,
        float &$taxAmount
    ): array {

        $item->unitWeight ??= null;

        $productId = $item->productId ?? null;

        if ($productId) {
            //$product = null;

            if ($item->unitWeight === null && $order->isNew()) {
                /** @var ?Product $product */
                $product = $this->entityManager->getEntityById(Product::ENTITY_TYPE, $productId);

                if ($product) {
                    $item->unitWeight = $product->getWeight();
                }
            }

            /*if (
                $order instanceof ReceiptOrder ||
                $order instanceof DeliveryOrder ||
                $order instanceof TransferOrder
            ) {
                if (!$product) {
                    /\** @var ?Product $product *\/
                    $product = $this->entityManager->getEntityById(Product::ENTITY_TYPE, $productId);
                }

                if ($product) {
                    $item->inventoryNumberType = $product->getInventoryNumberType();
                }
            }*/
        }

        $item->weight = $item->unitWeight !== null && isset($item->quantity) ?
            round($item->unitWeight * $item->quantity, self::ROUND_INTERMEDIATE_PRECISION) :
            null;

        $item->accountId = $order->getAccount()?->getId();
        $item->accountName = $order->getAccount()?->getName();

        $item->discount = 0.0;

        $itemUnitPrice = 0.0;

        if (
            isset($item->unitPrice) &&
            isset($item->quantity)
        ) {
            $itemUnitPrice = (float) $item->unitPrice;
            $itemListPrice = (float) $item->listPrice;

            if ($item->listPrice) {
                $itemDiscount = (($itemListPrice - $itemUnitPrice) / $itemListPrice) * 100.0;
                $item->discount = round($itemDiscount, self::ROUND_PRECISION);
            }

            $itemDiscountAmount = ($itemListPrice - $itemUnitPrice) * $item->quantity;

            $discountAmount += round($itemDiscountAmount, self::ROUND_INTERMEDIATE_PRECISION);
        }

        if (
            isset($item->unitPrice) &&
            isset($item->quantity) &&
            !empty($item->taxRate)
        ) {
            $itemTaxAmount = $itemUnitPrice * $item->quantity * $item->taxRate / 100.0;

            $taxAmount += round($itemTaxAmount, self::ROUND_INTERMEDIATE_PRECISION);
        }

        return [$discountAmount, $taxAmount];
    }

    /**
     * @param stdClass[] $itemList
     * @param string $entityType
     * @return stdClass[]
     */
    private function sanitizeItemList(array $itemList, string $entityType): array
    {
        return array_map(
            fn ($item) => $this->sanitizeItem($item, $entityType),
            $itemList
        );
    }

    private function sanitizeItem(stdClass $item, string $entityType): stdClass
    {
        $entity = $this->entityManager->getNewEntity($entityType . 'Item');

        $entity->set($item);

        return $entity->getValueMap();
    }
}
