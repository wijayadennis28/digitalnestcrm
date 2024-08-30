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

class ExecuteFormula extends BaseEntity
{
    protected function run(Entity $entity, stdClass $actionData): bool
    {
        if (empty($actionData->formula)) {
            return true;
        }

        $reloadedEntity = $this->getEntityManager()->getEntity($entity->getEntityType(), $entity->get('id'));

        $variables = $this->getFormulaVariables();

        $this->getFormulaManager()->run($actionData->formula, $reloadedEntity, $variables);

        $this->updateVariables($variables);

        $isChanged = false;

        $changedMap = (object) [];

        foreach ($reloadedEntity->getAttributeList() as $attribute) {
            if ($reloadedEntity->isAttributeChanged($attribute)) {
                $changedMap->$attribute = $reloadedEntity->get($attribute);

                $isChanged = true;
            }
        }

        if ($isChanged) {
            $this->getEntityManager()->saveEntity($reloadedEntity, [
                'modifiedById' => 'system',
                'skipWorkflow' => !$this->bpmnProcess,
                'workflowId' => $this->getWorkflowId(),
            ]);

            $entity->set($changedMap);
        }

        return true;
    }
}
