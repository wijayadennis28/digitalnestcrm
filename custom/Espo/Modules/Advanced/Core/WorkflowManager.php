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

namespace Espo\Modules\Advanced\Core;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\File\Manager as FileManager;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\Advanced\Core\Workflow\ConditionManager;
use Espo\Modules\Advanced\Core\Workflow\ActionManager;
use Espo\Modules\Advanced\Entities\Workflow;
use Espo\ORM\Entity;
use Espo\Core\Container;
use Espo\ORM\EntityManager;

use Exception;

class WorkflowManager
{
    private Container $container;
    private ConditionManager $conditionManager;
    private ActionManager $actionManager;
    private ?array $data = null;

    private Log $log;

    private string $cacheFile = 'data/cache/advanced/workflows.php';

    private array $cacheFields = [
        'conditionsAll',
        'conditionsAny',
        'conditionsFormula',
        'actions',
    ];

    public const AFTER_RECORD_SAVED = 'afterRecordSaved';
    public const AFTER_RECORD_CREATED = 'afterRecordCreated';
    public const AFTER_RECORD_UPDATED = 'afterRecordUpdated';

    /** @var string[] */
    private array $entityListToIgnore;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->conditionManager = new ConditionManager($this->container);
        $this->actionManager = new ActionManager($this->container);

        /** @noinspection PhpFieldAssignmentTypeMismatchInspection */
        $this->log = $container->get('log');

        $this->entityListToIgnore = $this->container
            ->get('metadata')
            ->get('entityDefs.Workflow.entityListToIgnore') ?? [];
    }

    private function getConfig(): Config
    {
        /** @var Config */
        return $this->container->get('config');
    }

    private function getUser(): User
    {
        /** @var User */
        return $this->container->get('user');
    }

    private function getEntityManager(): EntityManager
    {
        /** @var EntityManager */
        return $this->container->get('entityManager');
    }

    private function getFileManager(): FileManager
    {
        /** @var FileManager */
        return $this->container->get('fileManager');
    }

    private function getData(string $entityType, string $trigger) : ?array
    {
        if (!isset($this->data)) {
            $this->loadWorkflows();
        }

        if (!isset($this->data[$trigger])) {
            return null;
        }

        if (!isset($this->data[$trigger][$entityType])) {
            return null;
        }

        $result = $this->data[$trigger][$entityType];

        if ($result && !is_array($result)) {
            $this->log->error("WorkflowManager: Bad data for workflow.");

            return null;
        }

        return $result;
    }

    /**
     * Run a signal workflow.
     *
     * @param Entity $entity An entity.
     * @param string $signal A signal.
     * @param ?array<string, mixed> $params Signal params.
     * @param array<string, mixed> $options Save options.
     */
    public function processSignal(Entity $entity, string $signal, ?array $params = null, array $options = []): void
    {
        $this->processInternal($entity, '$' . $signal, $options, $params);
    }

    /**
     * Run a workflow.
     *
     * @param Entity $entity An entity.
     * @param string $trigger A trigger.
     * @param array<string, mixed> $options Save options.
     */
    public function process(Entity $entity, string $trigger, array $options = []): void
    {
        $this->processInternal($entity, $trigger, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function processInternal(
        Entity $entity,
        string $trigger,
        array $options = [],
        ?array $signalParams = null
    ): void {

        $entityType = $entity->getEntityType();

        if (in_array($entityType, $this->entityListToIgnore)) {
            return;
        }

        $data = $this->getData($entityType, $trigger);

        if (!$data) {
            return;
        }

        $this->log->debug("WorkflowManager: Start workflow [$trigger] for [$entityType, {$entity->getId()}].");

        $conditionManager = $this->conditionManager;
        $actionManager = $this->actionManager;

        $variables = [];

        if ($signalParams) {
            $variables['__signalParams'] = (object) $signalParams;
        }

        foreach ($data as $workflowId => $workflowData) {
            $this->log->debug("Start workflow rule [$workflowId].");

            if ($workflowData['portalOnly']) {
                if (!$this->getUser()->getPortalId()) {
                    continue;
                }

                if (!empty($workflowData['portalId'])) {
                    if ($this->getUser()->get('portalId') !== $workflowData['portalId']) {
                        continue;
                    }
                }
            }

            if (!empty($options['workflowId']) && $options['workflowId'] === $workflowId) {
                continue;
            }

            $conditionManager->setInitData($workflowId, $entity);

            $result = true;

            if (isset($workflowData['conditionsAll'])) {
                $result &= $conditionManager->checkConditionsAll($workflowData['conditionsAll']);
            }

            if (isset($workflowData['conditionsAny'])) {
                $result &= $conditionManager->checkConditionsAny($workflowData['conditionsAny']);
            }

            if (!empty($workflowData['conditionsFormula'])) {
                $result &= $conditionManager->checkConditionsFormula($workflowData['conditionsFormula'], $variables);
            }

            $this->log->debug("Condition result [$result] for workflow rule [$workflowId].");

            if ($result) {
                $workflowLogRecord = $this->getEntityManager()->getEntity('WorkflowLogRecord');

                $workflowLogRecord->set([
                    'workflowId' => $workflowId,
                    'targetId' => $entity->getId(),
                    'targetType' => $entity->getEntityType(),
                ]);

                $this->getEntityManager()->saveEntity($workflowLogRecord);
            }

            if ($result && isset($workflowData['actions'])) {
                $this->log->debug("Start actions for workflow rule [$workflowId].");

                $actionManager->setInitData($workflowId, $entity);

                try {
                    $actionManager->runActions($workflowData['actions'], $variables);
                }
                catch (Exception $e) {
                    $this->log->notice("Failed action execution for workflow [$workflowId]. {$e->getMessage()}");
                }

                $this->log->debug("End running actions for workflow rule [$workflowId].");
            }

            $this->log->debug("End workflow rule [$workflowId].");
        }

        $this->log->debug("End workflow [$trigger] for [$entityType, {$entity->get('id')}].");
    }

    public function checkConditions(Workflow $workflow, Entity $entity): bool
    {
        $result = true;

        $conditionsAll = $workflow->get('conditionsAll');
        $conditionsAny = $workflow->get('conditionsAny');
        $conditionsFormula = $workflow->get('conditionsFormula');

        $conditionManager = $this->conditionManager;

        $conditionManager->setInitData($workflow->getId(), $entity);

        if (isset($conditionsAll)) {
            $result &= $conditionManager->checkConditionsAll($conditionsAll);
        }
        if (isset($conditionsAny)) {
            $result &= $conditionManager->checkConditionsAny($conditionsAny);
        }

        if ($conditionsFormula && $conditionsFormula !== '') {
            $result &= $conditionManager->checkConditionsFormula($conditionsFormula);
        }

        return $result;
    }

    public function runActions(Workflow $workflow, Entity $entity): void
    {
        $actions = $workflow->get('actions');

        $actionManager = $this->actionManager;

        $actionManager->setInitData($workflow->getId(), $entity);
        $actionManager->runActions($actions);
    }

    public function loadWorkflows(bool $reload = false): void
    {
        if (
            !$reload &&
            $this->getConfig()->get('useCache') &&
            $this->getFileManager()->exists($this->cacheFile)
        ) {
            $this->data = $this->getFileManager()->getPhpContents($this->cacheFile);

            return;
        }

        $this->data = $this->getWorkflowData();

        if ($this->getConfig()->get('useCache')) {
            $this->getFileManager()->putPhpContents($this->cacheFile, $this->data, true);
        }
    }

    /**
     * Get all workflows from database and save into cache.
     *
     * @return array<string, mixed>
     */
    private function getWorkflowData(): array
    {
        $data = [];

        /** @var iterable<Workflow> $workflowList */
        $workflowList = $this->getEntityManager()
            ->getRDBRepository(Workflow::ENTITY_TYPE)
            ->where(['isActive' => true])
            ->find();

        foreach ($workflowList as $workflow) {
            $rowData = [];

            foreach ($this->cacheFields as $fieldName) {
                if ($workflow->get($fieldName) === null) {
                    continue;
                }

                $fieldValue = $workflow->get($fieldName);

                if (!empty($fieldValue)) {
                    $rowData[$fieldName] = $fieldValue;
                }
            }

            $rowData['portalOnly'] = (bool) $workflow->get('portalOnly');

            if ($rowData['portalOnly']) {
                $rowData['portalId'] = $workflow->get('portalId');
            }

            $entityType = $workflow->getTargetEntityType();

            $id = $workflow->getId();

            $trigger = $workflow->getType() === Workflow::TYPE_SIGNAL ?
                '$' . $workflow->get('signalName') :
                $workflow->getType();

            $data[$trigger][$entityType][$id] = $rowData;
        }

        return $data;
    }
}
