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

use Espo\Modules\Advanced\Core\Bpmn\Utils\Helper;
use Espo\Modules\Advanced\Core\SignalManager;
use Espo\Modules\Advanced\Entities\BpmnFlowNode;

class EventStartSignalEventSubProcess extends Event
{
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
        $signal = $this->getSignal();

        if (!$signal) {
            $this->fail();

            $GLOBALS['log']->warning("BPM: No signal for sub-process start event");

            return;
        }

        $flowNode = $this->getFlowNode();
        $flowNode->set([
            'status' => BpmnFlowNode::STATUS_STANDBY,
        ]);
        $this->getEntityManager()->saveEntity($flowNode);

        $this->getSignalManager()->subscribe($signal, $flowNode->get('id'));
    }

    public function proceedPending(): void
    {
        $subProcessIsInterrupting = $this->getFlowNode()->getDataItemValue('subProcessIsInterrupting');

        if (!$subProcessIsInterrupting) {
            $this->createCopy();
        }

        if ($subProcessIsInterrupting) {
            $this->getManager()->interruptProcessByEventSubProcess($this->getProcess(), $this->getFlowNode());
        }

        $this->processNextElement();
    }

    protected function createCopy(): void
    {
        $data = $this->getFlowNode()->get('data') ?? (object) [];
        $data = clone $data;

        $flowNode = $this->getEntityManager()->getEntity(BpmnFlowNode::ENTITY_TYPE);

        $flowNode->set([
            'status' => BpmnFlowNode::STATUS_STANDBY,
            'elementType' => $this->getFlowNode()->getElementType(),
            'elementData' => $this->getFlowNode()->get('elementData'),
            'data' => $data,
            'flowchartId' => $this->getProcess()->getFlowchartId(),
            'processId' => $this->getProcess()->get('id'),
            'targetType' => $this->getFlowNode()->getTargetType(),
            'targetId' => $this->getFlowNode()->getTargetId(),
        ]);

        $this->getEntityManager()->saveEntity($flowNode);

        $this->getSignalManager()->subscribe($this->getSignal(), $flowNode->get('id'));
    }

    protected function getSignal() : ?string
    {
        $subProcessStartData = $this->getFlowNode()->getDataItemValue('subProcessStartData') ?? (object) [];

        $name = $subProcessStartData->signal ?? null;

        if (!$name) {
            return $name;
        }

        return Helper::applyPlaceholders($name, $this->getTarget(), $this->getVariables());
    }

    protected function getSignalManager(): SignalManager
    {
        /** @var SignalManager */
        return $this->getContainer()->get('signalManager');
    }
}
