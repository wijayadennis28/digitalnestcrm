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

namespace Espo\Modules\Sales\Classes\Acl\QuoteItem;

use Espo\Core\Acl\DefaultOwnershipChecker;
use Espo\Core\Acl\OwnershipOwnChecker;
use Espo\Core\Acl\OwnershipTeamChecker;
use Espo\Core\AclManager;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class OwnershipChecker implements OwnershipOwnChecker, OwnershipTeamChecker
{
    public function __construct(
        private DefaultOwnershipChecker $defaultOwnershipChecker,
        private AclManager $aclManager,
        private EntityManager $entityManager
    ) {}

    public function checkOwn(User $user, Entity $entity): bool
    {
        $parentField = $this->getParentField($entity);

        if (!$entity->has($parentField . 'Id')) {
            return $this->defaultOwnershipChecker->checkOwn($user, $entity);
        }

        $parent = $this->entityManager
            ->getRDBRepository($entity->getEntityType())
            ->getRelation($entity, $parentField)
            ->findOne();

        if (!$parent) {
            return false;
        }

        return $this->aclManager->checkOwnershipOwn($user, $parent);
    }

    public function checkTeam(User $user, Entity $entity): bool
    {
        $parentField = $this->getParentField($entity);

        if (!$entity->has($parentField . 'Id')) {
            return $this->defaultOwnershipChecker->checkTeam($user, $entity);
        }

        $parent = $this->entityManager
            ->getRDBRepository($entity->getEntityType())
            ->getRelation($entity, $parentField)
            ->findOne();

        if (!$parent) {
            return false;
        }

        return $this->aclManager->checkOwnershipTeam($user, $parent);
    }

    private function getParentField(Entity $entity): string
    {
        return lcfirst(substr($entity->getEntityType(), 0, -4));
    }
}
