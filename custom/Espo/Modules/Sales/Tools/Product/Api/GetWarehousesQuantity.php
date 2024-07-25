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

namespace Espo\Modules\Sales\Tools\Product\Api;

use Espo\Core\Acl;
use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\Sales\Entities\Product;
use Espo\Modules\Sales\Entities\Warehouse;
use Espo\Modules\Sales\Tools\Inventory\AvailabilityDataProvider;
use Espo\Modules\Sales\Tools\Sales\ConfigDataProvider;
use Espo\ORM\EntityManager;

/**
 * @noinspection PhpUnused
 */
class GetWarehousesQuantity implements Action
{
    public function __construct(
        private Acl $acl,
        private EntityManager $entityManager,
        private AvailabilityDataProvider $availabilityDataProvider,
        private ConfigDataProvider $configDataProvider
    ) {}

    public function process(Request $request): Response
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest();
        }

        /** @var ?Product $product */
        $product = $this->entityManager->getEntityById(Product::ENTITY_TYPE, $id);

        if (!$product) {
            throw new NotFound();
        }

        if (
            !$this->acl->checkEntityRead($product) ||
            !$this->acl->checkField(Product::ENTITY_TYPE, 'quantity') ||
            !$this->acl->checkScope(Warehouse::ENTITY_TYPE) ||
            !$this->configDataProvider->isWarehousesEnabled() ||
            !$this->configDataProvider->isInventoryTransactionsEnabled()
        ) {
            throw new Forbidden();
        }

        $dataList = $this->availabilityDataProvider->getWarehousesForProduct($product);

        $list = array_map(
            function ($data) {
                return (object) [
                    'id' => $data->getId(),
                    'name' => $data->getName(),
                    'quantity' => $data->getQuantity(),
                    'quantityOnHand' => $data->getQuantity() + $data->getQuantitySoftReserved(),
                    'quantityReserved' => $data->getQuantityReserved(),
                    'quantitySoftReserved' => $data->getQuantitySoftReserved(),
                ];
            },
            $dataList
        );

        return ResponseComposer::json(['list' => $list]);
    }
}
