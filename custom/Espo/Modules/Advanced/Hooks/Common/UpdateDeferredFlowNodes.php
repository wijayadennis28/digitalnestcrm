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

namespace Espo\Modules\Advanced\Hooks\Common;

use Espo\Core\Utils\Metadata;
use Espo\Modules\Advanced\Entities\BpmnFlowNode;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class UpdateDeferredFlowNodes
{
    private const LIMIT = 10;

    private EntityManager $entityManager;
    private Metadata $metadata;

    public function __construct(
        EntityManager $entityManager,
        Metadata $metadata
    ) {
        $this->entityManager = $entityManager;
        $this->metadata = $metadata;
    }

    public function afterSave(Entity $entity, array $options): void
    {
        // To skip if updated from a BPM process.
        if (!empty($options['skipWorkflow'])) {
            return;
        }

        if (!empty($options['workflowId'])) {
            return;
        }

        if (!empty($options['silent'])) {
            return;
        }

        $entityType = $entity->getEntityType();

        if (!$this->metadata->get(['scopes', $entityType, 'object'])) {
            return;
        }

        $nodeList = $this->entityManager
            ->getRDBRepository(BpmnFlowNode::ENTITY_TYPE)
            ->where([
                'targetId' => $entity->getId(),
                'targetType' => $entityType,
                'status' => [
                    BpmnFlowNode::STATUS_PENDING,
                    BpmnFlowNode::STATUS_STANDBY,
                ],
                'isDeferred' => true,
            ])
            ->limit(0, self::LIMIT)
            ->find();

        foreach ($nodeList as $node) {
            $node->set('isDeferred', false);

            $this->entityManager->saveEntity($node, [
                'silent' => true,
                'skipAll' => true,
            ]);
        }
    }
}
