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

namespace Espo\Modules\Advanced\Hooks\Common;

use Espo\Modules\Advanced\Core\WorkflowManager;
use Espo\ORM\Entity;

class Workflow
{
    public static $order = 99;

    private WorkflowManager $workflowManager;

    public function __construct(WorkflowManager $workflowManager) {
        $this->workflowManager = $workflowManager;
    }

    public function afterSave(Entity $entity, array $options): void
    {
        if (!empty($options['skipWorkflow'])) {
            return;
        }

        if (!empty($options['silent'])) {
            return;
        }

        if ($entity->isNew()) {
            $this->workflowManager->process($entity, WorkflowManager::AFTER_RECORD_CREATED, $options);
        } else {
            $this->workflowManager->process($entity, WorkflowManager::AFTER_RECORD_UPDATED, $options);
        }

        $this->workflowManager->process($entity, WorkflowManager::AFTER_RECORD_SAVED, $options);
    }
}
