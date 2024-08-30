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
use Espo\Modules\Advanced\Core\Bpmn\Utils\MessageSenders\EmailType;
use Espo\Modules\Advanced\Entities\BpmnFlowNode;
use Throwable;

class TaskSendMessage extends Activity
{
    public function process(): void
    {
        $this->getFlowNode()->set([
            'status' => BpmnFlowNode::STATUS_PENDING,
        ]);
        $this->saveFlowNode();
    }

    public function proceedPending(): void
    {
        $createdEntitiesData = $this->getCreatedEntitiesData();

        try {
            $this->getImplementation()->process(
                $this->getTarget(),
                $this->getFlowNode(),
                $this->getProcess(),
                $createdEntitiesData,
                $this->getVariables()
            );
        } catch (Throwable $e) {
            $GLOBALS['log']->error(
                'Process ' . $this->getProcess()->get('id') . ' element ' .
                $this->getFlowNode()->get('elementId').' send message error: ' . $e->getMessage());

            $this->setFailedWithException($e);

            return;
        }

        $this->getProcess()->set('createdEntitiesData', $createdEntitiesData);
        $this->getEntityManager()->saveEntity($this->getProcess());

        $this->processNextElement();
    }

    /**
     * @todo Use factory.
     * @return EmailType
     */
    private function getImplementation(): EmailType
    {
        $messageType = $this->getAttributeValue('messageType');

        if (!$messageType) {
            throw new Error('Process ' . $this->getProcess()->getId() . ', no message type.');
        }

        $messageType = str_replace('\\', '', $messageType);

        $className = 'Espo\\Modules\\Advanced\\Core\\Bpmn\\Utils\\MessageSenders\\' . $messageType . 'Type';

        if (!class_exists($className)) {
            throw new Error(
                'Process ' . $this->getProcess()->getId() . ' element ' .
                $this->getFlowNode()->get('elementId'). ' send message not found implementation class.');
        }

        return new $className($this->getContainer());
    }
}
