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

namespace Espo\Modules\Advanced\Tools\Workflow\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Modules\Advanced\Entities\Workflow as WorkflowEntity;
use Espo\Modules\Advanced\Tools\Report\ListType\RunParams as ListRunParams;
use Espo\Modules\Advanced\Tools\Report\Service as ReportService;
use Espo\Modules\Advanced\Tools\Workflow\Service;
use Espo\ORM\EntityManager;

use Exception;
use RuntimeException;

class RunScheduledWorkflow implements Job
{
    private ReportService $reportService;
    private EntityManager $entityManager;
    private Service $service;
    private JobSchedulerFactory $jobSchedulerFactory;

    public function __construct(
        ReportService $reportService,
        EntityManager $entityManager,
        Service $service,
        JobSchedulerFactory $jobSchedulerFactory
    ) {
        $this->reportService = $reportService;
        $this->entityManager = $entityManager;
        $this->service = $service;
        $this->jobSchedulerFactory = $jobSchedulerFactory;
    }

    public function run(Data $data): void
    {
        $data = $data->getRaw();

        $workflowId = $data->workflowId ?? null;

        if (!$workflowId || !is_string($workflowId)) {
            throw new RuntimeException();
        }

        /** @var ?WorkflowEntity $workflow */
        $workflow = $this->entityManager->getEntityById(WorkflowEntity::ENTITY_TYPE, $data->workflowId);

        if (!$workflow) {
            throw new RuntimeException("Workflow $workflowId not found.");
        }

        if (!$workflow->isActive()) {
            return;
        }

        $targetReport = $this->entityManager
            ->getRDBRepository(WorkflowEntity::ENTITY_TYPE)
            ->getRelation($workflow, 'targetReport')
            ->findOne();

        if (!$targetReport) {
            throw new RuntimeException("Workflow $workflowId: Target report not found.");
        }

        $result = $this->reportService->runList(
            $targetReport->getId(),
            null,
            null,
            ListRunParams::create()->withReturnSthCollection()
        );

        foreach ($result->getCollection() as $entity) {
            try {
                $this->runScheduledWorkflowForEntity(
                    $workflow->getId(),
                    $entity->getEntityType(),
                    $entity->getId()
                );
            }
            catch (Exception $e) {
                // @todo Revise.

                $this->jobSchedulerFactory
                    ->create()
                    ->setClassName(RunScheduledWorkflowForEntity::class)
                    ->setGroup('scheduled-workflows')
                    ->setData([
                        'workflowId' => $workflow->getId(),
                        'entityType' => $entity->getEntityType(),
                        'entityId' => $entity->getId(),
                    ])
                    ->schedule();
            }
        }
    }

    private function runScheduledWorkflowForEntity(string $workflowId, string $entityType, string $id): void
    {
        $entity = $this->entityManager->getEntityById($entityType, $id);

        if (!$entity) {
            throw new RuntimeException("Workflow $workflowId: Entity $entityType $id not found.");
        }

        $this->service->triggerWorkflow($entity, $workflowId);
    }
}
