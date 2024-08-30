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

namespace Espo\Modules\Advanced\Jobs;

use Cron\CronExpression;

use DateTimeImmutable;
use Espo\Core\Job\JobDataLess;
use Espo\Core\Job\QueueName;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\DateTime as DateTimeUtil;
use Espo\Core\Utils\Log;
use Espo\Entities\Job;
use Espo\Modules\Advanced\Entities\Workflow;
use Espo\Modules\Advanced\Tools\Workflow\Jobs\RunScheduledWorkflow as RunScheduledWorkflowJob;
use Espo\ORM\EntityManager;

use Exception;
use DateTimeZone;

class RunScheduledWorkflows implements JobDataLess
{
    private EntityManager $entityManager;
    private Config $config;
    private Log $log;

    public function __construct(
        EntityManager $entityManager,
        Config $config,
        Log $log
    ) {
        $this->entityManager = $entityManager;
        $this->config = $config;
        $this->log = $log;
    }

    public function run(): void
    {
        /** @var iterable<Workflow> $collection */
        $collection = $this->entityManager
            ->getRDBRepositoryByClass(Workflow::class)
            ->where([
                'type' => Workflow::TYPE_SCHEDULED,
                'isActive' => true,
            ])
            ->find();

        $defaultTimeZone = $this->config->get('timeZone');

        foreach ($collection as $entity) {
            $timeZone = $entity->get('schedulingApplyTimezone') ? $defaultTimeZone : null;

            $scheduling = $entity->getScheduling();

            try {
                $cronExpression = method_exists(CronExpression::class, 'factory') ?
                    CronExpression::factory($scheduling) :
                    new CronExpression($scheduling);

                $executionTime = $cronExpression
                    ->getNextRunDate('now', 0, true, $timeZone)
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT);
            }
            catch (Exception $e) {
                $this->log->error("Bad scheduling in workflow {$entity->getId()}.");

                continue;
            }

            if ($entity->get('lastRun') === $executionTime) {
                continue;
            }

            if ($this->jobExists($executionTime, $entity->getId())) {
                return;
            }

            $jobData = ['workflowId' => $entity->getId()];

            $this->createJob($jobData, $executionTime, $entity->getId());

            $entity->set('lastRun', $executionTime);

            $this->entityManager->saveEntity($entity, ['silent' => true]);
        }
    }

    private function createJob(array $jobData, string $executionTime, string $workflowId): void
    {
        $job = $this->entityManager->getNewEntity(Job::ENTITY_TYPE);

        $job->set([
            'name' => RunScheduledWorkflowJob::class,
            'className' => RunScheduledWorkflowJob::class,
            'data' => $jobData,
            'executeTime' => $executionTime,
            'targetId' => $workflowId,
            'targetType' => Workflow::ENTITY_TYPE,
            //'queue' => QueueName::Q1,
        ]);

        $this->entityManager->saveEntity($job);
    }

    private function jobExists(string $time, string $workflowId): bool
    {
        $from = new DateTimeImmutable($time);
        $seconds = (int) $from->format('s');

        $from = $from->modify("- $seconds seconds");
        $to = $from->modify('+ 1 minute');

        $found = $this->entityManager
            ->getRDBRepository(Job::ENTITY_TYPE)
            ->select(['id'])
            ->where([
                'className' => RunScheduledWorkflowJob::class,
                ['executeTime>=' => $from->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT)],
                ['executeTime<' => $to->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT)],
                'targetId' => $workflowId,
                'targetType' => Workflow::ENTITY_TYPE,
            ])
            ->findOne();

        if ($found) {
            return true;
        }

        return false;
    }
}
