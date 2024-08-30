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

namespace Espo\Modules\Advanced\Core\Workflow\Actions;

use Espo\ORM\Entity;
use stdClass;

class CreateEntity extends BaseEntity
{
    protected function run(Entity $entity, stdClass $actionData): bool
    {
        $entityType = $actionData->link;

        $newEntity = $this->entityManager->getNewEntity($entityType);

        $data = $this->getDataToFill($newEntity, $actionData->fields);
        $newEntity->set($data);

        $formula = $actionData->formula ?? null;

        if ($formula) {
            $this->getFormulaManager()->run($formula, $newEntity, $this->getFormulaVariables());
        }

        if (isset($actionData->linkList) && count($actionData->linkList)) {
            foreach ($actionData->linkList as $link) {
                if ($newEntity->getRelationType($link) === Entity::BELONGS_TO) {
                    $newEntity->set($link . 'Id', $entity->getId());
                }
                else if ($newEntity->getRelationType($link) === Entity::BELONGS_TO_PARENT) {
                    $newEntity->set($link . 'Id', $entity->getId());
                    $newEntity->set($link . 'Type', $entity->getEntityType());
                }
            }
        }

        $this->entityManager->saveEntity($newEntity, [
            'workflowId' => $this->getWorkflowId(),
            'createdById' => $newEntity->get('createdById') ?? 'system',
        ]);

        if (isset($actionData->linkList) && count($actionData->linkList)) {
            foreach ($actionData->linkList as $link) {
                if (
                    !in_array(
                        $newEntity->getRelationType($link),
                        [$newEntity::BELONGS_TO, Entity::BELONGS_TO_PARENT]
                    )
                ) {
                    $this->entityManager
                        ->getRDBRepository($newEntity->getEntityType())
                        ->getRelation($newEntity, $link)
                        ->relate($entity);
                }
            }
        }

        if ($this->createdEntitiesData && !empty($actionData->elementId) && !empty($actionData->id)) {
            $this->createdEntitiesDataIsChanged = true;

            $alias = $actionData->elementId . '_' . $actionData->id;

            $this->createdEntitiesData->$alias = (object) [
                'entityType' => $newEntity->getEntityType(),
                'entityId' => $newEntity->getId(),
            ];
        }

        return true;
    }
}
