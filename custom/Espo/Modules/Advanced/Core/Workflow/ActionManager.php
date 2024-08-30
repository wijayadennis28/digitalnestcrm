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

namespace Espo\Modules\Advanced\Core\Workflow;

use Espo\Core\Exceptions\Error;

use Exception;
use stdClass;

class ActionManager extends BaseManager
{
    protected string $dirName = 'Actions';
    protected array $requiredOptions = ['type'];

    /**
     * @param stdClass[] $actions Actions.
     * @param array<string, mixed> $variables Formula variables to pass.
     * @throws Error
     */
    public function runActions(array $actions, array $variables = []): void
    {
        if (!isset($actions)) {
            return;
        }

        $this->log->debug("Start workflow rule [{$this->getWorkflowId()}].");

        $actualVariables = (object) [];

        foreach ($variables as $key => $value) {
            $actualVariables->$key = $value;
        }

        // Should be initialized before the loop.
        $processId = $this->getProcessId();

        foreach ($actions as $action) {
            $this->runAction($action, $processId, $actualVariables);
        }

        $this->log->debug("End workflow rule [{$this->getWorkflowId()}].");
    }

    /**
     * @throws Error
     */
    private function runAction(stdClass $actionData, ?string $processId, stdClass $variables): void
    {
        $entity = $this->getEntity($processId);

        $entityType = $entity->getEntityType();

        if (!$this->validate($actionData)) {
            $workflowId = $this->getWorkflowId($processId);

            $this->log->warning("Workflow [$workflowId]: invalid action data, [$entityType].");

            return;
        }

        $actionImpl = $this->getImpl($actionData->type, $processId);

        if (!isset($actionImpl)) {
            return;
        }

        try {
            $actionImpl->process($entity, $actionData, null, $variables);

            $this->copyVariables($actionImpl->getVariablesBack(), $variables);
        }
        catch (Exception $e) {
            $workflowId = $this->getWorkflowId($processId);
            $type = $actionData->type;
            $cid = $actionData->cid;

            $this->log->error("Workflow [$workflowId]: Action failed, [$type] [$cid], {$e->getMessage()}.");
        }
    }

    private function copyVariables(object $source, object $destination)
    {
        foreach (get_object_vars($destination) as $k => $v) {
            unset($destination->$k);
        }

        foreach (get_object_vars($source) as $k => $v) {
            $destination->$k = $v;
        }
    }
}
