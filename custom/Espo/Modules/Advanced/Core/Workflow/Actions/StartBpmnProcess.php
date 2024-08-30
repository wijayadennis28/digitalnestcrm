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

namespace Espo\Modules\Advanced\Core\Workflow\Actions;

use Espo\Core\Exceptions\Error;
use Espo\Modules\Advanced\Entities\BpmnFlowchart;
use Espo\ORM\Entity;
use Espo\Modules\Advanced\Core\Bpmn\BpmnManager;
use stdClass;

/**
 * @noinspection PhpUnused
 */
class StartBpmnProcess extends Base
{
    protected function run(Entity $entity, stdClass $actionData): bool
    {
        $target = $actionData->target ?? null;
        $flowchartId = $actionData->flowchartId ?? null;
        $elementId = $actionData->elementId;

        if (!$flowchartId || !$elementId) {
            throw new Error('Workflow StartBpmnProcess: Not flowchart data.');
        }

        $targetEntity = $this->getFirstTargetFromTargetItem($entity, $target);

        if (!$targetEntity) {
            $GLOBALS['log']->notice('Workflow StartBpmnProcess: No target.');

            return false;
        }

        $flowchart = $this->getFlowchart($flowchartId, $targetEntity);

        $bpmnManager = new BpmnManager($this->getContainer());

        $bpmnManager->startProcess(
            $targetEntity,
            $flowchart,
            $elementId,
            null,
            $this->getWorkflowId(),
            $this->getSignalParams()
        );

        return true;
    }

    private function getFlowchart(string $flowchartId, Entity $targetEntity): ?BpmnFlowchart
    {
        /** @var ?BpmnFlowchart $flowchart */
        $flowchart = $this->getEntityManager()->getEntityById(BpmnFlowchart::ENTITY_TYPE, $flowchartId);

        if (!$flowchart) {
            throw new Error("StartBpmnProcess: Could not find flowchart $flowchartId.");
        }

        if ($flowchart->getTargetType() !== $targetEntity->getEntityType()) {
            throw new Error("Workflow StartBpmnProcess: Target entity type doesn't match flowchart target type.");
        }

        return $flowchart;
    }

    private function getSignalParams(): ?stdClass
    {
        $signalParams = $this->getVariables()->__signalParams ?? null;

        if (!$signalParams instanceof stdClass) {
            $signalParams = null;
        }

        if ($signalParams) {
            $signalParams = clone $signalParams;
        }

        return $signalParams;
    }
}
