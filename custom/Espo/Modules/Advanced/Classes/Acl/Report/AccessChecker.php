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
 * License ID: 02847865974db42443189e5f30908f60
 ************************************************************************************/

namespace Espo\Modules\Advanced\Classes\Acl\Report;

use Espo\Core\Acl\AccessEntityCREDChecker;
use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\Acl\Traits\DefaultAccessCheckerDependency;
use Espo\Core\AclManager;
use Espo\Entities\User;
use Espo\Modules\Advanced\Entities\Report;
use Espo\ORM\Entity;

/**
 * @implements AccessEntityCREDChecker<Report>
 */
class AccessChecker implements AccessEntityCREDChecker
{
    use DefaultAccessCheckerDependency;

    private AclManager $aclManager;

    public function __construct(
        DefaultAccessChecker $defaultAccessChecker,
        AclManager $aclManager
    ) {
        $this->defaultAccessChecker = $defaultAccessChecker;
        $this->aclManager = $aclManager;
    }

    /**
     * @param Report $entity
     */
    public function checkEntityRead(User $user, Entity $entity, ScopeData $data): bool
    {
        if (
            $entity->getTargetEntityType() &&
            !$this->aclManager->checkScope($user, $entity->getTargetEntityType())
        ) {
            return false;
        }

        return $this->defaultAccessChecker->checkEntityRead($user, $entity, $data);
    }
}
