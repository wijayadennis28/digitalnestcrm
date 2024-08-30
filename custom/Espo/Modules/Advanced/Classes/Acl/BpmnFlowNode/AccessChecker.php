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

namespace Espo\Modules\Advanced\Classes\Acl\BpmnFlowNode;

use Espo\Core\Acl\AccessChecker as AccessCheckerInterface;
use Espo\Core\Acl\AccessReadChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\AclManager;
use Espo\Entities\User;
use Espo\Modules\Advanced\Entities\BpmnProcess;

class AccessChecker implements AccessCheckerInterface, AccessReadChecker
{
    private AclManager $aclManager;

    public function __construct(AclManager $aclManager)
    {
        $this->aclManager = $aclManager;
    }

    public function check(User $user, ScopeData $data): bool
    {
        return $this->aclManager->checkScope($user, BpmnProcess::ENTITY_TYPE);
    }

    public function checkRead(User $user, ScopeData $data): bool
    {
        return false;
    }
}
