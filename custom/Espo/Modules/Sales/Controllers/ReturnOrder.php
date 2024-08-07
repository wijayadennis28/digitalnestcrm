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

use Espo\Core\Api\Request;
use Espo\Core\Controllers\Record;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\Sales\Entities\ReturnOrder as ReturnOrderEntity;
use Espo\Modules\Sales\Entities\SalesOrder as SalesOrderEntity;
use Espo\Modules\Sales\Tools\Quote\ConvertService;
use Espo\Modules\Sales\Tools\Quote\EmailService;

/** @noinspection PhpUnused */
class ReturnOrder extends Record
{
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
            ->getAttributes(ReturnOrderEntity::ENTITY_TYPE, $data->id, $data->templateId);
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
            ->getAttributes(ReturnOrderEntity::ENTITY_TYPE, SalesOrderEntity::ENTITY_TYPE, $salesOrderId);
    }
}
