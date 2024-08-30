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

use Espo\Modules\Advanced\Tools\Workflow\Action\RunAction\ServiceAction;
use Espo\ORM\Entity;
use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Json;
use stdClass;

/**
 * @noinspection PhpUnused
 */
class RunService extends Base
{
    protected function run(Entity $entity, stdClass $actionData): bool
    {
        if (empty($actionData->methodName)) {
            throw new Error("No service action name.");
        }

        $name = $actionData->methodName;

        if (!is_string($name)) {
            throw new Error("Bad service action name.");
        }

        $target = 'targetEntity';

        if (!empty($actionData->target)) {
            $target = $actionData->target;
        }

        $targetEntity = null;

        if ($target === 'targetEntity') {
            $targetEntity = $entity;
        }
        else if (strpos($target, 'created:') === 0) {
            $targetEntity = $this->getCreatedEntity($target);
        }
        else if (strpos($target, 'link:') === 0) {
            $link = substr($target, 5);

            $type = $this->getMetadata()
                ->get(['entityDefs', $entity->getEntityType(), 'links', $link, 'type']);

            if (empty($type)) {
                return false;
            }

            $idField = $link . 'Id';

            if ($type === Entity::BELONGS_TO) {
                if (!$entity->get($idField)) {
                    return false;
                }

                $foreignEntityType = $this->getMetadata()
                    ->get(['entityDefs', $entity->getEntityType(), 'links', $link, 'entity']);

                if (empty($foreignEntityType)) {
                    return false;
                }

                $targetEntity = $this->getEntityManager()->getEntity($foreignEntityType, $entity->get($idField));
            }
            else {
                return false;
            }
        }

        if (!$targetEntity) {
            return false;
        }

        $data = null;

        if (!empty($actionData->additionalParameters)) {
            $data = Json::decode($actionData->additionalParameters);
        }

        $variables = null;

        if ($this->hasVariables()) {
            $variables = $this->getVariables();
        }

        $output = null;

        $targetEntityType = $targetEntity->getEntityType();

        /** @var ?class-string<ServiceAction> $className */
        $className = $this->getMetadata()->get("app.workflow.serviceActions.$targetEntityType.$name.className") ??
            $this->getMetadata()->get("app.workflow.serviceActions.Global.$name.className");

        if ($className) {
            /** @var ServiceAction $serviceAction */
            $serviceAction = $this->injectableFactory->create($className);

            $output = $serviceAction->run($entity, $data);
        }

        // Legacy.
        if (!$className) {
            $this->runLegacy($targetEntityType, $name, $targetEntity, $data, $variables);
        }

        if (!$this->hasVariables()) {
            return true;
        }

        $variables->__lastServiceActionOutput = $output;

        $this->updateVariables($variables);

        return true;
    }

    /**
     * @param mixed $data
     */
    private function runLegacy(
        string $targetEntityType,
        string $name,
        Entity $targetEntity,
        $data,
        ?stdClass $variables
    ): void {

        $serviceName = $this->getMetadata()
            ->get(['entityDefs', 'Workflow', 'serviceActions', $targetEntityType, $name, 'serviceName']);

        $methodName = $this->getMetadata()
            ->get(['entityDefs', 'Workflow', 'serviceActions', $targetEntityType, $name, 'methodName']);

        if (!$serviceName || !$methodName) {
            $methodName = $name;
            $serviceName = $targetEntity->getEntityType();
        }

        $serviceFactory = $this->getServiceFactory();

        if (!$serviceFactory->checkExists($serviceName)) {
            throw new Error("No service $serviceName.");
        }

        $service = $serviceFactory->create($serviceName);

        if (!method_exists($service, $methodName)) {
            throw new Error("No method $methodName.");
        }

        $service->$methodName(
            $this->getWorkflowId(),
            $targetEntity,
            $data,
            $this->bpmnProcess,
            $variables ?? (object)[]
        );
    }
}
