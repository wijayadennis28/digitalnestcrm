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

namespace Espo\Modules\Advanced\Classes\FieldProcessing\BpmnFlowNode;

use Espo\Modules\Advanced\Entities\BpmnUserTask;
use Espo\ORM\Entity;
use Espo\Core\FieldProcessing\Loader;
use Espo\Core\FieldProcessing\Loader\Params;
use Espo\Core\ORM\EntityManager;

/**
 * @implements Loader<BpmnUserTask>
 */
class UserTaskLoader implements Loader
{
    private EntityManager $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function process(Entity $entity, Params $params): void
    {
        if ($entity->getElementType() !== 'taskUser') {
            return;
        }

        $userTask = $this->entityManager
            ->getRDBRepository(BpmnUserTask::ENTITY_TYPE)
            ->where(['flowNodeId' => $entity->getId()])
            ->findOne();

        if ($userTask) {
            $entity->set('userTaskId', $userTask->getId());
        }
    }
}
