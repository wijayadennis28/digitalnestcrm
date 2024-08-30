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

use Espo\Core\Formula\Manager as FormulaManager;
use Espo\Core\Utils\Config;
use Espo\Core\WebSocket\Submission;
use Espo\Modules\Advanced\Core\Bpmn\BpmnManager;
use Espo\Modules\Advanced\Entities\BpmnProcess;
use Espo\Modules\Advanced\Entities\BpmnFlowNode;
use Espo\Core\Exceptions\Error;
use Espo\Core\Container;
use Espo\Core\Utils\Metadata;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;

use stdClass;

abstract class Base
{
    /** @var Container */
    protected $container;
    /** @var BpmnProcess */
    protected $process;
    /** @var BpmnFlowNode */
    protected $flowNode;
    /** @var Entity */
    protected $target;
    /** @var BpmnManager */
    protected $manager;

    public function __construct(
        Container $container,
        BpmnManager $manager,
        Entity $target,
        BpmnFlowNode $flowNode,
        BpmnProcess $process
    ) {
        $this->container = $container;
        $this->manager = $manager;
        $this->target = $target;
        $this->flowNode = $flowNode;
        $this->process = $process;
    }

    protected function getContainer(): Container
    {
        return $this->container;
    }

    protected function getEntityManager(): EntityManager
    {
        /** @var EntityManager */
        return $this->container->get('entityManager');
    }

    protected function getMetadata(): Metadata
    {
        /** @var Metadata */
        return $this->container->get('metadata');
    }

    protected function getFormulaManager(): FormulaManager
    {
        /** @var FormulaManager */
        return $this->getContainer()->get('formulaManager');
    }

    private function getWebSocketSubmission(): Submission
    {
        /** @var Submission */
        return $this->getContainer()->get('webSocketSubmission');
    }

    private function getConfig(): Config
    {
        /** @var Config */
        return $this->getContainer()->get('config');
    }

    protected function getProcess(): BpmnProcess
    {
        return $this->process;
    }

    protected function getFlowNode(): BpmnFlowNode
    {
        return $this->flowNode;
    }

    protected function getTarget(): Entity
    {
        return $this->target;
    }

    protected function getManager(): BpmnManager
    {
        return $this->manager;
    }

    protected function refresh(): void
    {
        $this->refreshFlowNode();
        $this->refreshProcess();
        $this->refreshTarget();
    }

    protected function refreshFlowNode(): void
    {
        $flowNode = $this->getEntityManager()->getEntity('BpmnFlowNode', $this->flowNode->get('id'));

        if ($flowNode) {
            $this->flowNode->set($flowNode->getValueMap());
            $this->flowNode->setAsFetched();
        }
    }

    protected function refreshProcess(): void
    {
        $process = $this->getEntityManager()->getEntity('BpmnProcess', $this->process->get('id'));

        if ($process) {
            $this->process->set($process->getValueMap());
            $this->process->setAsFetched();
        }
    }

    protected function saveProcess(): void
    {
        $this->getEntityManager()->saveEntity($this->getProcess(), ['silent' => true]);
    }

    protected function saveFlowNode(): void
    {
        $this->getEntityManager()->saveEntity($this->getFlowNode());

        $this->submitWebSocket();
    }

    private function submitWebSocket(): void
    {
        if (!$this->getConfig()->get('useWebSocket')) {
            return;
        }

        if (!$this->getProcess()->hasId()) {
            return;
        }

        $entityType = $this->getProcess()->getEntityType();
        $id = $this->getProcess()->getId();

        $topic = "recordUpdate.$entityType.$id";

        $this->getWebSocketSubmission()->submit($topic);
    }

    protected function refreshTarget(): void
    {
        $target = $this->getEntityManager()->getEntity($this->target->getEntityType(), $this->target->get('id'));

        if ($target) {
            $this->target->set($target->getValueMap());
            $this->target->setAsFetched();
        }
    }

    public function isProcessable(): bool
    {
        return true;
    }

    public function beforeProcess(): void
    {}

    /**
     * @throws Error
     */
    abstract public function process(): void;

    /**
     * @throws Error
     */
    public function proceedPending(): void
    {
        $flowNode = $this->getFlowNode();

        throw new Error("BPM Flow: Can't proceed element ". $flowNode->getElementType() . " " .
            $flowNode->get('elementId') . " in flowchart " . $flowNode->getFlowchartId() . ".");
    }

    protected function getElementId(): string
    {
        $flowNode = $this->getFlowNode();
        $elementId = $flowNode->getElementId();

        if (!$elementId) {
            throw new Error("BPM Flow: No id for element " . $flowNode->getElementType() .
                " in flowchart " . $flowNode->getFlowchartId() . ".");
        }

        return $elementId;
    }

    protected function isInNormalFlow(): bool
    {
        return true;
    }

    protected function hasNextElementId(): bool
    {
        $flowNode = $this->getFlowNode();

        $item = $flowNode->get('elementData');
        $nextElementIdList = $item->nextElementIdList;

        if (!count($nextElementIdList)) {
            return false;
        }

        return true;
    }

    protected function getNextElementId(): ?string
    {
        $flowNode = $this->getFlowNode();

        if (!$this->hasNextElementId()) {
            return null;
        }

        $item = $flowNode->get('elementData');
        $nextElementIdList = $item->nextElementIdList;

        return $nextElementIdList[0];
    }

    /**
     * @return mixed
     */
    public function getAttributeValue(string $name)
    {
        $item = $this->getFlowNode()->get('elementData');

        if (!property_exists($item, $name)) {
            return null;
        }

        return $item->$name;
    }

    protected function getVariables(): stdClass
    {
        return $this->getProcess()->getVariables() ?? (object) [];
    }

    /**
     * @todo Revise the need.
     */
    protected function getClonedVariables(): stdClass
    {
        return clone $this->getVariables();
    }

    protected function getVariablesForFormula(): stdClass
    {
        $variables = $this->getClonedVariables();

        $variables->__createdEntitiesData = $this->getCreatedEntitiesData();
        $variables->__processEntity = $this->getProcess();
        $variables->__targetEntity = $this->getTarget();

        return $variables;
    }

    protected function sanitizeVariables($variables): void
    {
        unset($variables->__createdEntitiesData);
        unset($variables->__processEntity);
        unset($variables->__targetEntity);
    }

    protected function setProcessed(): void
    {
        $this->getFlowNode()->set([
            'status' => BpmnFlowNode::STATUS_PROCESSED,
            'processedAt' => date('Y-m-d H:i:s')
        ]);
        $this->saveFlowNode();
    }

    protected function setInterrupted(): void
    {
        $this->getFlowNode()->set([
            'status' => BpmnFlowNode::STATUS_INTERRUPTED,
        ]);
        $this->saveFlowNode();

        $this->endProcessFlow();
    }

    protected function setFailed(): void
    {
        $this->getFlowNode()->set([
            'status' => BpmnFlowNode::STATUS_FAILED,
            'processedAt' => date('Y-m-d H:i:s'),
        ]);
        $this->saveFlowNode();

        $this->endProcessFlow();
    }

    protected function setRejected(): void
    {
        $this->getFlowNode()->set([
            'status' => BpmnFlowNode::STATUS_REJECTED,
        ]);
        $this->saveFlowNode();

        $this->endProcessFlow();
    }

    public function fail(): void
    {
        $this->setFailed();
    }

    public function interrupt(): void
    {
        $this->setInterrupted();
    }

    public function cleanupInterrupted(): void
    {}

    public function complete(): void
    {
        throw new Error("Can't complete " . $this->getFlowNode()->get('elementType') . ".");
    }

    /**
     * @param string|bool|null $divergentFlowNodeId
     */
    protected function prepareNextFlowNode(
        ?string $nextElementId = null,
        $divergentFlowNodeId = false
    ): ?BpmnFlowNode {

        $flowNode = $this->getFlowNode();

        if (!$nextElementId) {
            if (!$this->isInNormalFlow()) {
                return null;
            }

            if (!$this->hasNextElementId()) {
                $this->endProcessFlow();

                return null;
            }

            $nextElementId = $this->getNextElementId();
        }

        if ($divergentFlowNodeId === false) {
            $divergentFlowNodeId = $flowNode->get('divergentFlowNodeId');
        }

        return $this->getManager()->prepareFlow(
            $this->getTarget(),
            $this->getProcess(),
            $nextElementId,
            $flowNode->get('id'),
            $flowNode->getElementType(),
            $divergentFlowNodeId
        );
    }

    /**
     * @param string|bool|null $divergentFlowNodeId
     */
    protected function processNextElement(
        ?string $nextElementId = null,
        $divergentFlowNodeId = false,
        bool $dontSetProcessed = false
    ): ?BpmnFlowNode {

        $nextFlowNode = $this->prepareNextFlowNode($nextElementId, $divergentFlowNodeId);

        if (!$dontSetProcessed) {
            $this->setProcessed();
        }

        if ($nextFlowNode) {
            $this->getManager()->processPreparedFlowNode(
                $this->getTarget(),
                $nextFlowNode,
                $this->getProcess()
            );
        }

        return $nextFlowNode;
    }

    protected function processPreparedNextFlowNode(BpmnFlowNode $flowNode): void
    {
        $this->getManager()->processPreparedFlowNode($this->getTarget(), $flowNode, $this->getProcess());
    }

    protected function endProcessFlow(): void
    {
        $this->getManager()->endProcessFlow($this->getFlowNode(), $this->getProcess());
    }

    protected function getCreatedEntitiesData(): stdClass
    {
        $createdEntitiesData = $this->getProcess()->get('createdEntitiesData');

        if (!$createdEntitiesData) {
            $createdEntitiesData = (object) [];
        }

        return $createdEntitiesData;
    }

    protected function getCreatedEntity(string $target): ?Entity
    {
        $createdEntitiesData = $this->getCreatedEntitiesData();

        if (strpos($target, 'created:') === 0) {
            $alias = substr($target, 8);
        } else {
            $alias = $target;
        }

        if (!$createdEntitiesData) {
            return null;
        }

        if (!property_exists($createdEntitiesData, $alias)) {
            return null;
        }

        if (empty($createdEntitiesData->$alias->entityId) || empty($createdEntitiesData->$alias->entityType)) {
            return null;
        }

        $entityType = $createdEntitiesData->$alias->entityType;
        $entityId = $createdEntitiesData->$alias->entityId;

        return $this->getEntityManager()->getEntity($entityType, $entityId);
    }

    protected function getSpecificTarget(?string $target): ?Entity
    {
        $entity = $this->getTarget();

        if (!$target || $target == 'targetEntity') {
            return $entity;
        }

        if (strpos($target, 'created:') === 0) {
            return $this->getCreatedEntity($target);
        }

        if (strpos($target, 'record:') === 0) {
            $entityType = substr($target, 7);

            $targetIdExpression = $this->getAttributeValue('targetIdExpression');

            if (!$targetIdExpression) {
                return null;
            }

            if (substr($targetIdExpression, -1) === ';') {
                $targetIdExpression = substr($targetIdExpression, 0, -1);
            }

            $id = $this->getFormulaManager()->run(
                $targetIdExpression,
                $this->getTarget(),
                $this->getVariablesForFormula()
            );

            if (!$id) {
                return null;
            }

            if (!is_string($id)) {
                throw new Error("BPM: Target-ID evaluated not to string.");
            }

            return $this->getEntityManager()->getEntity($entityType, $id);
        }

        if (strpos($target, 'link:') === 0) {
            $link = substr($target, 5);

            $linkList = explode('.', $link);

            $pointerEntity = $entity;

            $notFound = false;

            foreach ($linkList as $link) {
                $type = $this->getMetadata()
                    ->get(['entityDefs', $pointerEntity->getEntityType(), 'links', $link, 'type']);

                if (empty($type)) {
                    $notFound = true;

                    break;
                }

                $pointerEntity = method_exists($this->getEntityManager(), 'getRDBRepository') ?
                    $this->getEntityManager()
                        ->getRDBRepository($pointerEntity->getEntityType())
                        ->getRelation($pointerEntity, $link)
                        ->findOne() :
                    $pointerEntity->get($link);

                if (!$pointerEntity instanceof Entity) {
                    $notFound = true;

                    break;
                }
            }

            if (!$notFound) {
                return $pointerEntity;
            }
        }

        return null;
    }
}
