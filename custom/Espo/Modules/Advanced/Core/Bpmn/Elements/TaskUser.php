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

namespace Espo\Modules\Advanced\Core\Bpmn\Elements;

use Espo\Core\Exceptions\Error;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Language;
use Espo\Modules\Advanced\Core\Bpmn\Utils\Helper;
use Espo\Modules\Advanced\Entities\BpmnFlowNode;
use Espo\Modules\Advanced\Entities\BpmnUserTask;
use Espo\ORM\Entity;

class TaskUser extends Activity
{
    public function process(): void
    {
        $flowNode = $this->getFlowNode();

        $flowNode->set([
            'status' => BpmnFlowNode::STATUS_IN_PROCESS,
        ]);

        $this->saveFlowNode();

        $targetString = $this->getAttributeValue('target');
        $target = $this->getSpecificTarget($targetString);

        if (!$target) {
            $GLOBALS['log']->info("BPM TaskUser: Could not get target.");

            $this->fail();

            return;
        }

        $userTask = $this->getEntityManager()->getEntity('BpmnUserTask');

        $userTask->set([
            'flowNodeId' => $this->getFlowNode()->get('id'),
            'actionType' => $this->getAttributeValue('actionType'),
            'processId' => $this->getProcess()->get('id'),
            'targetId' => $target->get('id'),
            'targetType' => $target->getEntityType(),
            'description' => $this->getAttributeValue('description'),
            'instructions' => $this->getInstructionsText(),
        ]);

        $name = $this->getTaskName();

        if (empty($name)) {
            $name = $this->getLanguage()
                ->translateOption($this->getAttributeValue('actionType'), 'actionType', 'BpmnUserTask');
        }

        $teamIdList = $this->getProcess()->getLinkMultipleIdList('teams');

        $assignmentType = $this->getAttributeValue('assignmentType');
        $targetTeamId = $this->getAttributeValue('targetTeamId');

        $targetUserPosition = $this->getAttributeValue('targetUserPosition');
        if (!$targetUserPosition) {
            $targetUserPosition = null;
        }

        if ($targetTeamId && !in_array($targetTeamId, $teamIdList)) {
            $teamIdList[] = $targetTeamId;
        }

        $assignmentAttributes = null;

        if (strpos($assignmentType, 'rule:') === 0) {
            $assignmentRule = substr($assignmentType, 5);
            $ruleImpl = $this->getAssignmentRuleImplementation($assignmentRule);

            $whereClause = null;

            if ($assignmentRule === 'Least-Busy') {
                $whereClause = ['isResolved' => false];
            }

            $assignmentAttributes = $ruleImpl->getAssignmentAttributes(
                $userTask,
                $targetTeamId,
                $targetUserPosition,
                null,
                $whereClause
            );

        } else if (strpos($assignmentType, 'link:') === 0) {
            $link = substr($assignmentType, 5);
            $e = $this->getTarget();

            if (strpos($link, '.') !== false) {
                [$firstLink, $link] = explode('.', $link);

                $target = $this->getTarget();

                $e = method_exists($this->getEntityManager(), 'getRDBRepository') ?
                    $this->getEntityManager()
                        ->getRDBRepository($target->getEntityType())
                        ->getRelation($target, $firstLink)
                        ->findOne() :
                    $target->get($firstLink);
            }

            if ($e instanceof Entity) {
                $field = $link . 'Id';
                $userId = $e->get($field);

                if ($userId) {
                    $assignmentAttributes['assignedUserId'] = $userId;
                }
            }
        }
        else if ($assignmentType === 'processAssignedUser') {
            $userId = $this->getProcess()->get('assignedUserId');

            if ($userId) {
                $assignmentAttributes['assignedUserId'] = $userId;
            }
        }
        else if ($assignmentType === 'specifiedUser') {
            $userId = $this->getAttributeValue('targetUserId');

            if ($userId) {
                $assignmentAttributes['assignedUserId'] = $userId;
            }
        }

        if ($assignmentAttributes) {
            $userTask->set($assignmentAttributes);
        }

        $userTask->set([
            'name' => $name,
            'teamsIds' => $teamIdList
        ]);

        $this->getEntityManager()->saveEntity($userTask, ['createdById' => 'system']);

        $flowNode->setDataItemValue('userTaskId', $userTask->get('id'));

        $this->getEntityManager()->saveEntity($flowNode);

        $createdEntitiesData = $this->getCreatedEntitiesData();

        $alias = $this->getFlowNode()->get('elementId');

        $createdEntitiesData->$alias = (object) [
            'entityId' => $userTask->get('id'),
            'entityType' => $userTask->getEntityType(),
        ];

        $this->getProcess()->set('createdEntitiesData', $createdEntitiesData);

        $this->getEntityManager()->saveEntity($this->getProcess(), ['silent' => true]);
    }

    private function getAssignmentRuleImplementation(string $assignmentRule)
    {
        $className = 'Espo\\Modules\\Advanced\\Business\\Workflow\\AssignmentRules\\' .
            str_replace('-', '', $assignmentRule);

        if (!class_exists($className)) {
            throw new Error('Process TaskUser, Class ' . $className . ' not found.');
        }

        /** @var InjectableFactory $injectableFactory */
        $injectableFactory = $this->getContainer()->get('injectableFactory');

        return $injectableFactory->createWith($className, [
            'entityType' => BpmnUserTask::ENTITY_TYPE,
            'actionId' => $this->getElementId(),
            'flowchartId' => $this->getFlowNode()->get('flowchartId'),
        ]);
    }

    public function complete(): void
    {
        if (!$this->isInNormalFlow()) {
            $this->setProcessed();

            return;
        }

        $this->processNextElement();
    }

    protected function getLanguage(): Language
    {
        /** @var Language */
        return $this->getContainer()->get('defaultLanguage');
    }

    protected function setInterrupted(): void
    {
        $this->cancelUserTask();

        parent::setInterrupted();
    }

    public function cleanupInterrupted(): void
    {
        parent::cleanupInterrupted();

        $this->cancelUserTask();
    }

    private function cancelUserTask()
    {
        $userTaskId = $this->getFlowNode()->getDataItemValue('userTaskId');

        if ($userTaskId) {
            $userTask = $this->getEntityManager()->getEntity('BpmnUserTask', $userTaskId);

            if ($userTask && !$userTask->get('isResolved')) {
                $userTask->set([
                    'isCanceled' => true,
                ]);

                $this->getEntityManager()->saveEntity($userTask);
            }
        }
    }

    private function getTaskName(): ?string
    {
        $name = $this->getAttributeValue('name');

        if (!$name) {
            return $name;
        }

        return Helper::applyPlaceholders($name, $this->getTarget(), $this->getVariables());
    }

    private function getInstructionsText(): ?string
    {
        $text = $this->getAttributeValue('instructions');

        if (!$text) {
            return $text;
        }

        return Helper::applyPlaceholders($text, $this->getTarget(), $this->getVariables());
    }
}
