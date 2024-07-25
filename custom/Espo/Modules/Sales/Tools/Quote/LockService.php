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
use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\Sales\Tools\Sales\OrderEntity;
use Espo\ORM\EntityManager;

class LockService
{
    public function __construct(
        private Acl $acl,
        private EntityManager $entityManager
    ) {}

    /**
     * @throws Forbidden
     */
    public function lock(OrderEntity $order): void
    {
        $this->checkAccess($order);

        if ($order->isLocked()) {
            throw new Forbidden("Cannot lock an already locked record.");
        }

        if (!$order->isNotActual()) {
            throw new Forbidden("Cannot lock an actual record.");
        }

        $order->set('isLocked', true);
        $this->entityManager->saveEntity($order);
    }

    /**
     * @throws Forbidden
     */
    public function unlock(OrderEntity $order): void
    {
        $this->checkAccess($order);

        if (!$order->isLocked()) {
            throw new Forbidden("Cannot unlock a not locked record.");
        }

        if ($order->get('isHardLocked')) {
            throw new Forbidden("Cannot unlock a hard-locked record.");
        }

        $order->set('isLocked', false);
        $this->entityManager->saveEntity($order);
    }

    /**
     * @throws Forbidden
     */
    private function checkAccess(OrderEntity $order): void
    {
        if (!$this->acl->checkEntityEdit($order)) {
            throw new Forbidden();
        }

        // Does not work as read-only fields are forbidden.
        /*if (
            in_array('isLocked',
                $this->acl->getScopeForbiddenFieldList($order->getEntityType(), Acl\Table::ACTION_EDIT))
        ) {
            throw new Forbidden();
        }*/
    }
}
