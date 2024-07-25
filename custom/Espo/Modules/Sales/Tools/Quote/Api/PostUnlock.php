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

namespace Espo\Modules\Sales\Tools\Quote\Api;

use Espo\Core\Acl;
use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\Sales\Tools\Quote\LockService;
use Espo\Modules\Sales\Tools\Sales\OrderEntity;
use Espo\ORM\EntityManager;

/**
 * @noinspection PhpUnused
 */
class PostUnlock implements Action
{
    public function __construct(
        private LockService $service,
        private EntityManager $entityManager,
        private Acl $acl
    ) {}

    public function process(Request $request): Response
    {
        $entityType = $request->getRouteParam('entityType');
        $id = $request->getRouteParam('id');

        if (!$entityType || !$id) {
            throw new BadRequest();
        }

        if (!$this->acl->checkScope($entityType)) {
            throw new Forbidden();
        }

        $entity = $this->entityManager->getEntityById($entityType, $id);

        if (!$entity) {
            throw new NotFound();
        }

        if (!$entity instanceof OrderEntity) {
            throw new Forbidden();
        }

        $this->service->unlock($entity);

        return ResponseComposer::json(true);
    }
}
