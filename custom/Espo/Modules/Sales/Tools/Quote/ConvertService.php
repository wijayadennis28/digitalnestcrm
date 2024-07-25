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

use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Metadata;
use Espo\Modules\Crm\Entities\Account;
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\Modules\Sales\Entities\DeliveryOrder;
use Espo\Modules\Sales\Entities\DeliveryOrderItem;
use Espo\Modules\Sales\Entities\Product;
use Espo\Modules\Sales\Entities\PurchaseOrder;
use Espo\Modules\Sales\Entities\Quote;
use Espo\Modules\Sales\Entities\QuoteItem;
use Espo\Modules\Sales\Entities\ReceiptOrder;
use Espo\Modules\Sales\Entities\ReturnOrder;
use Espo\Modules\Sales\Entities\ReturnOrderItem;
use Espo\Modules\Sales\Entities\SalesOrder;
use Espo\Modules\Sales\Entities\SalesOrderItem;
use Espo\Modules\Sales\Entities\Tax;
use Espo\Modules\Sales\Tools\Sales\OrderEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Traversable;

class ConvertService
{
    /** @var string[] */
    private array $ignoreItemAttributeList = [
        'id',
        'createdById',
        'createdByName',
        'modifiedById',
        'modifiedByName',
        'createdAt',
        'modifiedAt',
    ];

    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private Metadata $metadata,
        private Config $config
    ) {}

    /**
     * @throws Forbidden
     * @throws NotFound
     * @return array<string, mixed>
     */
    public function getAttributes(string $targetType, string $sourceType, string $sourceId): array
    {
        $source = $this->entityManager->getEntityById($sourceType, $sourceId);

        $idAttribute = lcfirst($sourceType) . 'Id';

        if (!$source) {
            throw new NotFound();
        }

        if (!$this->acl->check($source, Table::ACTION_READ)) {
            throw new Forbidden();
        }

        $itemList = $this->getItems($source, $targetType);

        /** @noinspection PhpPossiblePolymorphicInvocationInspection */
        $source->loadLinkMultipleField('teams');

        $name = $source->get('name') === $source->get('number') ?
            null :
            $source->get('name');

        $attributes = [
            'name' => $name,
            'teamsIds' => $source->get('teamsIds'),
            'teamsNames' => $source->get('teamsNames'),
            $idAttribute => $sourceId,
            'itemList' => $itemList,
            'amount' => $source->get('amount'),
            'amountCurrency' => $source->get('amountCurrency'),
            'preDiscountedAmountCurrency' => $source->get('amountCurrency'),
            'taxAmount' => $source->get('taxAmount'),
            'taxAmountCurrency' => $source->get('amountCurrency'),
            'grandTotalAmountCurrency' => $source->get('amountCurrency'),
            'discountAmountCurrency' => $source->get('amountCurrency'),
            'shippingCost' => $source->get('shippingCost'),
            'shippingCostCurrency' => $source->get('amountCurrency'),
            'shippingProviderId' => $source->get('shippingProviderId'),
            'shippingProviderName' => $source->get('shippingProviderName'),
            'priceBookId' => $source->get('priceBookId'),
            'priceBookName' => $source->get('priceBookName'),
        ];

        if (
            $sourceType === Quote::ENTITY_TYPE ||
            $sourceType === SalesOrder::ENTITY_TYPE
        ) {
            $attributes['billingContactId'] = $source->get('billingContactId');
            $attributes['billingContactName'] = $source->get('billingContactName');
            $attributes['shippingContactId'] = $source->get('shippingContactId');
            $attributes['shippingContactName'] = $source->get('shippingContactName');
        }

        if ($source->hasAttribute('quoteId')) {
            $attributes['quoteId'] = $source->get('quoteId');
            $attributes['quoteName'] = $source->get('quoteName');
        }

        if ($source->hasAttribute('salesOrderId')) {
            $attributes['salesOrderId'] = $source->get('salesOrderId');
            $attributes['salesOrderName'] = $source->get('salesOrderName');
        }

        if ($source->hasAttribute('opportunityId')) {
            $attributes['opportunityId'] = $source->get('opportunityId');
            $attributes['opportunityName'] = $source->get('opportunityName');
        }

        $amount = $source->get('amount');

        if (!$amount) {
            $amount = 0;
        }

        $preDiscountedAmount = 0;

        foreach ($itemList as $item) {
            $itemListPrice = $item['listPrice'] ?? 0.0;
            $itemQuantity = $item['quantity'] ?? 0.0;

            $preDiscountedAmount += $itemListPrice * $itemQuantity;
        }

        $preDiscountedAmount = round($preDiscountedAmount, 2);

        $attributes['preDiscountedAmount'] = $preDiscountedAmount;

        $discountAmount = $preDiscountedAmount - $amount;
        $attributes['discountAmount'] = $discountAmount;

        $grandTotalAmount = $amount + $attributes['taxAmount'] + $attributes['shippingCost'];
        $attributes['grandTotalAmount'] = $grandTotalAmount;

        $attributes['accountId'] = $source->get('accountId');
        $attributes['accountName'] = $source->get('accountName');

        if ($sourceType === PurchaseOrder::ENTITY_TYPE) {
            $attributes['supplierId'] = $source->get('supplierId');
            $attributes['supplierName'] = $source->get('supplierName');
        }

        if (
            $sourceType === PurchaseOrder::ENTITY_TYPE ||
            $sourceType === ReturnOrder::ENTITY_TYPE
        ) {
            $attributes['shippingContactId'] = $source->get('shippingContactId');
            $attributes['shippingContactName'] = $source->get('shippingContactName');

            $attributes['warehouseId'] = $source->get('warehouseId');
            $attributes['warehouseName'] = $source->get('warehouseName');
        }

        $attributes['billingAddressStreet'] = $source->get('billingAddressStreet');
        $attributes['billingAddressCity'] = $source->get('billingAddressCity');
        $attributes['billingAddressState'] = $source->get('billingAddressState');
        $attributes['billingAddressCountry'] = $source->get('billingAddressCountry');
        $attributes['billingAddressPostalCode'] = $source->get('billingAddressPostalCode');

        if ($sourceType === SalesOrder::ENTITY_TYPE && $targetType === ReturnOrder::ENTITY_TYPE) {
            $attributes['fromAddressStreet'] = $source->get('shippingAddressStreet');
            $attributes['fromAddressCity'] = $source->get('shippingAddressCity');
            $attributes['fromAddressState'] = $source->get('shippingAddressState');
            $attributes['fromAddressCountry'] = $source->get('shippingAddressCountry');
            $attributes['fromAddressPostalCode'] = $source->get('shippingAddressPostalCode');
        }
        else {
            $attributes['shippingAddressStreet'] = $source->get('shippingAddressStreet');
            $attributes['shippingAddressCity'] = $source->get('shippingAddressCity');
            $attributes['shippingAddressState'] = $source->get('shippingAddressState');
            $attributes['shippingAddressCountry'] = $source->get('shippingAddressCountry');
            $attributes['shippingAddressPostalCode'] = $source->get('shippingAddressPostalCode');
        }

        $accountId = $source->get('accountId');

        if (!$accountId) {
            return $attributes;
        }

        if ($sourceType === Opportunity::ENTITY_TYPE) {
            $account = $this->entityManager->getEntityById(Account::ENTITY_TYPE, $accountId);

            if (!$account) {
                return $attributes;
            }

            if (!$source->get('priceBookId')) {
                $attributes['priceBookId'] = $account->get('priceBookId');
                $attributes['priceBookName'] = $account->get('priceBookName');
            }

            $attributes['billingAddressStreet'] = $account->get('billingAddressStreet');
            $attributes['billingAddressCity'] = $account->get('billingAddressCity');
            $attributes['billingAddressState'] = $account->get('billingAddressState');
            $attributes['billingAddressCountry'] = $account->get('billingAddressCountry');
            $attributes['billingAddressPostalCode'] = $account->get('billingAddressPostalCode');
            $attributes['shippingAddressStreet'] = $account->get('shippingAddressStreet');
            $attributes['shippingAddressCity'] = $account->get('shippingAddressCity');
            $attributes['shippingAddressState'] = $account->get('shippingAddressState');
            $attributes['shippingAddressCountry'] = $account->get('shippingAddressCountry');
            $attributes['shippingAddressPostalCode'] = $account->get('shippingAddressPostalCode');

            return $attributes;
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    public function copyItem(
        QuoteItem $item,
        float $defaultTaxRate,
        Entity $source
    ): array {

        $sourceItemType = $item->getEntityType();

        $item->loadAllLinkMultipleFields();

        $itemAttributes = [
            'name' => $item->get('name'),
            'productId' => $item->get('productId'),
            'productName' => $item->get('productName'),
            'unitPrice' => $item->get('unitPrice'),
            'unitPriceCurrency' => $item->get('unitPriceCurrency'),
            'amount' => $item->get('amount'),
            'amountCurrency' => $item->get('amountCurrency'),
            'quantity' => $item->get('quantity'),
            'taxRate' => $item->get('taxRate') ?? $defaultTaxRate,
            'listPrice' => $item->get('listPrice') ?? $item->get('unitPrice'),
            'listPriceCurrency' => $item->get('amountCurrency'),
            'description' => $item->get('description'),
        ];

        $productId = $item->get('productId');

        if ($productId && $item->get('listPrice') === null) {
            /** @var ?Product $product */
            $product = $this->entityManager->getEntityById(Product::ENTITY_TYPE, $productId);

            if ($product) {
                $listPrice = $product->getListPrice()?->getAmount();
                $listPriceCurrency = $product->getListPrice()?->getCode();

                // @todo Use currency converter.
                if ($listPriceCurrency != $source->get('amountCurrency')) {
                    $rates = $this->config->get('currencyRates', []);
                    $targetCurrency = $source->get('amountCurrency');

                    $value = $listPrice;

                    $rate1 = 1.0;

                    if (array_key_exists($listPriceCurrency, $rates)) {
                        $rate1 = $rates[$listPriceCurrency];
                    }

                    $rate2 = 1.0;

                    if (array_key_exists($targetCurrency, $rates)) {
                        $rate2 = $rates[$targetCurrency];
                    }

                    $value = $value * ($rate1);
                    $value = $value / ($rate2);

                    $listPrice = round($value, 2);
                    $listPriceCurrency = $targetCurrency;
                }

                $itemAttributes['listPrice'] = $listPrice;
                $itemAttributes['listPriceCurrency'] = $listPriceCurrency;

                if ($product->isTaxFree() && $item->get('taxRate') === null) {
                    $itemAttributes['taxRate'] = 0;
                }
            }
        }

        $attributeList = $this->entityManager
            ->getDefs()
            ->getEntity($sourceItemType)
            ->getAttributeNameList();

        foreach ($attributeList as $attribute) {
            if (
                !$item->hasAttribute($attribute) ||
                array_key_exists($attribute, $itemAttributes) ||
                in_array($attribute, $this->ignoreItemAttributeList)
            ) {
                continue;
            }

            $itemAttributes[$attribute] = $item->get($attribute);
        }

        return $itemAttributes;
    }

    /**
     * @return array<string, mixed>[]
     */
    private function getItems(
        Entity $source,
        string $targetType
    ): array {

        $defaultTaxRate = 0.0;

        $defaultTaxId = $this->metadata->get("entityDefs.$targetType.fields.tax.defaultAttributes.taxId");

        if ($defaultTaxId) {
            /** @var ?Tax $defaultTax */
            $defaultTax = $this->entityManager->getEntityById(Tax::ENTITY_TYPE, $defaultTaxId);

            if ($defaultTax) {
                $defaultTaxRate = $defaultTax->getRate();
            }
        }

        $sourceItemList = $this->getSourceItems($source);

        $salesOrderItems = null;

        if (
            $source instanceof SalesOrder &&
            $targetType === ReturnOrder::ENTITY_TYPE
        ) {
            if ($source->isDeliveryCreated()) {
                /** @var SalesOrderItem[] $salesOrderItems */
                $salesOrderItems = $sourceItemList;

                $sourceItemList = $this->getSalesOrderForReturnOrderSourceItems($source);
            }

            $sourceItemList = array_values(array_filter($sourceItemList, fn ($item) => $item->getProduct()));
        }

        $itemList = [];

        foreach ($sourceItemList as $item) {
            $itemList[] = $this->copyItem($item, $defaultTaxRate, $source);
        }

        if (
            $source instanceof SalesOrder &&
            $targetType === DeliveryOrder::ENTITY_TYPE
        ) {
            $itemList = $this->filterAlreadyCreatedDelivery($source, $itemList);
        }

        if (
            $source instanceof SalesOrder &&
            $targetType === ReturnOrder::ENTITY_TYPE
        ) {
            $itemList = $this->filterAlreadyCreatedReturns($source, $itemList);
        }

        if ($salesOrderItems){
            $this->applyPricesFromSalesOrderItems($itemList, $salesOrderItems, $defaultTaxRate, $source);
        }

        if ($targetType === ReceiptOrder::ENTITY_TYPE) {
            foreach ($itemList as &$item) {
                $item['quantityReceived'] ??= null;
            }
        }

        return $itemList;
    }

    /**
     * @return QuoteItem[]
     */
    private function getSourceItems(Entity $source): array
    {
        $sourceItemType = $source->getEntityType() . 'Item';
        $idAttribute = lcfirst($source->getEntityType()) . 'Id';

        $collection = $this->entityManager
            ->getRDBRepository($sourceItemType)
            ->where([$idAttribute => $source->getId()])
            ->order('order')
            ->find();

        return iterator_to_array($collection);
    }

    /**
     * @return QuoteItem[]
     */
    private function getSalesOrderForReturnOrderSourceItems(SalesOrder $source): array
    {
        /** @var string[] $doneStatusList */
        $doneStatusList = $this->metadata->get('scopes.DeliveryOrder.doneStatusList') ?? [];

        $deliveryOrders = $this->entityManager
            ->getRDBRepositoryByClass(DeliveryOrder::class)
            ->where([
                'salesOrderId' => $source->getId(),
                'status' => $doneStatusList,
            ])
            ->order('number')
            ->find();

        /** @var ReturnOrderItem[] $itemList */
        $itemList = [];

        foreach ($deliveryOrders as $deliveryOrder) {
            $itemList = array_merge(
                $itemList,
                $this->getSourceItems($deliveryOrder)
            );
        }

        return $itemList;
    }

    /**
     * @param array<string, mixed>[] $itemList
     * @param SalesOrderItem[] $salesOrderItems
     */
    private function applyPricesFromSalesOrderItems(
        array &$itemList,
        array $salesOrderItems,
        float $defaultTaxRate,
        Entity $source
    ): void {

        foreach ($itemList as &$item) {
            $productId = $item['productId'] ?? null;

            if (!$productId) {
                continue;
            }

            foreach ($salesOrderItems as $salesOrderItem) {
                if (
                    $salesOrderItem->getProduct() &&
                    $productId === $salesOrderItem->getProduct()->getId()
                ) {
                    $copiedItem = $this->copyItem($salesOrderItem, $defaultTaxRate, $source);

                    $item['taxRate'] = $copiedItem['taxRate'] ?? 0.0;
                    $item['unitPrice'] = $copiedItem['unitPrice'] ?? null;
                    $item['unitPriceCurrency'] = $copiedItem['unitPriceCurrency'] ?? null;
                    $item['amountCurrency'] = $item['unitPriceCurrency'];
                    $item['amount'] = round($item['unitPrice'] * $item['quantity'], 2);

                    continue 2;
                }
            }

            $item['taxRate'] = 0.0;
            $item['unitPrice'] = 0.0;
            $item['unitPriceCurrency'] = $source->get('amountCurrency');
            $item['amountCurrency'] = $source->get('amountCurrency');
            $item['amount'] = 0.0;
        }
    }

    /**
     * @param array<string, mixed>[] $inputItems
     * @return array<string, mixed>[]
     */
    private function filterAlreadyCreatedDelivery(SalesOrder $source, array $inputItems): array
    {
        $ignoreStatuses = array_merge(
            $this->metadata->get('scopes.DeliveryOrder.failedStatusList') ?? [],
            $this->metadata->get('scopes.DeliveryOrder.canceledStatusList') ?? [],
        );

        /** @var Traversable<int, DeliveryOrder> $deliveryOrders */
        $deliveryOrders = $this->entityManager
            ->getRDBRepositoryByClass(DeliveryOrder::class)
            ->where([
                'salesOrderId' => $source->getId(),
                'status!=' => $ignoreStatuses,
            ])
            ->find();

        if (iterator_count($deliveryOrders) === 0) {
            return $inputItems;
        }

        [$items, $map] = $this->getQuantityMapAndItems($inputItems, $deliveryOrders, DeliveryOrderItem::ENTITY_TYPE);

        return $this->getFilteredItemsBasedOnQuantityMap($items, $map);
    }

    /**
     * @param array<string, mixed>[] $inputItems
     * @return array<string, mixed>[]
     */
    private function filterAlreadyCreatedReturns(SalesOrder $source, array $inputItems): array
    {
        $ignoreStatuses = array_merge(
            $this->metadata->get('scopes.ReturnOrder.canceledStatusList') ?? [],
        );

        /** @var Traversable<int, ReturnOrder> $returnOrders */
        $returnOrders = $this->entityManager
            ->getRDBRepositoryByClass(ReturnOrder::class)
            ->where([
                'salesOrderId' => $source->getId(),
                'status!=' => $ignoreStatuses,
            ])
            ->find();

        if (iterator_count($returnOrders) === 0) {
            return $inputItems;
        }

        [$items, $map] = $this->getQuantityMapAndItems($inputItems, $returnOrders, ReturnOrderItem::ENTITY_TYPE);

        return $this->getFilteredItemsBasedOnQuantityMap($items, $map);
    }

    /**
     * @param array<string, mixed>[] $inputItems
     * @param Traversable<int, OrderEntity> $orders
     * @param class-string<QuoteItem> $itemClassName
     * @return array{QuoteItem[], array<string, float>}
     */
    private function getQuantityMapAndItems(array $inputItems, Traversable $orders, string $itemClassName): array
    {
        $items = [];

        /** @var array<string, float> $map */
        $map = [];

        foreach ($inputItems as $rawItem) {
            /** @var QuoteItem $item */
            $item = $this->entityManager->getNewEntity($itemClassName);
            $item->set($rawItem);

            if (!$item->getProduct()) {
                continue;
            }

            $productId = $item->getProduct()->getId();

            $map[$productId] ??= 0.0;
            $map[$productId] += $item->getQuantity();

            $items[] = $item;
        }

        foreach ($orders as $order) {
            $order->loadItemListField();

            foreach ($order->getItems() as $item) {
                if (!$item->getProductId()) {
                    continue;
                }

                $productId = $item->getProductId();

                if (!isset($map[$productId])) {
                    continue;
                }

                $map[$productId] -= $item->getQuantity();
            }
        }

        return [$items, $map];
    }

    /**
     * @param QuoteItem[] $items
     * @param array<string, float> $map
     * @return array<string, mixed>[]
     */
    private function getFilteredItemsBasedOnQuantityMap(array $items, array $map): array
    {
        $duplicateProductIds = [];

        /** @var QuoteItem[] $newItems */
        $newItems = [];

        foreach ($items as $item) {
            $productId = $item->getProduct()?->getId();

            if (!$productId) {
                continue;
            }

            foreach ($newItems as $newItem) {
                if ($newItem->getProduct()?->getId() === $productId) {
                    $duplicateProductIds[] = $productId;

                    continue 2;
                }
            }

            $newItems[] = $item;
        }

        $items = $newItems;

        $rawItems = [];

        foreach ($items as $item) {
            $productId = $item->getProduct()?->getId();

            if (!$productId) {
                continue;
            }

            $quantity = $map[$productId] ?? 0.0;

            if ($quantity === 0.0) {
                continue;
            }

            $rawItem = get_object_vars($item->getValueMap());
            $rawItem['quantity'] = $quantity;

            if (in_array($productId, $duplicateProductIds)) {
                unset($rawItem['inventoryNumberId']);
                unset($rawItem['inventoryNumberName']);
            }

            $rawItems[] = $rawItem;
        }

        return $rawItems;
    }
}
