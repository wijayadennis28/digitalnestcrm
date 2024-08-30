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

use Espo\Modules\Advanced\Core\Bpmn\Utils\ConditionManager;
use Espo\Modules\Advanced\Entities\BpmnFlowNode;
use Espo\Modules\Advanced\Entities\BpmnProcess;

class GatewayInclusive extends Gateway
{
    protected function processDivergent(): void
    {
        $conditionManager = $this->getConditionManager();

        $flowList = $this->getAttributeValue('flowList');

        if (!is_array($flowList)) {
            $flowList = [];
        }

        $defaultNextElementId = $this->getAttributeValue('defaultNextElementId');

        $nextElementIdList = [];

        foreach ($flowList as $flowData) {
            $conditionsAll = isset($flowData->conditionsAll) ? $flowData->conditionsAll : null;
            $conditionsAny = isset($flowData->conditionsAny) ? $flowData->conditionsAny : null;
            $conditionsFormula = isset($flowData->conditionsFormula) ? $flowData->conditionsFormula : null;

            $result = $conditionManager->check(
                $this->getTarget(),
                $conditionsAll,
                $conditionsAny,
                $conditionsFormula,
                $this->getVariablesForFormula()
            );

            if ($result) {
                $nextElementIdList[] = $flowData->elementId;
            }
        }

        $isDefaultFlow = false;

        if (!count($nextElementIdList) && $defaultNextElementId) {
            $isDefaultFlow = true;

            $nextElementIdList[] = $defaultNextElementId;
        }

        $flowNode = $this->getFlowNode();

        $nextDivergentFlowNodeId = $flowNode->get('id');

        if (count($nextElementIdList)) {
            $flowNode->set('status', BpmnFlowNode::STATUS_IN_PROCESS);

            $this->getEntityManager()->saveEntity($flowNode);

            $nextFlowNodeList = [];

            foreach ($nextElementIdList as $nextElementId) {
                $nextFlowNode = $this->prepareNextFlowNode($nextElementId, $nextDivergentFlowNodeId);

                if ($nextFlowNode) {
                    $nextFlowNodeList[] = $nextFlowNode;
                }
            }

            $this->setProcessed();

            foreach ($nextFlowNodeList as $nextFlowNode) {
                if ($this->getProcess()->getStatus() !== BpmnProcess::STATUS_STARTED) {
                    break;
                }

                $this->getManager()->processPreparedFlowNode($this->getTarget(), $nextFlowNode, $this->getProcess());
            }

            $this->getManager()->tryToEndProcess($this->getProcess());

            return;
        }

        $this->endProcessFlow();
    }

    protected function processConvergent(): void
    {
        $flowNode = $this->getFlowNode();

        $item = $flowNode->get('elementData');
        $previousElementIdList = $item->previousElementIdList;

        $nextDivergentFlowNodeId = null;
        $divergentFlowNode = null;

        $convergingFlowCount = 1;

        if ($flowNode->get('divergentFlowNodeId')) {
            $divergentFlowNode = $this->getEntityManager()
                ->getEntity('BpmnFlowNode', $flowNode->get('divergentFlowNodeId'));

            if ($divergentFlowNode) {
                $nextDivergentFlowNodeId = $divergentFlowNode->get('divergentFlowNodeId');

                $forkFlowNodeList = $this->getEntityManager()
                    ->getRepository('BpmnFlowNode')
                    ->where([
                        'processId' => $flowNode->get('processId'),
                        'previousFlowNodeId' => $divergentFlowNode->get('id')
                    ])
                    ->find();

                $convergingFlowCount = 0;

                foreach ($previousElementIdList as $previousElementId) {
                    $isActual = false;

                    foreach ($forkFlowNodeList as $forkFlowNode) {
                        if (
                            $this->checkElementsBelongSingleFlow(
                                $divergentFlowNode->get('elementId'),
                                $forkFlowNode->get('elementId'),
                                $previousElementId
                            )
                        ) {
                            $isActual = true;

                            break;
                        }
                    }

                    if ($isActual) {
                        $convergingFlowCount++;
                    }
                }
            }
        }

        $concurentFlowNodeList = $this->getEntityManager()
            ->getRepository(BpmnFlowNode::ENTITY_TYPE)
            ->where([
                'elementId' => $flowNode->get('elementId'),
                'processId' => $flowNode->get('processId'),
                'divergentFlowNodeId' => $flowNode->get('divergentFlowNodeId')
            ])
            ->find();

        $concurrentCount = count($concurentFlowNodeList);

        if ($concurrentCount < $convergingFlowCount) {
            $this->setRejected();

            return;
        }

        $isBalansingDivergent = true;

        if ($divergentFlowNode) {
            $divergentElementData = $divergentFlowNode->get('elementData');

            if (isset($divergentElementData->nextElementIdList)) {
                foreach ($divergentElementData->nextElementIdList as $forkId) {
                    if (
                        !$this->checkElementsBelongSingleFlow(
                            $divergentFlowNode->get('elementId'),
                            $forkId,
                            $flowNode->get('elementId')
                        )
                    ) {
                        $isBalansingDivergent = false;

                        break;
                    }
                }
            }
        }

        if ($isBalansingDivergent) {
            if ($divergentFlowNode) {
                $nextDivergentFlowNodeId = $divergentFlowNode->get('divergentFlowNodeId');
            }

            $this->processNextElement(null, $nextDivergentFlowNodeId);
        }
        else {
            $this->processNextElement(null, false);
        }
    }

    protected function getConditionManager(): ConditionManager
    {
        $conditionManager = new ConditionManager($this->getContainer());
        $conditionManager->setCreatedEntitiesData($this->getCreatedEntitiesData());

        return $conditionManager;
    }
}
