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
use Espo\ORM\Entity;

class EventStartConditionalEventSubProcess extends EventIntermediateConditionalCatch
{
    protected $pendingStatus = BpmnFlowNode::STATUS_STANDBY;

    protected function getConditionsTarget(): ?Entity
    {
        return $this->getSpecificTarget($this->getFlowNode()->getDataItemValue('subProcessTarget'));
    }

    /**
     * @param string|bool|null $divergentFlowNodeId
     */
    protected function processNextElement(
        ?string $nextElementId = null,
        $divergentFlowNodeId = false,
        bool $dontSetProcessed = false
    ): ?BpmnFlowNode {

        return parent::processNextElement($this->getFlowNode()->getDataItemValue('subProcessElementId'));
    }

    public function process(): void
    {
        $target = $this->getConditionsTarget();

        if (!$target) {
            $this->fail();

            return;
        }

        $result = $this->getConditionManager()->check(
            $target,
            $this->getAttributeValue('conditionsAll'),
            $this->getAttributeValue('conditionsAny'),
            $this->getAttributeValue('conditionsFormula'),
            $this->getVariablesForFormula()
        );

        if ($result) {
            $subProcessIsInterrupting = $this->getFlowNode()->getDataItemValue('subProcessIsInterrupting');

            if (!$subProcessIsInterrupting) {
                $this->createOppositeNode();
            }

            if ($subProcessIsInterrupting) {
                $this->getManager()->interruptProcessByEventSubProcess($this->getProcess(), $this->getFlowNode());
            }

            $this->processNextElement();

            return;
        }

        $this->getFlowNode()->set(['status' => $this->pendingStatus]);
        $this->saveFlowNode();
    }

    public function proceedPending(): void
    {
        $result = $this->getConditionManager()->check(
            $this->getTarget(),
            $this->getAttributeValue('conditionsAll'),
            $this->getAttributeValue('conditionsAny'),
            $this->getAttributeValue('conditionsFormula'),
            $this->getVariablesForFormula()
        );

        if ($this->getFlowNode()->getDataItemValue('isOpposite')) {
            if (!$result) {
                $this->setProcessed();
                $this->createOppositeNode(true);
            }

            return;
        }

        if ($result) {
            $subProcessIsInterrupting = $this->getFlowNode()->getDataItemValue('subProcessIsInterrupting');

            if (!$subProcessIsInterrupting) {
                $this->createOppositeNode();
            }

            if ($subProcessIsInterrupting) {
                $this->getManager()->interruptProcessByEventSubProcess($this->getProcess(), $this->getFlowNode());
            }

            $this->processNextElement();
        }
    }

    protected function createOppositeNode($isNegative = false)
    {
        $data = $this->getFlowNode()->get('data') ?? (object) [];
        $data = clone $data;
        $data->isOpposite = !$isNegative;

        $flowNode = $this->getEntityManager()->getEntity(BpmnFlowNode::ENTITY_TYPE);

        $flowNode->set([
            'status' => BpmnFlowNode::STATUS_STANDBY,
            'elementType' => $this->getFlowNode()->get('elementType'),
            'elementData' => $this->getFlowNode()->get('elementData'),
            'data' => $data,
            'flowchartId' => $this->getProcess()->get('flowchartId'),
            'processId' => $this->getProcess()->get('id'),
            'targetType' => $this->getFlowNode()->get('targetType'),
            'targetId' => $this->getFlowNode()->get('targetId'),
        ]);

        $this->getEntityManager()->saveEntity($flowNode);
    }
}
