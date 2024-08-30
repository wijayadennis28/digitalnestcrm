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

namespace Espo\Modules\Advanced\Tools\Workflow;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\Advanced\Controllers\WorkflowLogRecord;
use Espo\Modules\Advanced\Core\WorkflowManager;
use Espo\Modules\Advanced\Entities\Workflow;
use Espo\Modules\Advanced\Entities\Workflow as WorkflowEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class Service
{
    private EntityManager $entityManager;
    private Acl $acl;
    private User $user;
    private WorkflowManager $workflowManager;
    private Log $log;

    public function __construct(
        EntityManager $entityManager,
        Acl $acl,
        User $user,
        WorkflowManager $workflowManager,
        Log $log
    ) {
        $this->entityManager = $entityManager;
        $this->acl = $acl;
        $this->user = $user;
        $this->workflowManager = $workflowManager;
        $this->log = $log;
    }

    /**
     * @throws NotFound
     * @throws Forbidden
     */
    public function runManual(string $id, string $targetId): void
    {
        if ($this->user->isPortal()) {
            throw new Forbidden();
        }

        /** @var Workflow $workflow */
        $workflow = $this->entityManager
            ->getRDBRepository(Workflow::ENTITY_TYPE)
            ->getById($id);

        if (!$workflow) {
            throw new NotFound("Workflow $id not found.");
        }

        if ($workflow->getType() !== Workflow::TYPE_MANUAL) {
            throw new Forbidden();
        }

        $targetEntityType = $workflow->getTargetEntityType();

        $entity = $this->entityManager
            ->getRDBRepository($targetEntityType)
            ->getById($targetId);

        if (!$entity) {
            throw new NotFound();
        }

        $accessRequired = $workflow->get('manualAccessRequired');

        if ($accessRequired === 'admin') {
            if (!$this->user->isAdmin()) {
                throw new Forbidden("No admin access.");
            }
        }
        elseif ($accessRequired === Acl\Table::ACTION_READ) {
            if (!$this->acl->checkEntityRead($entity)) {
                throw new Forbidden("No read access.");
            }
        }
        else {
            if (!$this->acl->checkEntityEdit($entity)) {
                throw new Forbidden("No edit access.");
            }
        }

        if (!$this->user->isAdmin()) {
            $teamIdList = $workflow->getLinkMultipleIdList('manualTeams');

            if (array_intersect($teamIdList, $this->user->getTeamIdList()) === []) {
                throw new Forbidden("User is not from allowed team.");
            }
        }

        $this->triggerWorkflow($entity, $workflow->getId());
    }

    public function triggerWorkflow(Entity $entity, string $workflowId, bool $mandatory = false): void
    {
        /** @var ?WorkflowEntity $workflow */
        $workflow = $this->entityManager->getEntityById(WorkflowEntity::ENTITY_TYPE, $workflowId);

        if (!$workflow) {
            throw new Error("Workflow $workflowId does not exist.");
        }

        if (!$workflow->isActive()) {
            if (!$mandatory) {
                $this->log->debug("Workflow $workflowId not triggerred as it's not active.");

                return;
            }

            throw new Error("Workflow $workflowId is not active.");
        }

        if (!$this->workflowManager->checkConditions($workflow, $entity)) {
            $this->log->debug("Workflow $workflowId not triggerred as conditions are not met.");

            return;
        }

        $workflowLogRecord = $this->entityManager->getNewEntity(WorkflowLogRecord::ENTITY_TYPE);

        $workflowLogRecord->set([
            'workflowId' => $workflowId,
            'targetId' => $entity->getId(),
            'targetType' => $entity->getEntityType()
        ]);

        $this->entityManager->saveEntity($workflowLogRecord);

        $this->workflowManager->runActions($workflow, $entity);
    }
}
