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

use Espo\ORM\Entity;
use Espo\Core\Exceptions\Error;
use stdClass;

class ApplyAssignmentRule extends BaseEntity
{
    protected function run(Entity $entity, stdClass $actionData): bool
    {
        $entityManager = $this->getEntityManager();

        $target = null;

        if (!empty($actionData->target)) {
            $target = $actionData->target;
        }

        if ($target === 'process') {
            $entity = $this->bpmnProcess;
        }
        else if (strpos($target, 'created:') === 0) {
            $entity = $this->getCreatedEntity($target);
        }

        if (!$entity) {
            return false;
        }

        if (!$entity->hasAttribute('assignedUserId') || !$entity->hasRelation('assignedUser')) {
            return false;
        }

        $reloadedEntity = $entityManager->getEntity($entity->getEntityType(), $entity->get('id'));

        if (empty($actionData->targetTeamId) || empty($actionData->assignmentRule)) {
            throw new Error('AssignmentRule: Not enough parameters.');
        }

        $targetTeamId = $actionData->targetTeamId;
        $assignmentRule = $actionData->assignmentRule;

        $targetUserPosition = null;

        if (!empty($actionData->targetUserPosition)) {
            $targetUserPosition = $actionData->targetUserPosition;
        }

        $listReportId = null;

        if (!empty($actionData->listReportId)) {
            $listReportId = $actionData->listReportId;
        }

        if (
            !in_array(
                $assignmentRule,
                $this->getMetadata()->get('entityDefs.Workflow.assignmentRuleList', [])
            )
        ) {
            throw new Error('AssignmentRule: ' . $assignmentRule . ' is not supported.');
        }

        // @todo Use factory and interface.

        $className = 'Espo\\Modules\\Advanced\\Business\\Workflow\\AssignmentRules\\' .
            str_replace('-', '', $assignmentRule);

        if (!class_exists($className)) {
            throw new Error('AssignmentRule: Class ' . $className . ' not found.');
        }

        $actionId = $this->getActionData()->id ?? null;

        if (!$actionId) {
            throw new Error("No action ID.");
        }

        $workflowId = $this->getWorkflowId();

        $flowchartId = null;

        if ($this->bpmnProcess) {
            $flowchartId = $this->bpmnProcess->get('flowchartId');

            $workflowId = null;
        }

        $rule = $this->injectableFactory->createWith($className, [
            'actionId' => $actionId,
            'workflowId' => $workflowId,
            'flowchartId' => $flowchartId,
            'entityType' => $entity->getEntityType(),
        ]);

        $attributes = $rule->getAssignmentAttributes($entity, $targetTeamId, $targetUserPosition, $listReportId);

        $entity->set($attributes);
        $reloadedEntity->set($attributes);

        $entityManager->saveEntity($reloadedEntity, [
            'skipWorkflow' => true,
            'modifiedById' => 'system',
            'skipCreatedBy' => true,
        ]);

        return true;
    }
}
