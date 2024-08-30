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

namespace Espo\Modules\Advanced\Hooks\BpmnProcess;

use Espo\Core\InjectableFactory;
use Espo\Modules\Advanced\Core\Bpmn\BpmnManager;
use Espo\Modules\Advanced\Entities\BpmnProcess;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class StopProcess
{
    private InjectableFactory $injectableFactory;
    private EntityManager $entityManager;

    public function __construct(
        InjectableFactory $injectableFactory,
        EntityManager $entityManager
    ) {
        $this->injectableFactory = $injectableFactory;
        $this->entityManager = $entityManager;
    }

    /**
     * @param BpmnProcess $entity
     */
    public function afterSave(Entity $entity, array $options): void
    {
        if (!empty($options['skipStopProcess'])) {
            return;
        }

        if ($entity->isNew()) {
            return;
        }

        if (!$entity->isAttributeChanged('status')) {
            return;
        }

        if ($entity->getStatus() !== BpmnProcess::STATUS_STOPPED) {
            return;
        }

        $manager = $this->injectableFactory->create(BpmnManager::class);

        $manager->stopProcess($entity);

        $subProcessList = $this->entityManager
            ->getRDBRepository(BpmnProcess::ENTITY_TYPE)
            ->where(['parentProcessId' => $entity->getId()])
            ->find();

        foreach ($subProcessList as $e) {
            $manager->stopProcess($e);
        }
    }
}
