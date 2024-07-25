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

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Error\Body;
use Espo\Core\FieldValidation\Exceptions\ValidationError;
use Espo\Core\FieldValidation\FieldValidationManager;
use Espo\Core\Utils\FieldUtil;
use Espo\Core\Utils\Metadata;
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\Modules\Sales\Entities\DeliveryOrder;
use Espo\Modules\Sales\Entities\DeliveryOrderItem;
use Espo\Modules\Sales\Entities\InventoryAdjustment;
use Espo\Modules\Sales\Entities\InventoryAdjustmentItem;
use Espo\Modules\Sales\Entities\Product;
use Espo\Modules\Sales\Entities\ReceiptOrder;
use Espo\Modules\Sales\Entities\SalesOrder;
use Espo\Modules\Sales\Entities\TransferOrder;
use Espo\Modules\Sales\Entities\TransferOrderItem;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

class RecordValidator
{
    public function __construct(
        private EntityManager $entityManager,
        private FieldValidationManager $validationManager,
        private Metadata $metadata,
        private FieldUtil $fieldUtil
    ) {}

    /**
     * @throws Conflict
     * @throws BadRequest
     */
    public function process(Opportunity|OrderEntity $entity): void
    {
        if ($entity instanceof OrderEntity) {
            $this->validateLocked($entity);
        }

        $this->loadValidItemAttributesAndProductValidation($entity);

        $itemEntityType = $entity->getEntityType() . 'Item';
        $itemParentIdAttribute = lcfirst($entity->getEntityType()) . 'Id';

        /** @var stdClass[] $itemList */
        $itemList = $entity->get('itemList') ?? [];

        $previousItems = !$entity->isNew() ?
            $this->entityManager
                ->getRDBRepository($itemEntityType)
                ->where([$itemParentIdAttribute => $entity->getId()])
                ->find() :
            null;

        /** @var array<string, Entity> $map */
        $map = [];

        if ($previousItems) {
            foreach ($previousItems as $item) {
                $map[$item->getId()] = $item;
            }
        }

        foreach ($itemList as $item) {
            $id = $item->id ?? null;

            $itemEntity = $id && array_key_exists($id, $map) ?
                $map[$id] :
                $this->entityManager->getNewEntity($itemEntityType);

            $itemEntity->set($item);

            try {
                $this->validationManager->process($itemEntity, $item);
            }
            catch (ValidationError $e) {
                throw BadRequest::createWithBody(
                    $e->getLogMessage(),
                    Body::create()
                        ->withMessageTranslation('invalidItems', 'Quote')
                        ->encode()
                );
            }

            if (
                $entity instanceof ReceiptOrder ||
                $entity instanceof TransferOrder
            ) {
                if (!$this->checkItemQuantityReceived($itemEntity, $entity)) {
                    throw BadRequest::createWithBody(
                        'Required quantity received.',
                        Body::create()
                            ->withMessageTranslation('requiredQuantityReceived', 'Quote')
                            ->encode()
                    );
                }
            }

            if (
                $entity instanceof DeliveryOrder ||
                $entity instanceof TransferOrder ||
                $entity instanceof InventoryAdjustment
            ) {
                if (!$this->checkInventoryNumber($itemEntity, $entity)) {
                    throw BadRequest::createWithBody(
                        'Required inventory number.',
                        Body::create()
                            ->withMessageTranslation('requiredInventoryNumber', 'Quote')
                            ->encode()
                    );
                }
            }
        }
    }

    private function checkItemQuantityReceived(Entity $item, ReceiptOrder|TransferOrder $order): bool
    {
        if (!in_array($order->getStatus(), $this->getDoneStatusList($order->getEntityType()))) {
            return true;
        }

        return $item->get('quantityReceived') !== null;
    }

    private function checkInventoryNumber(
        DeliveryOrderItem|TransferOrderItem|InventoryAdjustmentItem $item,
        DeliveryOrder|TransferOrder|InventoryAdjustment $order
    ): bool {

        if (
            $order->getEntityType() !== InventoryAdjustment::ENTITY_TYPE &&
            in_array(
                $order->getStatus(),
                $this->getSoftReservedCanceledStatusList($order->getEntityType())
            )
        ) {
            return true;
        }

        if (!$item->getProduct()) {
            return true;
        }

        $product = $this->entityManager
            ->getRDBRepositoryByClass(Product::class)
            ->getById($item->getProduct()->getId());

        if (!$product) {
            return true;
        }

        if (!$product->getInventoryNumberType()) {
            return true;
        }

        return $item->getInventoryNumber() !== null;
    }

    /**
     * @return string[]
     */
    private function getDoneStatusList(string $entityType): array
    {
        return $this->metadata->get("scopes.$entityType.doneStatusList") ?? [];
    }

    private function getSoftReservedCanceledStatusList(string $entityType): array
    {
        return array_merge(
            $this->metadata->get("scopes.$entityType.softReserveStatusList") ?? [],
            $this->metadata->get("scopes.$entityType.canceledStatusList") ?? [],
        );
    }

    /**
     * @throws BadRequest
     */
    private function loadValidItemAttributesAndProductValidation(OrderEntity|Opportunity $entity): void
    {
        /** @var stdClass[] $itemList */
        $itemList = $entity->get('itemList') ?? [];

        if ($itemList === []) {
            return;
        }

        $productMap = $this->getProductMap($entity);

        foreach ($productMap as $product) {
            if ($product->getType() === Product::TYPE_TEMPLATE) {
                throw BadRequest::createWithBody(
                    'Product template cannot be selected in an order item.',
                    Body::create()
                        ->withMessageTranslation('productTemplateCannotBeSelected', 'Quote', [
                            'name' => $product->getName(),
                        ])
                        ->encode()
                );
            }
        }

        $this->loadValidItemProductAttributes($productMap, $entity);

        if (
            !$entity instanceof SalesOrder &&
            !$entity instanceof ReceiptOrder &&
            !$entity instanceof TransferOrder &&
            !$entity instanceof DeliveryOrder
        ) {
            return;
        }

        $this->loadValidItemInventoryAttributes($productMap, $entity);
    }

    /**
     * @return array<string, Product>
     */
    private function getProductMap(OrderEntity|Opportunity $entity): array
    {
        $productIds = $entity instanceof Opportunity ?
            $this->getProductIds($entity) :
            $entity->getProductIds();

        /** @var iterable<Product> $products */
        $products = $this->entityManager
            ->getRDBRepositoryByClass(Product::class)
            ->where(['id' => $productIds])
            ->find();

        $map = [];

        foreach ($products as $product) {
            $map[$product->getId()] = $product;
        }

        return $map;
    }

    private function getProductIds(Opportunity $entity): array
    {
        $ids = [];

        foreach ($entity->get('itemList') ?? [] as $item) {
            $productId = $item->productId ?? null;

            if (!$productId || in_array($productId, $ids)) {
                continue;
            }

            $ids[] = $productId;
        }

        return $ids;
    }

    /**
     * @param array<string, Product> $productMap
     */
    private function loadValidItemInventoryAttributes(
        array $productMap,
        DeliveryOrder|TransferOrder|SalesOrder|ReceiptOrder $entity
    ): void {

        /** @var ?stdClass[] $itemList */
        $itemList = $entity->get('itemList') ?? [];

        if ($itemList === []) {
            return;
        }

        $toSet = false;

        foreach ($itemList as $item) {
            $productId = $item->productId ?? null;

            if (!$productId) {
                continue;
            }

            $product = $productMap[$productId] ?? null;

            if (!$product) {
                continue;
            }

            $item->inventoryNumberType ??= null;
            $item->isInventory ??= null;

            if (
                $item->inventoryNumberType !== $product->getInventoryNumberType() ||
                $item->isInventory !== $product->isInventory()
            ) {
                $toSet = true;

                $item->inventoryNumberType = $product->getInventoryNumberType();
                $item->isInventory = $product->isInventory();
            }
        }

        if (!$toSet) {
            return;
        }

        $entity->set('itemList', $itemList);
    }

    private function loadValidItemProductAttributes(array $productMap, OrderEntity|Opportunity $entity): void
    {
        /** @var ?stdClass[] $itemList */
        $itemList = $entity->get('itemList') ?? [];

        if ($itemList === []) {
            return;
        }

        $toSet = false;

        foreach ($itemList as $item) {
            $productId = $item->productId ?? null;

            if (!$productId) {
                continue;
            }

            $product = $productMap[$productId] ?? null;

            if (!$product) {
                continue;
            }

            $item->allowFractionalQuantity ??= null;

            if (
                $item->allowFractionalQuantity !== $product->allowFractionalQuantity()
            ) {
                $toSet = true;

                $item->allowFractionalQuantity = $product->allowFractionalQuantity();
            }
        }

        if ($toSet) {
            $entity->set('itemList', $itemList);
        }
    }

    /**
     * @throws Conflict
     */
    private function validateLocked(OrderEntity $entity): void
    {
        if (!$entity->isLocked()) {
            return;
        }

        /** @var string[] $fieldList */
        $fieldList = $this->metadata->get("scopes.{$entity->getEntityType()}.lockableFieldList") ?? [];

        $changedField = null;

        foreach ($fieldList as $field) {
            foreach ($this->fieldUtil->getActualAttributeList($entity->getEntityType(), $field) as $attribute) {
                if ($entity->isAttributeChanged($attribute)) {
                    $changedField = $field;

                    break;
                }
            }
        }

        if ($changedField === null) {
            return;
        }

        throw Conflict::createWithBody(
            "Can't modify the locked record.",
            Body::create()
                ->withMessageTranslation('cantModifyLocked', 'Quote', ['field' => $changedField])
                ->encode()
        );
    }
}
