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

use Espo\Modules\Advanced\Entities\BpmnFlowNode;
use Espo\Modules\Advanced\Entities\BpmnProcess;

class GatewayParallel extends Gateway
{
    protected function processDivergent(): void
    {
        $flowNode = $this->getFlowNode();

        $item = $flowNode->get('elementData');

        $nextElementIdList = $item->nextElementIdList ?? [];

        if (count($nextElementIdList)) {
            $flowNode->set('status', BpmnFlowNode::STATUS_IN_PROCESS);

            $this->getEntityManager()->saveEntity($flowNode);

            $nextFlowNodeList = [];

            foreach ($nextElementIdList as $nextElementId) {
                $nextFlowNode = $this->prepareNextFlowNode($nextElementId, $flowNode->get('id'));

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
        $convergingFlowCount = count($previousElementIdList);

        $nextDivergentFlowNodeId = null;
        $divergentFlowNode = null;

        $divergedFlowCount = 1;

        if ($flowNode->get('divergentFlowNodeId')) {
            $divergentFlowNode = $this->getEntityManager()
                ->getEntity('BpmnFlowNode', $flowNode->get('divergentFlowNodeId'));

            if ($divergentFlowNode) {
                $divergentElementData = $divergentFlowNode->get('elementData');

                $divergedFlowCount = count($divergentElementData->nextElementIdList ?? []);
            }
        }

        $concurentFlowNodeList = $this->getEntityManager()
            ->getRepository(BpmnFlowNode::ENTITY_TYPE)
            ->where([
                'elementId' => $flowNode->getElementId(),
                'processId' => $flowNode->getProcessId(),
                'divergentFlowNodeId' => $flowNode->get('divergentFlowNodeId'),
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
}
