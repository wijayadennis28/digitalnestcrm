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

namespace Espo\Modules\Sales\Controllers;

use Espo\Core\Acl\Table;
use Espo\Core\Api\Request;
use Espo\Core\Controllers\Record;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\Sales\Entities\DeliveryOrder as DeliveryOrderEntity;
use Espo\Modules\Sales\Entities\SalesOrder as SalesOrderEntity;
use Espo\Modules\Sales\Entities\TransferOrder as TransferOrderEntity;
use Espo\Modules\Sales\Tools\DeliveryOrder\Service as DeliveryOrderService;
use Espo\Modules\Sales\Tools\Inventory\AvailabilityDataProvider;
use Espo\Modules\Sales\Tools\Quote\ConvertService;
use Espo\Modules\Sales\Tools\Quote\EmailService;
use Espo\Modules\Sales\Tools\Sales\ConfigDataProvider;
use stdClass;

class DeliveryOrder extends Record
{
    private function getConfigDataProvider(): ConfigDataProvider
    {
        return $this->injectableFactory->create(ConfigDataProvider::class);
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     * @throws NotFound
     */
    public function actionGetAttributesFromSalesOrder(Request $request): array
    {
        $salesOrderId = $request->getQueryParam('salesOrderId');

        if (!$salesOrderId) {
            throw new BadRequest();
        }

        return $this->injectableFactory
            ->create(ConvertService::class)
            ->getAttributes(DeliveryOrderEntity::ENTITY_TYPE, SalesOrderEntity::ENTITY_TYPE, $salesOrderId);
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function postActionGetAttributesForEmail(Request $request): array
    {
        $data = $request->getParsedBody();

        if (empty($data->id) || empty($data->templateId)) {
            throw new BadRequest();
        }

        return $this->injectableFactory
            ->create(EmailService::class)
            ->getAttributes(DeliveryOrderEntity::ENTITY_TYPE, $data->id, $data->templateId);
    }

    /**
     * @throws BadRequest
     * @throws NotFound
     * @throws Forbidden
     * @throws Conflict
     */
    public function postActionCreateForSalesOrder(Request $request): stdClass
    {
        $salesOrderId = $request->getParsedBody()->salesOrderId ?? null;
        $dataList = $request->getParsedBody()->dataList ?? null;

        if (!is_string($salesOrderId) || !is_array($dataList)) {
            throw new BadRequest();
        }

        foreach ($dataList as $item) {
            if (!is_object($item)) {
                throw new BadRequest();
            }
        }

        $collection = $this->injectableFactory
            ->create(DeliveryOrderService::class)
            ->createFromSalesOrder($salesOrderId, $dataList);

        return (object) [
            'list' => $collection->getValueMapList(),
        ];
    }

    /**
     * @return stdClass[]
     * @throws BadRequest
     * @throws Forbidden
     */
    public function getActionGetInventoryDataForProducts(Request $request): array
    {
        if (!$this->getConfigDataProvider()->isInventoryTransactionsEnabled()) {
            throw new Forbidden();
        }

        if (
            !$this->acl->checkScope(DeliveryOrderEntity::ENTITY_TYPE, Table::ACTION_CREATE) &&
            !$this->acl->checkScope(TransferOrderEntity::ENTITY_TYPE, Table::ACTION_CREATE) &&
            !$this->acl->checkScope(SalesOrderEntity::ENTITY_TYPE, Table::ACTION_CREATE)
        ) {
            throw new Forbidden();
        }

        $ids = $request->getQueryParam('ids');
        $excludeId = $request->getQueryParam('excludeId');
        $excludeType = $request->getQueryParam('excludeType');

        if (!is_string($ids)) {
            throw new BadRequest();
        }

        $ids = explode(',', $ids);

        $items = $this->injectableFactory
            ->create(AvailabilityDataProvider::class)
            ->getForProducts($ids, $excludeType, $excludeId);

        $result = [];

        foreach ($items as $item) {
            $obj = (object) [
                'id' => $item->getId(),
                'name' => $item->getName(),
                'quantity' => $item->getQuantity(),
                'quantityOnHand' => $item->getQuantityOnHand(),
                'warehouses' => array_map(function ($warehouse) {
                    return (object) [
                        'id' => $warehouse->getId(),
                        'quantity' => $warehouse->getQuantity(),
                        'name' => $warehouse->getName(),
                        'quantityOnHand' => $warehouse->getQuantityOnHand(),
                    ];
                }, $item->getWarehouseDataList()),
            ];

            $result[] = $obj;
        }

        return $result;
    }
}
