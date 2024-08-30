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

namespace Espo\Modules\Advanced\Core\Bpmn;

use Espo\Core\Exceptions\Error;
use Espo\Modules\Advanced\Core\Bpmn\Elements\Base;
use Espo\Modules\Advanced\Entities\BpmnFlowchart;
use Espo\Modules\Advanced\Entities\BpmnProcess;
use Espo\Modules\Advanced\Entities\BpmnFlowNode;
use Espo\ORM\Collection;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;
use Espo\Core\Container;
use Espo\Core\Utils\Config;

use DateTime;
use stdClass;
use Throwable;

class BpmnManager
{
    private Container $container;

    private array $conditionalElementTypeList = [
        'eventStartConditional',
        'eventStartConditionalEventSubProcess',
        'eventIntermediateConditionalBoundary',
        'eventIntermediateConditionalCatch',
    ];

    private const PROCEED_PENDING_MAX_SIZE = 20000;
    private const PENDING_DEFER_PERIOD = '6 hours';
    private const PENDING_DEFER_INTERVAL_PERIOD = '10 minutes';

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    private function getEntityManager(): EntityManager
    {
        /** @var EntityManager */
        return $this->container->get('entityManager');
    }

    private function getConfig(): Config
    {
        /** @var Config */
        return $this->container->get('config');
    }

    public function startCreatedProcess(BpmnProcess $process, ?BpmnFlowchart $flowchart = null): void
    {
        if ($process->getStatus() !== BpmnProcess::STATUS_CREATED) {
            throw new Error("BPM: Could not start process with status " . $process->getStatus() . ".");
        }

        if (!$flowchart) {
            $flowchartId = $process->getFlowchartId();

            if (!$flowchartId) {
                throw new Error("BPM: Could not start process w/o flowchartId specified.");
            }
        }

        $startElementId = $process->getStartElementId();

        $targetId = $process->getTargetId();
        $targetType = $process->getTargetType();

        if (!$targetId || !$targetType) {
            throw new Error("BPM: Could not start process w/o targetId or targetType.");
        }

        if (!$flowchart) {
            $flowchart = $this->getEntityManager()->getEntity(BpmnFlowchart::ENTITY_TYPE, $flowchartId);
        }

        $target = $this->getEntityManager()->getEntity($targetType, $targetId);

        if (!$flowchart) {
            throw new Error("BPM: Could not find flowchart.");
        }

        if (!$target) {
            throw new Error("BPM: Could not find flowchart.");
        }

        $this->startProcess($target, $flowchart, $startElementId, $process);
    }

    /**
     * @throws Error
     */
    public function startProcess(
        Entity $target,
        BpmnFlowchart $flowchart,
        ?string $startElementId = null,
        ?BpmnProcess $process = null,
        ?string $workflowId = null,
        ?stdClass $signalParams = null
    ): void {

        $flowchartId = $flowchart->hasId() ? $flowchart->getId() : null;

        $GLOBALS['log']->debug("BPM: startProcess, flowchart $flowchartId, target {$target->getId()}.");

        $elementsDataHash = $flowchart->getElementsDataHash();

        if ($startElementId) {
            $this->checkFlowchartItemPropriety($elementsDataHash, $startElementId);

            $startItem = $elementsDataHash->$startElementId;

            if (!in_array($startItem->type, [
                'eventStart',
                'eventStartConditional',
                'eventStartTimer',
                'eventStartError',
                'eventStartEscalation',
                'eventStartSignal',
                'eventStartCompensation',
            ])) {
                throw new Error("BPM: startProcess, Bad start event type.");
            }
        }

        $isSubProcess = false;

        if ($process && $process->isSubProcess()) {
            $isSubProcess = true;
        }

        if (!$isSubProcess && $flowchartId) {
            $whereClause = [
                'targetId' => $target->getId(),
                'targetType' => $flowchart->getTargetType(),
                'status' => [
                    BpmnProcess::STATUS_STARTED,
                    BpmnProcess::STATUS_PAUSED,
                ],
                'flowchartId' => $flowchartId,
            ];

            $existingProcess = $this->getEntityManager()
                ->getRDBRepository(BpmnProcess::ENTITY_TYPE)
                ->where($whereClause)
                ->findOne();

            if ($existingProcess) {
                throw new Error(
                    "Process for flowchart " . $flowchartId .
                    " can't be run because process is already running.");
            }
        }

        $variables = (object) [];
        $createdEntitiesData = (object) [];

        if ($process) {
            $variables = $process->getVariables() ?? (object) [];

            if ($process->get('createdEntitiesData')) {
                $createdEntitiesData = $process->get('createdEntitiesData');
            }
        }

        if ($signalParams) {
            $variables->__signalParams = $signalParams;
        }

        if (!$process) {
            $process = $this->getEntityManager()->getEntity('BpmnProcess');

            $process->set([
                'name' => $flowchart->getName(),
                'assignedUserId' => $flowchart->getAssignedUserId(),
                'teamsIds' => $flowchart->getTeamIdList(),
                'workflowId' => $workflowId,
            ]);
        }

        $process->set([
            'name' => $flowchart->getName(),
            'flowchartId' => $flowchartId,
            'targetId' => $target->getId(),
            'targetType' => $flowchart->getTargetType(),
            'flowchartData' => $flowchart->getData(),
            'flowchartElementsDataHash' => $elementsDataHash,
            'assignedUserId' => $flowchart->getAssignedUserId(),
            'teamsIds' => $flowchart->getTeamIdList(),
            'status' => BpmnProcess::STATUS_STARTED,
            'createdEntitiesData' => $createdEntitiesData,
            'startElementId' => $startElementId,
            'variables' => $variables,
        ]);

        $this->getEntityManager()->saveEntity($process, [
            'createdById' => 'system',
            'skipModifiedBy' => true,
            'skipStartProcessFlow' => true,
        ]);

        if ($startElementId) {
            $flowNode = $this->prepareFlow($target, $process, $startElementId);

            if ($flowNode) {
                $this->prepareEventSubProcesses($target, $process);
                $this->processPreparedFlowNode($target, $flowNode, $process);
            }

            return;
        }

        $startElementIdList = $this->getProcessElementWithoutIncomingFlowIdList($process);

        /** @var BpmnFlowNode[] $flowNodeList */
        $flowNodeList = [];

        foreach ($startElementIdList as $elementId) {
            $flowNode = $this->prepareFlow($target, $process, $elementId);

            if (!$flowNode) {
                continue;
            }

            $flowNodeList[] = $flowNode;
        }

        if (!count($flowNodeList)) {
            $this->endProcess($process);
        } else {
            $this->prepareEventSubProcesses($target, $process);
        }

        foreach ($flowNodeList as $flowNode) {
            $this->processPreparedFlowNode($target, $flowNode, $process);

            if (method_exists($this->getEntityManager(), 'refreshEntity')) {
                $this->getEntityManager()->refreshEntity($process);
            }
        }
    }

    public function prepareEventSubProcesses(Entity $target, BpmnProcess $process): void
    {
        $standByFlowNodeList = [];

        foreach ($this->getProcessElementEventSubProcessIdList($process) as $id) {
            $flowNode = $this->prepareStandbyFlow($target, $process, $id);

            if ($flowNode) {
                $standByFlowNodeList[] = $flowNode;
            }
        }

        foreach ($standByFlowNodeList as $flowNode) {
            $this->processPreparedFlowNode($target, $flowNode, $process);
        }
    }

    /**
     * @return string[]
     */
    private function getProcessElementEventSubProcessIdList(BpmnProcess $process): array
    {
        $resultElementIdList = [];

        $elementIdList = $process->getElementIdList();

        foreach ($elementIdList as $id) {
            $item = $process->getElementDataById($id);

            if (empty($item->type)) {
                continue;
            }

            if ($item->type === 'eventSubProcess') {
                $resultElementIdList[] = $id;
            }
        }

        return $resultElementIdList;
    }

    /**
     * @return string[]
     */
    private function getProcessElementWithoutIncomingFlowIdList(BpmnProcess $process): array
    {
        $resultElementIdList = [];

        $elementIdList = $process->getElementIdList();

        foreach ($elementIdList as $id) {
            $item = $process->getElementDataById($id);

            if (empty($item->type)) {
                continue;
            }

            if (
                $item->type !== 'eventStart' &&
                (
                    in_array($item->type, ['flow', 'eventIntermediateLinkCatch']) ||
                    strpos($item->type, 'eventStart') === 0
                )
            ) {
                continue;
            }

            if (substr($item->type, -15) === 'EventSubProcess') {
                continue;
            }

            if (substr($item->type, -8) === 'Boundary') {
                continue;
            }

            if ($item->type === 'eventSubProcess') {
                continue;
            }

            if (!empty($item->previousElementIdList)) {
                continue;
            }

            if (!empty($item->isForCompensation)) {
                continue;
            }

            $resultElementIdList[] = $id;
        }

        return $resultElementIdList;
    }

    public function prepareStandbyFlow(Entity $target, BpmnProcess $process, string $elementId): ?BpmnFlowNode
    {
        $GLOBALS['log']->debug("BPM: prepareStandbyFlow, process {$process->getId()}, element $elementId.");

        if ($process->getStatus() !== BpmnProcess::STATUS_STARTED) {
            $GLOBALS['log']->info(
                "BPM: Process status " . $process->getId() . " is not 'Started' but ".
                $process->get('status').", hence can't create standby flow."
            );

            return null;
        }

        $item = $process->getElementDataById($elementId);

        $eventStartData = $item->eventStartData ?? (object) [];
        $startEventType = $eventStartData->type ?? null;

        if (!$startEventType) {
            return null;
        }

        if (!$eventStartData->id) {
            return null;
        }

        if (
            in_array($startEventType, [
                'eventStartError',
                'eventStartEscalation',
                'eventStartCompensation',
            ])
        ) {
            return null;
        }

        $elementType = $startEventType . 'EventSubProcess';

        /** @var BpmnFlowNode */
        return $this->getEntityManager()->createEntity(BpmnFlowNode::ENTITY_TYPE, [
            'status' => BpmnFlowNode::STATUS_CREATED,
            'elementType' => $elementType,
            'elementData' => $eventStartData,
            'flowchartId' => $process->getFlowchartId(),
            'processId' => $process->getId(),
            'targetType' => $target->getEntityType(),
            'targetId' => $target->getId(),
            'data' => (object) [
                'subProcessElementId' => $elementId,
                'subProcessTarget' => $item->target ?? null,
                'subProcessIsInterrupting' => $eventStartData->isInterrupting ?? false,
                'subProcessTitle' => $eventStartData->title ?? null,
                'subProcessStartData' => $eventStartData,
            ],
        ]);
    }

    /**
     * @throws Error
     */
    private function checkFlowchartItemPropriety(stdClass $elementsDataHash, string $elementId): void
    {
        if (!$elementId) {
            throw new Error('No start event element.');
        }

        if (!is_object($elementsDataHash)) {
            throw new Error();
        }

        if (!isset($elementsDataHash->$elementId) || !is_object($elementsDataHash->$elementId)) {
            throw new Error('Not existing start event element id.');
        }

        $item = $elementsDataHash->$elementId;

        if (!isset($item->type)) {
            throw new Error('Bad start event element.');
        }
    }

    /**
     * @throws Error
     */
    public function prepareFlow(
        Entity $target,
        BpmnProcess $process,
        string $elementId,
        ?string $previousFlowNodeId = null,
        ?string $previousFlowNodeElementType = null,
        ?string $divergentFlowNodeId = null,
        bool $allowEndedProcess = false
    ): ?BpmnFlowNode {

        $GLOBALS['log']->debug("BPM: prepareFlow, process {$process->getId()}, element $elementId.");

        if (!$allowEndedProcess && $process->getStatus() !== BpmnProcess::STATUS_STARTED) {
            $GLOBALS['log']->info(
                "BPM: Process status ".$process->getId() ." is not 'Started' but ".
                $process->get('status') . ", hence can't be processed."
            );

            return null;
        }

        $elementsDataHash = $process->get('flowchartElementsDataHash');

        $this->checkFlowchartItemPropriety($elementsDataHash, $elementId);

        if (
            $target->getEntityType() !== $process->getTargetType() ||
            $target->getId() !== $process->getTargetId()
        ) {
            throw new Error("Not matching targets.");
        }

        $item = $elementsDataHash->$elementId;

        $elementType = $item->type;

        /** @var BpmnFlowNode $flowNode */
        $flowNode = $this->getEntityManager()->getEntity(BpmnFlowNode::ENTITY_TYPE);

        $flowNode->set([
            'status' => BpmnFlowNode::STATUS_CREATED,
            'elementId' => $elementId,
            'elementType' => $elementType,
            'elementData' => $item,
            'flowchartId' => $process->getFlowchartId(),
            'processId' => $process->getId(),
            'previousFlowNodeElementType' => $previousFlowNodeElementType,
            'previousFlowNodeId' => $previousFlowNodeId,
            'divergentFlowNodeId' => $divergentFlowNodeId,
            'targetType' => $target->getEntityType(),
            'targetId' => $target->getId(),
        ]);

        $this->getEntityManager()->saveEntity($flowNode);

        return $flowNode;
    }

    public function processPreparedFlowNode(Entity $target, BpmnFlowNode $flowNode, BpmnProcess $process): void
    {
        $impl = $this->getFlowNodeImplementation($target, $flowNode, $process);

        $impl->beforeProcess();

        if (!$impl->isProcessable()) {
            $GLOBALS['log']->info("BPM: Can't process not processable node ". $flowNode->getId() .".");

            return;
        }

        $impl->process();
    }

    public function processFlow(
        Entity $target,
        BpmnProcess $process,
        string $elementId,
        ?string $previousFlowNodeId = null,
        ?string $previousFlowNodeElementType = null,
        ?string $divergentFlowNodeId = null
    ): ?BpmnFlowNode {

        $flowNode = $this->prepareFlow(
            $target,
            $process,
            $elementId,
            $previousFlowNodeId,
            $previousFlowNodeElementType,
            $divergentFlowNodeId
        );

        if ($flowNode) {
            $this->processPreparedFlowNode($target, $flowNode, $process);
        }

        return $flowNode;
    }

    public function processPendingFlows(): void
    {
        $limit = $this->getConfig()->get('bpmnProceedPendingMaxSize', self::PROCEED_PENDING_MAX_SIZE);

        $GLOBALS['log']->debug("BPM: processPendingFlows");

        $flowNodeList = $this->getEntityManager()
            ->getRDBRepository(BpmnFlowNode::ENTITY_TYPE)
            ->sth()
            ->where([
                'OR' => [
                    [
                        'status' => BpmnFlowNode::STATUS_PENDING,
                        'elementType' => 'eventIntermediateTimerCatch',
                        'proceedAt<=' => date('Y-m-d H:i:s'),
                    ],
                    [
                        'status' => BpmnFlowNode::STATUS_PENDING,
                        'elementType' => 'eventIntermediateConditionalCatch',
                    ],
                    [
                        'status' => BpmnFlowNode::STATUS_PENDING,
                        'elementType' => 'eventIntermediateMessageCatch',
                    ],
                    [
                        'status' => BpmnFlowNode::STATUS_PENDING,
                        'elementType' => 'eventIntermediateConditionalBoundary',
                    ],
                    [
                        'status' => BpmnFlowNode::STATUS_PENDING,
                        'elementType' => 'eventIntermediateTimerBoundary',
                        'proceedAt<=' => date('Y-m-d H:i:s'),
                    ],
                    [
                        'status' => BpmnFlowNode::STATUS_PENDING,
                        'elementType' => 'eventIntermediateMessageBoundary',
                    ],
                    [
                        'status' => BpmnFlowNode::STATUS_PENDING,
                        'elementType' => 'taskSendMessage',
                    ],
                    [
                        'status' => BpmnFlowNode::STATUS_STANDBY,
                        'elementType' => 'eventStartTimerEventSubProcess',
                        'proceedAt<=' => date('Y-m-d H:i:s'),
                    ],
                    [
                        'status' => BpmnFlowNode::STATUS_STANDBY,
                        'elementType' => 'eventStartConditionalEventSubProcess',
                    ],
                    [
                        'status' => BpmnFlowNode::STATUS_PENDING,
                        'elementType' => 'eventIntermediateCompensationThrow',
                    ],
                    [
                        'status' => BpmnFlowNode::STATUS_PENDING,
                        'elementType' => 'EventEndCompensation',
                    ],
                ],
                'isLocked' => false,
            ])
            ->select([
                'id',
                'elementType',
                'isLocked',
                'deferredAt',
                'isDeferred',
                'createdAt',
            ])
            ->order('number', false)
            ->limit(0, $limit)
            ->find();

        foreach ($flowNodeList as $flowNode) {
            try {
                $toProcess = $this->checkPendingFlow($flowNode);
            } catch (Throwable $e) {
                $this->logException($e);

                // @todo Destroy item.

                continue;
            }

            if (!$toProcess) {
                continue;
            }

            try {
                $this->controlDeferPendingFlow($flowNode);
            } catch (Throwable $e) {
                $this->logException($e);

                // @todo Destroy item.

                continue;
            }

            try {
                $this->proceedPendingFlow($flowNode);
            } catch (Throwable $e) {
                $this->logException($e);

                // @todo Destroy item.
            }
        }

        $this->cleanupSignalListeners();
        $this->processTriggeredSignals();
    }

    /**
     * Preventing checking conditional nodes too often.
     *
     * @return bool False if flow checking should be skipped.
     */
    private function checkPendingFlow(BpmnFlowNode $flowNode): bool
    {
        $elementType = $flowNode->getElementType();

        if (!in_array($elementType, $this->conditionalElementTypeList)) {
            return true;
        }

        if (!$flowNode->get('deferredAt')) {
            return true;
        }

        if (!$flowNode->get('isDeferred')) {
            return true;
        }

        $period = $this->getConfig()->get('bpmnPendingDeferIntervalPeriod', self::PENDING_DEFER_INTERVAL_PERIOD);

        $diff = DateTime::createFromFormat('Y-m-d H:i:s', $flowNode->get('deferredAt'))
            ->diff(
                (new DateTime())->modify('-' . $period)
            );

        if (!$diff->invert) {
            return true;
        }

        return false;
    }

    private function controlDeferPendingFlow(BpmnFlowNode $flowNode): void
    {
        $elementType = $flowNode->getElementType();

        if (!in_array($elementType, $this->conditionalElementTypeList)) {
            return;
        }

        $from = $flowNode->get('deferredAt') ?? $flowNode->get('createdAt');

        if (!$from) {
            return;
        }

        $period = $flowNode->get('deferredAt') ?
            $this->getConfig()->get('bpmnPendingDeferIntervalPeriod', self::PENDING_DEFER_INTERVAL_PERIOD) :
            $this->getConfig()->get('bpmnPendingDeferPeriod', self::PENDING_DEFER_PERIOD);

        $diff = DateTime::createFromFormat('Y-m-d H:i:s', $from)
            ->diff(
                (new DateTime())->modify('-' . $period)
            );

        if (
            $diff->invert &&
            $flowNode->get('deferredAt') &&
            !$flowNode->get('isDeferred')
        ) {
            // If a node was set as not deferred, it should be checked only once.

            $flowNode->set([
                'isDeferred' => true,
            ]);

            $this->getEntityManager()->saveEntity($flowNode);

            return;
        }

        if ($diff->invert) {
            return;
        }

        $flowNode->set([
            'deferredAt' => (new DateTime())->format('Y-m-d H:i:s'),
            'isDeferred' => true,
        ]);

        $this->getEntityManager()->saveEntity($flowNode);
    }

    public function cleanupSignalListeners(): void
    {
        $limit = $this->getConfig()->get('bpmnProceedPendingMaxSize', self::PROCEED_PENDING_MAX_SIZE);

        $listenerList = $this->getEntityManager()
            ->getRDBRepository('BpmnSignalListener')
            ->select(['id', 'name', 'flowNodeId'])
            ->order('number')
            ->leftJoin(
                BpmnFlowNode::ENTITY_TYPE,
                'flowNode',
                ['flowNode.id:' => 'flowNodeId'],
            )
            ->where([
                'OR' => [
                    'flowNode.deleted' => true,
                    'flowNode.id' => null,
                    'flowNode.status!=' => [
                        BpmnFlowNode::STATUS_STANDBY,
                        BpmnFlowNode::STATUS_CREATED,
                        BpmnFlowNode::STATUS_PENDING,
                    ],
                ],
            ])
            ->limit(0, $limit)
            ->find();

        foreach ($listenerList as $item) {
            $GLOBALS['log']->debug("BPM: Delete not actual signal listener for flow node " . $item->get('flowNodeId'));

            $this->getEntityManager()
                ->getRDBRepository('BpmnSignalListener')
                ->deleteFromDb($item->getId());
        }
    }

    public function processTriggeredSignals(): void
    {
        $limit = $this->getConfig()->get('bpmnProceedPendingMaxSize', self::PROCEED_PENDING_MAX_SIZE);

        $GLOBALS['log']->debug("BPM: processTriggeredSignals");

        $listenerList = $this->getEntityManager()
            ->getRDBRepository('BpmnSignalListener')
            ->select(['id', 'name', 'flowNodeId'])
            ->order('number')
            ->where([
                'isTriggered' => true,
            ])
            ->limit(0, $limit)
            ->find();

        foreach ($listenerList as $item) {
            $this->getEntityManager()
                ->getRDBRepository('BpmnSignalListener')
                ->deleteFromDb($item->getId());

            $flowNodeId = $item->get('flowNodeId');

            if (!$flowNodeId) {
                continue;
            }

            /** @var ?BpmnFlowNode $flowNode */
            $flowNode = $this->getEntityManager()->getEntity(BpmnFlowNode::ENTITY_TYPE, $flowNodeId);

            if (!$flowNode) {
                $GLOBALS['log']->notice("BPM: Flow Node $flowNodeId not found.");

                continue;
            }

            try {
                $this->proceedPendingFlow($flowNode);
            } catch (Throwable $e) {
                $this->logException($e);
            }
        }
    }

    public function setFlowNodeFailed(BpmnFlowNode $flowNode): void
    {
        $flowNode->set([
            'status' => BpmnFlowNode::STATUS_FAILED,
            'processedAt' => date('Y-m-d H:i:s'),
        ]);

        $this->getEntityManager()->saveEntity($flowNode);
    }

    /**
     * @throws Error
     */
    private function checkFlowIsActual(?Entity $target, BpmnFlowNode $flowNode, ?BpmnProcess $process): void
    {
        if (!$process) {
            $this->setFlowNodeFailed($flowNode);

            throw new Error("Could not find process " . $flowNode->getProcessId() . ".");
        }

        if (!$target) {
            $this->setFlowNodeFailed($flowNode);
            $this->interruptProcess($process);

            throw new Error("Could not find target for process " . $process->getId() . ".");
        }

        if ($process->getStatus() === BpmnProcess::STATUS_PAUSED) {
            $this->unlockFlowNode($flowNode);

            throw new Error("Attempted to continue flow of paused process " . $process->getId() . ".");
        }

        if ($flowNode->getElementDataItemValue('isForCompensation')) {
            return;
        }

        if ($process->getStatus() !== BpmnProcess::STATUS_STARTED) {
            $this->setFlowNodeFailed($flowNode);

            throw new Error("Attempted to continue flow of not active process " . $process->getId() . ".");
        }
    }

    private function getAndLockFlowNodeById(string $id): BpmnFlowNode
    {
        $transactionManager = $this->getEntityManager()->getTransactionManager();

        $transactionManager->start();

        /** @var ?BpmnFlowNode $flowNode */
        $flowNode = $this->getEntityManager()
            ->getRDBRepository('BpmnFlowNode')
            ->forUpdate()
            ->where(['id' => $id])
            ->findOne();

        if (!$flowNode) {
            $transactionManager->rollback();

            throw new Error("Can't find Flow Node " . $id . ".");
        }

        if ($flowNode->get('isLocked')) {
            $transactionManager->rollback();

            throw new Error("Can't get locked Flow Node " . $id . ".");
        }

        $this->lockFlowNode($flowNode);

        $transactionManager->commit();

        return $flowNode;
    }

    private function lockFlowNode(BpmnFlowNode $flowNode): void
    {
        $flowNode->set('isLocked', true);

        $this->getEntityManager()->saveEntity($flowNode);
    }

    private function unlockFlowNode(BpmnFlowNode $flowNode): void
    {
        $flowNode->set('isLocked', false);

        $this->getEntityManager()->saveEntity($flowNode);
    }

    /**
     * @throws Error
     */
    public function proceedPendingFlow(BpmnFlowNode $flowNode): void
    {
        $GLOBALS['log']->debug("BPM: proceedPendingFlow, node {$flowNode->getId()}.");

        $flowNode = $this->getAndLockFlowNodeById($flowNode->getId());

        /** @var ?BpmnProcess $process */
        $process = $this->getEntityManager()->getEntity(BpmnProcess::ENTITY_TYPE, $flowNode->getProcessId());
        $target = $this->getEntityManager()->getEntity($flowNode->getTargetType(), $flowNode->getTargetId());

        if (
            $flowNode->getStatus() !== BpmnFlowNode::STATUS_PENDING &&
            $flowNode->getStatus() !== BpmnFlowNode::STATUS_STANDBY
        ) {
            $this->unlockFlowNode($flowNode);

            $GLOBALS['log']->info(
                "BPM: Can not proceed not pending or standby (". $flowNode->getStatus() .") flow node in process " .
                $process->getId() . "."
            );

            return;
        }

        $this->checkFlowIsActual($target, $flowNode, $process);

        $impl = $this->getFlowNodeImplementation($target, $flowNode, $process);
        $impl->proceedPending();

        $this->unlockFlowNode($flowNode);
    }

    /**
     * @throws Error
     */
    public function completeFlow(BpmnFlowNode $flowNode): void
    {
        $GLOBALS['log']->debug("BPM: completeFlow, node {$flowNode->getId()}");

        $flowNode = $this->getAndLockFlowNodeById($flowNode->getId());
        $target = $this->getEntityManager()->getEntity($flowNode->getTargetType(), $flowNode->getTargetId());
        /** @var ?BpmnProcess $process */
        $process = $this->getEntityManager()->getEntity(BpmnProcess::ENTITY_TYPE, $flowNode->getProcessId());

        if ($flowNode->getStatus() !== BpmnFlowNode::STATUS_IN_PROCESS) {
            $this->unlockFlowNode($flowNode);

            throw new Error("BPM: Can not complete not 'In Process' flow node in process " . $process->getId() . ".");
        }

        if ($flowNode->getElementType() !== 'eventSubProcess') {
            $this->checkFlowIsActual($target, $flowNode, $process);
        }

        $this->getFlowNodeImplementation($target, $flowNode, $process)->complete();
        $this->unlockFlowNode($flowNode);

        if (
            $flowNode->getElementType() === 'eventSubProcess' &&
            (
                self::isEventSubProcessFlowNodeInterrupting($flowNode) ||
                self::isEventSubProcessFlowNodeErrorHandler($flowNode)
            ) &&
            $process->getParentProcessFlowNodeId()
        ) {
            // A sub-process interrupted by interrupting event is complete.

            $parentFlowNode = $this->getAndLockFlowNodeById($process->getParentProcessFlowNodeId());
            $parentFlowNode->set('status', BpmnFlowNode::STATUS_INTERRUPTED);

            $this->saveFlowNode($parentFlowNode);
            $this->unlockFlowNode($parentFlowNode);

            $process = $this->getProcessById($process->getParentProcessId());

            $this->endProcessFlow($parentFlowNode, $process);
        }
    }

    /**
     * @throws Error
     */
    private function failFlow(BpmnFlowNode $flowNode): void
    {
        $id = $flowNode->getId();

        $GLOBALS['log']->debug("BPM: failFlow, node $id");

        $flowNode = $this->getAndLockFlowNodeById($id);
        /** @var ?BpmnProcess $process */
        $process = $this->getEntityManager()->getEntityById(BpmnProcess::ENTITY_TYPE, $flowNode->getProcessId());
        $target = $this->getEntityManager()->getEntityById($flowNode->getTargetType(), $flowNode->getTargetId());

        if (!$this->isFlowNodeIsActual($flowNode)) {
            $this->unlockFlowNode($flowNode);

            throw new Error("Can not proceed not 'In Process' flow node in process $id.");
        }

        $this->checkFlowIsActual($target, $flowNode, $process);
        $this->getFlowNodeImplementation($target, $flowNode, $process)->fail();

        $this->unlockFlowNode($flowNode);
    }

    public function cancelActivityByBoundaryEvent(BpmnFlowNode $flowNode): void
    {
        $GLOBALS['log']->debug("BPM: cancelActivityByBoundaryEvent, node {$flowNode->getId()}");

        $activityFlowNode = $this->getAndLockFlowNodeById($flowNode->get('previousFlowNodeId'));

        /** @var ?BpmnProcess $process */
        $process = $this->getEntityManager()->getEntityById(BpmnProcess::ENTITY_TYPE, $flowNode->getProcessId());
        $target = $this->getEntityManager()->getEntityById($flowNode->getTargetType(), $flowNode->getTargetId());

        if (!$this->isFlowNodeIsActual($activityFlowNode)) {
            $this->unlockFlowNode($activityFlowNode);

            return;
        }

        $this->checkFlowIsActual($target, $activityFlowNode, $process);

        $impl = $this->getFlowNodeImplementation($target, $activityFlowNode, $process);

        $impl->interrupt();

        if (in_array($activityFlowNode->get('elementType'), ['callActivity', 'subProcess', 'eventSubProcess'])) {
            $subProcess = $this->getEntityManager()
                ->getRDBRepository(BpmnProcess::ENTITY_TYPE)
                ->where([
                    'parentProcessFlowNodeId' => $activityFlowNode->getId(),
                ])
                ->findOne();

            if ($subProcess) {
                try {
                    $this->interruptProcess($subProcess);
                }
                catch (Throwable $e) {
                    $GLOBALS['log']->error("BPM: Fail when tried to interrupt sub-process; " . $e->getMessage());
                }
            }
        }

        $this->unlockFlowNode($activityFlowNode);
    }

    private function isFlowNodeIsActual(BpmnFlowNode $flowNode): bool
    {
        return !in_array(
            $flowNode->getStatus(),
            [
                BpmnFlowNode::STATUS_FAILED,
                BpmnFlowNode::STATUS_REJECTED,
                BpmnFlowNode::STATUS_PROCESSED,
                BpmnFlowNode::STATUS_INTERRUPTED,
            ]
        );
    }

    private function failProcessFlow(Entity $target, BpmnFlowNode $flowNode, BpmnProcess $process): void
    {
        $this->getFlowNodeImplementation($target, $flowNode, $process)->fail();
    }

    public function getFlowNodeImplementation(Entity $target, BpmnFlowNode $flowNode, BpmnProcess $process): Base
    {
        $elementType = $flowNode->get('elementType');

        $className = 'Espo\\Modules\\Advanced\\Core\\Bpmn\\Elements\\' . ucfirst($elementType);

        return new $className(
            $this->container,
            $this,
            $target,
            $flowNode,
            $process
        );
    }

    private function getActiveFlowCount(BpmnProcess $process): int
    {
        return $this->getEntityManager()
            ->getRDBRepository(BpmnFlowNode::ENTITY_TYPE)
            ->where([
                'status!=' => [
                    BpmnFlowNode::STATUS_PROCESSED,
                    BpmnFlowNode::STATUS_REJECTED,
                    BpmnFlowNode::STATUS_FAILED,
                    BpmnFlowNode::STATUS_INTERRUPTED,
                    BpmnFlowNode::STATUS_STANDBY,
                ],
                'processId' => $process->getId(),
                'elementType!=' => 'eventSubProcess',
            ])
            ->count();
    }

    private function getActiveEventSubProcessCount(BpmnProcess $process): int
    {
        return $this->getEntityManager()
            ->getRDBRepository(BpmnFlowNode::ENTITY_TYPE)
            ->where([
                'status' => [BpmnFlowNode::STATUS_IN_PROCESS],
                'processId' => $process->getId(),
                'elementType' => 'eventSubProcess',
            ])
            ->count();
    }

    public function endProcessFlow(BpmnFlowNode $flowNode, BpmnProcess $process): void
    {
        $GLOBALS['log']->debug("BPM: endProcessFlow, node {$flowNode->getId()}.");

        if ($this->isFlowNodeIsActual($flowNode)) {
            $flowNode->set('status', BpmnFlowNode::STATUS_REJECTED);

            $this->getEntityManager()->saveEntity($flowNode);
        }

        $this->tryToEndProcess($process);
    }

    /**
     * @throws Error
     */
    public function tryToEndProcess(BpmnProcess $process): void
    {
        $GLOBALS['log']->debug("BPM: tryToEndProcess, process {$process->getId()}.");

        if (
            !$this->getActiveFlowCount($process) &&
            in_array(
                $process->getStatus(),
                [
                    BpmnProcess::STATUS_STARTED,
                    BpmnProcess::STATUS_PAUSED,
                ]
            )
        ) {
            if ($this->getActiveEventSubProcessCount($process)) {
                $this->rejectActiveFlows($process);

                return;
            }

            $this->endProcess($process);
        }
    }

    /**
     * @throws Error
     */
    public function endProcess(BpmnProcess $process, bool $interruptSubProcesses = false): void
    {
        $GLOBALS['log']->debug("BPM: endProcess, process {$process->getId()}.");

        $this->rejectActiveFlows($process);

        if (
            !in_array($process->getStatus(), [
                BpmnProcess::STATUS_STARTED,
                BpmnProcess::STATUS_PAUSED,
            ])
        ) {
            throw new Error('Process ' . $process->getId() . " can't be ended because it's not active.");
        }

        if ($interruptSubProcesses) {
            $this->interruptSubProcesses($process);
        }

        $process->set([
            'status' => BpmnProcess::STATUS_ENDED,
            'endedAt' => date('Y-m-d H:i:s'),
        ]);

        $this->getEntityManager()->saveEntity($process, ['modifiedById' => 'system']);

        if (!$process->hasParentProcess()) {
            return;
        }

        /** @var ?BpmnFlowNode $parentFlowNode */
        $parentFlowNode = $this->getEntityManager()
            ->getEntity(BpmnFlowNode::ENTITY_TYPE, $process->getParentProcessFlowNodeId());

        if (!$parentFlowNode) {
            return;
        }

        $this->completeFlow($parentFlowNode);
    }

    /**
     * @throws Error
     */
    public function escalate(BpmnProcess $process, ?string $escalationCode = null): void
    {
        $GLOBALS['log']->debug("BPM: escalate, process {$process->getId()}.");

        if (
            !in_array($process->getStatus(), [
                BpmnProcess::STATUS_STARTED,
                BpmnProcess::STATUS_PAUSED,
            ])
        ) {
            throw new Error('Process ' . $process->getId() . " can't have an escalation because it's not active.");
        }

        $escalationEventSubProcessFlowNode = $this->prepareEscalationEventSubProcessFlowNode($process, $escalationCode);

        $targetType = $process->getTargetType();
        $targetId = $process->getTargetId();

        if ($escalationEventSubProcessFlowNode) {
            $GLOBALS['log']->info("BPM: escalation event sub-process found");

            $target = $this->getEntityManager()->getEntity($targetType, $targetId);

            if (!$target) {
                return;
            }

            $isInterrupting = self::isEventSubProcessFlowNodeInterrupting($escalationEventSubProcessFlowNode);

            if ($isInterrupting) {
                $this->interruptProcessByEventSubProcess($process, $escalationEventSubProcessFlowNode);
            }

            $this->processPreparedFlowNode($target, $escalationEventSubProcessFlowNode, $process);

            return;
        }

        if (!$process->hasParentProcess()) {
            return;
        }

        /** @var ?BpmnProcess $parentProcess */
        $parentProcess = $this->getEntityManager()->getEntity(BpmnProcess::ENTITY_TYPE, $process->getParentProcessId());

        /** @var ?BpmnFlowNode $parentFlowNode */
        $parentFlowNode = $this->getEntityManager()
            ->getEntity(BpmnFlowNode::ENTITY_TYPE, $process->getParentProcessFlowNodeId());

        if (!$parentProcess || !$parentFlowNode) {
            return;
        }

        $target = $this->getEntityManager()
            ->getEntity($parentFlowNode->getTargetType(), $parentFlowNode->getTargetId());

        $boundaryFlowNode = $this->prepareBoundaryEscalationFlowNode(
            $parentFlowNode,
            $parentProcess,
            $escalationCode
        );

        if ($boundaryFlowNode && $target) {
            $this->processPreparedFlowNode($target, $boundaryFlowNode, $parentProcess);
        }
    }

    private static function isEventSubProcessFlowNodeInterrupting(BpmnFlowNode $flowNode): bool
    {
        $data = $flowNode->getElementDataItemValue('eventStartData') ?? (object) [];

        return $data->isInterrupting ?? false;
    }

    private static function isEventSubProcessFlowNodeErrorHandler(BpmnFlowNode $flowNode): bool
    {
        return (bool) $flowNode->getDataItemValue('isErrorHandler');
    }

    public function broadcastSignal(string $signal): void
    {
        $GLOBALS['log']->debug("BPM: broadcastSignal");

        $itemList = $this->getEntityManager()
            ->getRDBRepository('BpmnSignalListener')
            ->select(['id', 'flowNodeId'])
            ->where([
                'name' => $signal,
                'isTriggered' => false,
            ])
            ->order('number')
            ->find();

        foreach ($itemList as $item) {
            $this->getEntityManager()
                ->getRDBRepository('BpmnSignalListener')
                ->deleteFromDb($item->getId());
        }

        foreach ($itemList as $item) {
            $flowNodeId = $item->get('flowNodeId');

            /** @var ?BpmnFlowNode $flowNode */
            $flowNode = $this->getEntityManager()->getEntityById(BpmnFlowNode::ENTITY_TYPE, $flowNodeId);

            if (!$flowNode) {
                $GLOBALS['log']->notice("BPM: broadcastSignal, flow node $flowNodeId not found.");

                continue;
            }

            try {
                $this->proceedPendingFlow($flowNode);
            }
            catch (Throwable $e) {
                $this->logException($e);
            }
        }
    }

    /**
     * @throws Error
     */
    public function prepareBoundaryEscalationFlowNode(
        BpmnFlowNode $flowNode,
        BpmnProcess $process,
        ?string $escalationCode = null
    ): ?BpmnFlowNode {

        $attachedElementIdList = $process->getAttachedToFlowNodeElementIdList($flowNode);

        $found1Id = null;
        $found2Id = null;

        foreach ($attachedElementIdList as $id) {
            $item = $process->getElementDataById($id);

            if (!isset($item->type)) {
                continue;
            }

            if ($item->type === 'eventIntermediateEscalationBoundary') {
                if (!$escalationCode) {
                    if (empty($item->escalationCode)) {
                        $found1Id = $id;

                        break;
                    }
                }
                else {
                    if (empty($item->escalationCode)) {
                        if (!$found2Id) {
                            $found2Id = $id;
                        }
                    }
                    else {
                        if ($item->escalationCode == $escalationCode) {
                            $found1Id = $id;

                            break;
                        }
                    }
                }
            }
        }

        $elementId = $found1Id ?: $found2Id;

        if (!$elementId) {
            return null;
        }

        $target = $this->getEntityManager()->getEntity($flowNode->getTargetType(), $flowNode->getTargetId());

        if (!$target) {
            return null;
        }

        return $this->prepareFlow(
            $target,
            $process,
            $elementId,
            $flowNode->getId(),
            $flowNode->getElementType()
        );
    }

    /**
     * @throws Error
     */
    public function endProcessWithError(
        BpmnProcess $process,
        ?string $errorCode = null,
        ?string $errorMessage = null
    ): void {

        $GLOBALS['log']->debug("BPM: endProcessWithError, process {$process->getId()}.");

        $this->rejectActiveFlows($process);

        if (
            !in_array($process->getStatus(), [
                BpmnProcess::STATUS_STARTED,
                BpmnProcess::STATUS_PAUSED,
            ])
        ) {
            throw new Error('Process ' . $process->getId() . " can't be ended because it's not active.");
        }

        $this->interruptSubProcesses($process);

        $process->set([
            'status' => BpmnProcess::STATUS_ENDED,
            'endedAt' => date('Y-m-d H:i:s'),
        ]);

        $this->getEntityManager()->saveEntity($process, ['modifiedById' => 'system']);

        $this->triggerError($process, $errorCode, $errorMessage);
    }

    /**
     * @throws Error
     */
    private function triggerError(
        BpmnProcess $process,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        bool $skipHandler = false
    ): void {

        $GLOBALS['log']->info("BPM: triggerError");

        $errorEventSubProcessFlowNode = !$skipHandler ?
            $this->prepareErrorEventSubProcessFlowNode($process, $errorCode) :
            null;

        if ($errorEventSubProcessFlowNode) {
            $GLOBALS['log']->info("BPM: error event sub-process found");

            $errorEventSubProcessFlowNode->setDataItemValue('caughtErrorCode', $errorCode);
            $errorEventSubProcessFlowNode->setDataItemValue('caughtErrorMessage', $errorMessage);

            $this->getEntityManager()->saveEntity($errorEventSubProcessFlowNode);

            $target = $this->getEntityManager()->getEntity($process->getTargetType(), $process->getTargetId());

            if ($target) {
                $this->processPreparedFlowNode($target, $errorEventSubProcessFlowNode, $process);
            }

            return;
        }

        if (!$process->hasParentProcess()) {
            return;
        }

        /** @var ?BpmnProcess $parentProcess */
        $parentProcess = $this->getEntityManager()
            ->getEntity(BpmnProcess::ENTITY_TYPE, $process->getParentProcessId());

        /** @var ?BpmnFlowNode $parentFlowNode */
        $parentFlowNode = $this->getEntityManager()
            ->getEntity(BpmnFlowNode::ENTITY_TYPE, $process->getParentProcessFlowNodeId());

        if (!$parentProcess || !$parentFlowNode) {
            return;
        }

        $parentFlowNode->setDataItemValue('errorCode', $errorCode);
        $parentFlowNode->setDataItemValue('errorMessage', $errorMessage);
        $parentFlowNode->setDataItemValue('errorTriggered', true);

        $this->getEntityManager()->saveEntity($parentFlowNode);

        $isInterruptingSubProcess =
            $parentFlowNode->getElementType() === 'eventSubProcess' &&
            (
                self::isEventSubProcessFlowNodeErrorHandler($parentFlowNode) ||
                self::isEventSubProcessFlowNodeInterrupting($parentFlowNode)
            );

        if ($isInterruptingSubProcess) {
            $parentFlowNode->set('status', BpmnFlowNode::STATUS_PROCESSED);
            $this->getEntityManager()->saveEntity($parentFlowNode);

            $this->triggerError($parentProcess, $errorCode, $errorMessage, true);

            return;
        }

        $this->failFlow($parentFlowNode);
    }

    private function interruptSubProcesses(BpmnProcess $process): void
    {
        $subProcessList = $this->getEntityManager()
            ->getRDBRepository(BpmnProcess::ENTITY_TYPE)
            ->where([
                'parentProcessId' => $process->getId(),
                'status' => [
                    BpmnProcess::STATUS_STARTED,
                    BpmnProcess::STATUS_PAUSED,
                ],
            ])
            ->find();

        foreach ($subProcessList as $subProcess) {
            try {
                $this->interruptProcess($subProcess);
            } catch (Throwable $e) {
                $GLOBALS['log']->error($e->getMessage());
            }
        }
    }

    public function interruptProcess(BpmnProcess $process): void
    {
        $GLOBALS['log']->debug("BPM: interruptProcess, process {$process->getId()}.");

        /** @var ?BpmnProcess $process */
        $process = $this->getEntityManager()->getEntity(BpmnProcess::ENTITY_TYPE, $process->getId());

        if (
            !$process ||
            !in_array($process->getStatus(), [
                BpmnProcess::STATUS_STARTED,
                BpmnProcess::STATUS_PAUSED,
            ])
        ) {
            throw new Error('Process ' . $process->getId() . " can't be interrupted because it's not active.");
        }

        $this->rejectActiveFlows($process);

        $process->set(['status' => BpmnProcess::STATUS_INTERRUPTED]);
        $this->getEntityManager()->saveEntity($process, ['skipModifiedBy' => true]);

        $this->interruptSubProcesses($process);
    }

    /**
     * @throws Error
     */
    public function interruptProcessByEventSubProcess(BpmnProcess $process, BpmnFlowNode $interruptingFlowNode): void
    {
        $GLOBALS['log']->debug("BPM: interruptProcessByEventSubProcess, process {$process->getId()}.");

        /** @var ?BpmnProcess $process */
        $process = $this->getEntityManager()->getEntity(BpmnProcess::ENTITY_TYPE, $process->getId());

        if (
            !$process ||
            !in_array($process->getStatus(), [
                BpmnProcess::STATUS_STARTED,
                BpmnProcess::STATUS_PAUSED,
            ])
        ) {
            throw new Error('Process ' . $process->getId() . " can't be interrupted because it's not active.");
        }

        $this->rejectActiveFlows($process, $interruptingFlowNode->getId());

        $process->set(['status' => BpmnProcess::STATUS_INTERRUPTED]);
        $this->getEntityManager()->saveEntity($process, ['skipModifiedBy' => true]);

        $this->interruptSubProcesses($process);
    }

    public function prepareEscalationEventSubProcessFlowNode(
        BpmnProcess $process,
        ?string $escalationCode = null
    ): ?BpmnFlowNode {

        $found1Id = null;
        $found2Id = null;

        foreach ($this->getProcessElementEventSubProcessIdList($process) as $id) {
            $item = $process->getElementDataById($id);

            if (($item->type ?? null) !== 'eventSubProcess') {
                continue;
            }

            $item = $item->eventStartData ?? (object) [];

            if (($item->type ?? null) !== 'eventStartEscalation') {
                continue;
            }

            if (!$escalationCode) {
                if (empty($item->escalationCode)) {
                    $found1Id = $id;
                    break;
                }
            }
            else {
                if (empty($item->escalationCode)) {
                    if (!$found2Id) {
                        $found2Id = $id;
                    }
                } else {
                    if ($item->escalationCode == $escalationCode) {
                        $found1Id = $id;

                        break;
                    }
                }
            }
        }

        $elementId = $found1Id ?: $found2Id;

        if (!$elementId) {
            return null;
        }

        $target = $this->getEntityManager()->getEntity($process->getTargetType(), $process->getTargetId());

        if (!$target) {
            return null;
        }

        return $this->prepareFlow(
            $target,
            $process,
            $elementId,
            null,
            null,
            null,
            true
        );
    }

    public function prepareErrorEventSubProcessFlowNode(
        BpmnProcess $process,
        ?string $errorCode = null
    ): ?BpmnFlowNode {

        $found1Id = null;
        $found2Id = null;

        foreach ($this->getProcessElementEventSubProcessIdList($process) as $id) {
            $item = $process->getElementDataById($id);

            if (($item->type ?? null) !== 'eventSubProcess') {
                continue;
            }

            $item = $item->eventStartData ?? (object) [];

            if (($item->type ?? null) !== 'eventStartError') {
                continue;
            }

            if (!$errorCode) {
                if (empty($item->errorCode)) {
                    $found1Id = $id;

                    break;
                }
            } else {
                if (empty($item->errorCode)) {
                    if (!$found2Id) {
                        $found2Id = $id;
                    }
                } else {
                    if ($item->errorCode == $errorCode) {
                        $found1Id = $id;

                        break;
                    }
                }
            }
        }

        $elementId = $found1Id ?: $found2Id;

        if (!$elementId) {
            return null;
        }

        $target = $this->getEntityManager()->getEntity($process->getTargetType(), $process->getTargetId());

        if (!$target) {
            return null;
        }

        $flowNode = $this->prepareFlow(
            $target,
            $process,
            $elementId,
            null,
            null,
            null,
            true
        );

        if (!$flowNode) {
            return null;
        }

        $flowNode->setDataItemValue('isErrorHandler', true);
        $this->getEntityManager()->saveEntity($flowNode);

        return $flowNode;
    }

    /**
     * @throws Error
     */
    public function prepareBoundaryErrorFlowNode(
        BpmnFlowNode $flowNode,
        BpmnProcess  $process,
        ?string $errorCode = null
    ): ?BpmnFlowNode {

        $attachedElementIdList = $process->getAttachedToFlowNodeElementIdList($flowNode);

        $found1Id = null;
        $found2Id = null;

        foreach ($attachedElementIdList as $id) {
            $item = $process->getElementDataById($id);

            if (!isset($item->type)) {
                continue;
            }

            if ($item->type === 'eventIntermediateErrorBoundary') {
                if (!$errorCode) {
                    if (empty($item->errorCode)) {
                        $found1Id = $id;

                        break;
                    }
                } else {
                    if (empty($item->errorCode)) {
                        if (!$found2Id) {
                            $found2Id = $id;
                        }
                    } else {
                        if ($item->errorCode == $errorCode) {
                            $found1Id = $id;

                            break;
                        }
                    }
                }
            }
        }

        $errorElementId = $found1Id ?: $found2Id;

        if (!$errorElementId) {
            return null;
        }

        $target = $this->getEntityManager()->getEntity($flowNode->getTargetType(), $flowNode->getTargetId());

        if (!$target) {
            return null;
        }

        return $this->prepareFlow(
            $target,
            $process,
            $errorElementId,
            $flowNode->getId(),
            $flowNode->get('elementType')
        );
    }

    public function stopProcess(BpmnProcess $process): void
    {
        $GLOBALS['log']->debug("BPM: stopProcess, process {$process->getId()}.");

        $this->rejectActiveFlows($process);
        $this->interruptSubProcesses($process);

        $process->set([
            'endedAt' => date('Y-m-d H:i:s'),
        ]);

        if ($process->getStatus() !== BpmnProcess::STATUS_STOPPED) {
            $process->set([
                'status' => BpmnProcess::STATUS_STOPPED,
            ]);
        }

        $this->getEntityManager()->saveEntity($process, [
            'modifiedById' => 'system',
            'skipStopProcess' => true,
        ]);
    }

    private function rejectActiveFlows(BpmnProcess $process, ?string $exclusionFlowNodeId = null): void
    {
        $GLOBALS['log']->debug("BPM: rejectActiveFlows, process {$process->getId()}.");

        $where = [
            'status!=' => [
                BpmnFlowNode::STATUS_PROCESSED,
                BpmnFlowNode::STATUS_REJECTED,
                BpmnFlowNode::STATUS_FAILED,
                BpmnFlowNode::STATUS_INTERRUPTED,
            ],
            'processId' => $process->getId(),
            'elementType!=' => 'eventSubProcess',
        ];

        if ($exclusionFlowNodeId) {
            $where['id!='] = $exclusionFlowNodeId;
        }

        /** @var Collection<BpmnFlowNode> $flowNodeList */
        $flowNodeList = $this->getEntityManager()
            ->getRDBRepository(BpmnFlowNode::ENTITY_TYPE)
            ->where($where)
            ->find();

        foreach ($flowNodeList as $flowNode) {
            if ($flowNode->getStatus() === BpmnFlowNode::STATUS_IN_PROCESS) {
                $flowNode->set('status', BpmnFlowNode::STATUS_INTERRUPTED);
            } else {
                $flowNode->set('status', BpmnFlowNode::STATUS_REJECTED);
            }

            $this->getEntityManager()->saveEntity($flowNode);
        }

        $target = $this->getEntityManager()->getEntity($process->getTargetType(), $process->getTargetId());

        if ($target) {
            foreach ($flowNodeList as $flowNode) {
                if ($flowNode->getStatus() === BpmnFlowNode::STATUS_INTERRUPTED) {
                    $impl = $this->getFlowNodeImplementation($target, $flowNode, $process);

                    $impl->cleanupInterrupted();
                }
            }
        }
    }

    private function logException(Throwable $e): void
    {
        $GLOBALS['log']->error($e->getMessage());

        if ($this->getConfig()->get('logger.printTrace')) {
            $GLOBALS['log']->error($e->getTraceAsString());
        }
    }

    private function saveFlowNode(BpmnFlowNode $flowNode): void
    {
        $this->getEntityManager()->saveEntity($flowNode);
    }

    /**
     * @throws Error
     */
    private function getProcessById(string $id): BpmnProcess
    {
        /** @var ?BpmnProcess $process */
        $process = $this->getEntityManager()->getEntityById(BpmnProcess::ENTITY_TYPE, $id);

        if (!$process) {
            throw new Error("Could not get process $id.");
        }

        return $process;
    }

    /**
     * @return string[]
     * @throws Error
     */
    public function compensate(BpmnProcess $process, ?string $activityId): array
    {
        $builder = $this->getEntityManager()
            ->getRDBRepository(BpmnFlowNode::ENTITY_TYPE)
            ->where([
                'processId' => $process->getId(),
                'status' => BpmnFlowNode::STATUS_PROCESSED,
                'elementType' => [
                    'subProcess',
                    'callActivity',
                    'task',
                    'taskScript',
                    'taskUser',
                    'taskSendMessage',
                ]
            ])
            ->order('number', 'DESC');

        if ($activityId) {
            $builder->where(['elementId' => $activityId]);
        }

        /** @var Collection<BpmnFlowNode> $activityNodes */
        $activityNodes = $builder->find();

        if (iterator_count($activityNodes) === 0) {
            return [];
        }

        $compensationNodeIds = [];
        $compensationNodes = [];

        foreach ($activityNodes as $activityNode) {
            $compensationNode =
                $this->prepareBoundaryCompensation($process, $activityNode) ??
                $this->prepareSubProcessCompensation($process, $activityNode);

            if (!$compensationNode) {
                continue;
            }

            $compensationNodes[] = $compensationNode;
            $compensationNodeIds[] = $compensationNode->getId();
        }

        foreach ($compensationNodes as $node) {
            $itemProcess = $process;

            if ($node->getElementType() === 'eventSubProcess') {
                $itemProcess = $this->getEntityManager()
                    ->getEntityById(BpmnProcess::ENTITY_TYPE, $node->getProcessId());

                if (!$itemProcess) {
                    throw new Error("No process.");
                }
            }

            $target = $this->getEntityManager()
                ->getEntity($node->getTargetType(), $node->getTargetId());

            if (!$target) {
                throw new Error("No target {$node->getTargetType()} {$node->getTargetId()}.");
            }

            $this->processPreparedFlowNode($target, $node, $itemProcess);
        }

        return $compensationNodeIds;
    }

    /**
     * @throws Error
     */
    private function prepareBoundaryCompensation(BpmnProcess $process, BpmnFlowNode $activityNode): ?BpmnFlowNode
    {
        $attachedElementIdList = $process->getAttachedToFlowNodeElementIdList($activityNode);

        if ($activityNode->getElementDataItemValue('isMultiInstance')) {
            return null;
        }

        $compensationElementId = null;

        foreach ($attachedElementIdList as $elementId) {
            $item = $process->getElementDataById($elementId);

            if (($item->type ?? null) === 'eventIntermediateCompensationBoundary') {
                /** @var ?string $compensationElementId */
                $compensationElementId = ($item->nextElementIdList ?? [])[0] ?? null;

                break;
            }
        }

        if (!$compensationElementId) {
            return null;
        }

        $target = $this->getEntityManager()->getEntity($process->getTargetType(), $process->getTargetId());

        if (!$target) {
            return null;
        }

        $node = $this->prepareFlow(
            $target,
            $process,
            $compensationElementId,
            null,
            null,
            null,
            true
        );

        if (!$node) {
            return null;
        }

        $node->setDataItemValue('compensatedFlowNodeId', $activityNode->getId());
        $this->getEntityManager()->saveEntity($node);

        return $node;
    }

    /**
     * @throws Error
     */
    private function prepareSubProcessCompensation(BpmnProcess $process, BpmnFlowNode $activityNode): ?BpmnFlowNode
    {
        if ($activityNode->getElementType() !== 'subProcess') {
            return null;
        }

        /** @var ?BpmnProcess $subProcess */
        $subProcess = $this->getEntityManager()
            ->getRDBRepository(BpmnProcess::ENTITY_TYPE)
            ->where([
                'parentProcessFlowNodeId' => $activityNode->getId()
            ])
            ->findOne();

        if (!$subProcess) {
            return null;
        }

        $elementId = null;

        foreach ($this->getProcessElementEventSubProcessIdList($subProcess) as $id) {
            $item = $subProcess->getElementDataById($id);

            if (($item->type ?? null) !== 'eventSubProcess') {
                continue;
            }

            $startItem = $item->eventStartData ?? (object)[];

            if (($startItem->type ?? null) !== 'eventStartCompensation') {
                continue;
            }

            $elementId = $id;

            break;
        }

        if (!$elementId) {
            return null;
        }

        $target = $this->getEntityManager()->getEntityById($subProcess->getTargetType(), $subProcess->getTargetId());

        if (!$target) {
            return null;
        }

        return $this->prepareFlow(
            $target,
            $subProcess,
            $elementId,
            null,
            null,
            null,
            true
        );
    }
}
