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

namespace Espo\Modules\Sales\Classes\AclPortal\PriceRule;

use Espo\Core\AclManager;
use Espo\Core\Portal\Acl\OwnershipAccountChecker;
use Espo\Core\Portal\AclManager as PortalAclManager;
use Espo\Entities\User;
use Espo\Modules\Sales\Entities\PriceBook;
use Espo\Modules\Sales\Entities\PriceRule;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

use RuntimeException;

/**
 * @implements OwnershipAccountChecker<PriceRule>
 */
class OwnershipChecker implements OwnershipAccountChecker
{
    private PortalAclManager $aclManager;
    private EntityManager $entityManager;

    public function __construct(
        AclManager $aclManager,
        EntityManager $entityManager
    ) {
        if (!$aclManager instanceof PortalAclManager) {
            throw new RuntimeException();
        }

        $this->aclManager = $aclManager;
        $this->entityManager = $entityManager;
    }

    public function checkAccount(User $user, Entity $entity): bool
    {
        $priceBook = $this->getPriceBook($entity);

        if (!$priceBook) {
            return false;
        }

        return $this->aclManager->checkOwnershipAccount($user, $priceBook);
    }

    private function getPriceBook(PriceRule $entity): ?PriceBook
    {
        /** @var ?PriceBook */
        return $this->entityManager->getEntityById(PriceBook::ENTITY_TYPE, $entity->getPriceBook()->getId());
    }
}
