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

namespace Espo\Modules\Sales\Tools\ProductAttribute\Api;

use Espo\Core\Acl;
use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Core\Utils\Json;
use Espo\Modules\Sales\Entities\ProductAttribute;
use Espo\Modules\Sales\Tools\ProductAttribute\AttributeOption\MoveService;

/**
 * @noinspection PhpUnused
 */
class PostMoveOption implements Action
{
    public function __construct(
        private MoveService $moveService,
        private Acl $acl
    ) {}

    /**
     * @inheritDoc
     */
    public function process(Request $request): Response
    {
        if (!$this->acl->checkScope(ProductAttribute::ENTITY_TYPE, Acl\Table::ACTION_EDIT)) {
            throw new Forbidden();
        }

        $type = $request->getRouteParam('type');
        $id = $request->getRouteParam('id');
        $body = $request->getParsedBody();

        if (!in_array($type, [
            MoveService::TYPE_TOP,
            MoveService::TYPE_UP,
            MoveService::TYPE_DOWN,
            MoveService::TYPE_BOTTOM,
        ])) {
            throw new BadRequest();
        };

        if (!$id) {
            throw new BadRequest();
        }

        $searchParams = SearchParams::create();

        if ($body->where ?? null) {
            $rawWhere = Json::decode(Json::encode($body->where), true);

            $searchParams = $searchParams->withWhere(
                WhereItem::fromRawAndGroup($rawWhere)
            );
        }

        $this->moveService->move($id, $type, $searchParams);

        return ResponseComposer::json(true);
    }
}
