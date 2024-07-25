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

namespace Espo\Modules\Sales\Classes\FieldLoaders\Quote;

use Espo\Core\FieldProcessing\Loader;
use Espo\Core\FieldProcessing\Loader\Params;
use Espo\Core\Utils\Metadata;
use Espo\Modules\Sales\Entities\DeliveryOrder;
use Espo\Modules\Sales\Entities\Quote;
use Espo\Modules\Sales\Entities\SalesOrder;
use Espo\Modules\Sales\Entities\TransferOrder;
use Espo\Modules\Sales\Entities\Warehouse;
use Espo\Modules\Sales\Tools\Inventory\ProductQuantityLoader;
use Espo\Modules\Sales\Tools\Sales\ConfigDataProvider;
use Espo\Modules\Sales\Tools\Sales\OrderEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use RuntimeException;

/**
 * @implements Loader<OrderEntity>
 * @noinspection PhpUnused
 */
class InventoryDataLoader implements Loader
{
    public function __construct(
        private ProductQuantityLoader $loader,
        private Metadata $metadata,
        private ConfigDataProvider $configDataProvider,
        private EntityManager $entityManager
    ) {}

    public function process(Entity $entity, Params $params): void
    {
        if (!$this->configDataProvider->isInventoryTransactionsEnabled()) {
            return;
        }

        if ($this->isNotActual($entity)) {
            $entity->set('inventoryData', (object) []);

            return;
        }

        $productIds = $entity->getInventoryProductIds();

        if ($productIds === []) {
            $entity->set('inventoryData', (object) []);

            return;
        }

        $excludeSoftReserve =
            (
                $entity instanceof DeliveryOrder ||
                $entity instanceof TransferOrder
            ) &&
            in_array($entity->getStatus(), $this->getReserveStatusList($entity->getEntityType()));

        $quantityMap = $this->loader->load($productIds, $entity, $excludeSoftReserve);

        $onHandQuantityMap = [];

        if (
            !$excludeSoftReserve &&
            (
                $entity instanceof DeliveryOrder ||
                $entity instanceof TransferOrder
            ) ||
            $entity instanceof SalesOrder
        ) {
            $onHandQuantityMap = $this->loader->load($productIds, $entity, true);
        }

        $totalQuantityMap = [];

        if (
            $this->configDataProvider->isWarehousesEnabled() &&
            $entity instanceof DeliveryOrder &&
            $entity->getWarehouse() &&
            $this->isWarehouseAvailableForStock($entity->getWarehouse()->getId())
        ) {
            $totalQuantityMap = $this->loader->load($productIds, $entity, $excludeSoftReserve, true);
        }

        $inventoryQuantityMap = [];

        if (
            $entity instanceof DeliveryOrder ||
            $entity instanceof TransferOrder
        ) {
            $inventoryNumberIds = $entity->getInventoryNumberIds();

            if ($inventoryNumberIds !== []) {
                $inventoryQuantityMap = $this->loader->loadForNumbers($inventoryNumberIds, $entity);
            }
        }

        $data = (object) [];

        /** @var object{productId?: string, quantity?: float, id: string}[] $itemList */
        $itemList = $entity->get('itemList') ?? [];

        foreach ($itemList as $item) {
            $productId = $item->productId;
            $quantity = $item->quantity ?? 0.0;
            $id = $item->id;
            $inventoryNumberId = $item->inventoryNumberId ?? null;
            $isInventory = $item->isInventory ?? false;

            if (
                !$productId ||
                !$isInventory ||
                !array_key_exists($productId, $quantityMap)
            ) {
                continue;
            }

            $obj = (object) [
                'quantity' => $quantityMap[$productId],
            ];

            $quantityMap[$productId] -= $quantity;

            $onHandQuantity = $onHandQuantityMap[$productId] ?? null;
            $totalQuantity = $totalQuantityMap[$productId] ?? null;

            if ($totalQuantity !== null) {
                $totalQuantityMap[$productId] -= $quantity;

                $obj->totalQuantity = $totalQuantity;
                $obj->quantity = min($obj->quantity, $obj->totalQuantity);
            }

            if ($inventoryNumberId && array_key_exists($inventoryNumberId, $inventoryQuantityMap)) {
                $obj->inventoryNumberQuantity = $inventoryQuantityMap[$inventoryNumberId];
            }

            if ($onHandQuantity !== null) {
                $obj->onHandQuantity = $onHandQuantity;
            }

            $data->$id = $obj;
        }

        $entity->set('inventoryData', $data);
    }

    /**
     * @return string[]
     */
    private function getDoneStatusList(string $entityType): array
    {
        return $this->metadata->get("scopes.$entityType.doneStatusList") ?? [];
    }

    /**
     * @return string[]
     */
    private function getCanceledStatusList(string $entityType): array
    {
        return $this->metadata->get("scopes.$entityType.canceledStatusList") ?? [];
    }

    /**
     * @return string[]
     */
    private function getFailedStatusList(string $entityType): array
    {
        return $this->metadata->get("scopes.$entityType.failedStatusList") ?? [];
    }

    /**
     * @return string[]
     */
    private function getReserveStatusList(string $entityType): array
    {
        return $this->metadata->get("scopes.$entityType.reserveStatusList") ?? [];
    }

    /**
     * @return string[]
     */
    private function getSoftReserveStatusList(string $entityType): array
    {
        return $this->metadata->get("scopes.$entityType.softReserveStatusList") ?? [];
    }

    private function isNotActual(OrderEntity $entity): bool
    {
        $status = $entity->getStatus();
        $entityType = $entity->getEntityType();

        $isDone = in_array($status, $this->getDoneStatusList($entityType));
        $isFailed = in_array($status, $this->getFailedStatusList($entityType));
        $isCanceled = in_array($status, $this->getCanceledStatusList($entityType));

        if ($isCanceled) {
            return true;
        }

        if ($entityType === Quote::ENTITY_TYPE) {
            return $isDone;
        }

        if ($entity instanceof SalesOrder && $entity->isDeliveryCreated()) {
            return true;
        }

        if ($entityType === DeliveryOrder::ENTITY_TYPE || $entityType === TransferOrder::ENTITY_TYPE) {
            $isTransferred =
                !$isDone &&
                !$isFailed &&
                !in_array($status, $this->getReserveStatusList($entityType)) &&
                !in_array($status, $this->getSoftReserveStatusList($entityType));

            return $isDone || $isFailed || $isTransferred;
        }

        if ($entity instanceof SalesOrder) {
            if (!$this->configDataProvider->isDeliveryOrdersEnabled()) {
                return $isDone;
            }

            return $isDone && $entity->isDeliveryCreated();
        }

        return false;
    }

    private function isWarehouseAvailableForStock(string $warehouseId): bool
    {
        $warehouse = $this->entityManager
            ->getRDBRepositoryByClass(Warehouse::class)
            ->getById($warehouseId);

        if (!$warehouse) {
            throw new RuntimeException("No warehouse.");
        }

        return $warehouse->isAvailableForStock();
    }
}
