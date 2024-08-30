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
use Throwable;

abstract class Activity extends Base
{
    /** @var string[] */
    protected array $pendingBoundaryTypeList = [
        'eventIntermediateConditionalBoundary',
        'eventIntermediateTimerBoundary',
        'eventIntermediateSignalBoundary',
        'eventIntermediateMessageBoundary',
    ];

    public function beforeProcess(): void
    {
        $this->prepareBoundary();
        $this->refreshFlowNode();
        $this->refreshTarget();
    }

    public function prepareBoundary(): void
    {
        $boundaryFlowNodeList = [];

        $attachedElementIdList = $this->getProcess()->getAttachedToFlowNodeElementIdList($this->getFlowNode());

        foreach ($attachedElementIdList as $id) {
            $item = $this->getProcess()->getElementDataById($id);

            if (!in_array($item->type,  $this->pendingBoundaryTypeList)) {
                continue;
            }

            $boundaryFlowNode = $this->getManager()->prepareFlow(
                $this->getTarget(),
                $this->getProcess(),
                $id,
                $this->getFlowNode()->get('id'),
                $this->getFlowNode()->getElementType()
            );

            if ($boundaryFlowNode) {
                $boundaryFlowNodeList[] = $boundaryFlowNode;
            }
        }

        foreach ($boundaryFlowNodeList as $boundaryFlowNode) {
            $this->getManager()->processPreparedFlowNode($this->getTarget(), $boundaryFlowNode, $this->getProcess());
        }
    }

    public function isProcessable(): bool
    {
        return $this->getFlowNode()->getStatus() === BpmnFlowNode::STATUS_CREATED;
    }

    protected function isInNormalFlow(): bool
    {
        return !$this->getFlowNode()->getElementDataItemValue('isForCompensation');
    }

    protected function setFailedWithError(?string $errorCode = null, ?string $errorMessage = null): void
    {
        $flowNode = $this->getFlowNode();
        $flowNode->set([
            'status' => BpmnFlowNode::STATUS_FAILED,
            'processedAt' => date('Y-m-d H:i:s'),
        ]);
        $this->getEntityManager()->saveEntity($flowNode);

        $this->getManager()->endProcessWithError($this->getProcess(), $errorCode, $errorMessage);
    }

    protected function setFailed(): void
    {
        $this->rejectPendingBoundaryFlowNodes();

        $errorCode = $this->getFlowNode()->getDataItemValue('errorCode');
        $errorMessage = $this->getFlowNode()->getDataItemValue('errorMessage');

        $boundaryErrorFlowNode = $this->getManager()
            ->prepareBoundaryErrorFlowNode($this->getFlowNode(), $this->getProcess(), $errorCode);

        if (!$boundaryErrorFlowNode) {
            $this->setFailedWithError($errorCode, $errorMessage);

            return;
        }

        $boundaryErrorFlowNode->setDataItemValue('code', $errorCode);
        $boundaryErrorFlowNode->setDataItemValue('message', $errorMessage);

        $this->getEntityManager()->saveEntity($boundaryErrorFlowNode);

        parent::setFailed();

        $this->getManager()->processPreparedFlowNode($this->getTarget(), $boundaryErrorFlowNode, $this->getProcess());
    }

    protected function setFailedWithException(Throwable $e): void
    {
        $errorCode = (string) $e->getCode();

        $this->rejectPendingBoundaryFlowNodes();

        $boundaryErrorFlowNode = $this->getManager()
            ->prepareBoundaryErrorFlowNode($this->getFlowNode(), $this->getProcess(), $errorCode);

        if (!$boundaryErrorFlowNode) {
            $this->setFailedWithError($errorCode, $e->getMessage());

            return;
        }

        $boundaryErrorFlowNode->setDataItemValue('code', $errorCode);
        $boundaryErrorFlowNode->setDataItemValue('message', $e->getMessage());

        $this->getEntityManager()->saveEntity($boundaryErrorFlowNode);

        parent::setFailed();

        $this->getManager()->processPreparedFlowNode($this->getTarget(), $boundaryErrorFlowNode, $this->getProcess());
    }

    protected function getPendingBoundaryFlowNodeList()
    {
        return $this->getEntityManager()
            ->getRepository(BpmnFlowNode::ENTITY_TYPE)
            ->where([
                'elementType' => $this->pendingBoundaryTypeList,
                'processId' => $this->getProcess()->get('id'),
                'status' => [
                    BpmnFlowNode::STATUS_CREATED,
                    BpmnFlowNode::STATUS_PENDING,
                ],
                'previousFlowNodeId' => $this->getFlowNode()->get('id'),
            ])
            ->find();
    }

    protected function rejectPendingBoundaryFlowNodes()
    {
        $boundaryNodeList = $this->getPendingBoundaryFlowNodeList();

        foreach ($boundaryNodeList as $boundaryNode) {
            $boundaryNode->set('status', BpmnFlowNode::STATUS_REJECTED);

            $this->getEntityManager()->saveEntity($boundaryNode);
        }
    }

    protected function setRejected(): void
    {
        $this->rejectPendingBoundaryFlowNodes();

        parent::setRejected();
    }

    protected function setProcessed(): void
    {
        $this->rejectPendingBoundaryFlowNodes();

        parent::setProcessed();
    }

    protected function setInterrupted(): void
    {
        $this->rejectPendingBoundaryFlowNodes();

        parent::setInterrupted();
    }
}
