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

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Exceptions\Error;
use Espo\Core\Formula\Manager as FormulaManager;
use Espo\Core\InjectableFactory;
use Espo\Core\ServiceFactory;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\DateTime;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\Advanced\Core\Workflow\EntityHelper;
use Espo\Modules\Advanced\Core\Workflow\Helper;
use Espo\Modules\Advanced\Core\Workflow\Utils;
use Espo\Modules\Advanced\Entities\BpmnProcess;
use Espo\Modules\Advanced\Tools\Workflow\Core\RecipientIds;
use Espo\Modules\Advanced\Tools\Workflow\Core\RecipientProvider;
use Espo\Modules\Advanced\Tools\Workflow\Core\TargetProvider;
use Espo\ORM\Entity;
use Espo\Core\Container;
use Espo\ORM\EntityManager;

use stdClass;

abstract class Base
{
    private Container $container;
    protected EntityManager $entityManager;
    protected InjectableFactory $injectableFactory;

    private ?string $workflowId = null;
    protected ?Entity $entity = null;
    protected ?stdClass $action = null;
    protected ?stdClass $createdEntitiesData = null;
    protected bool $createdEntitiesDataIsChanged = false;
    protected ?stdClass $variables = null;
    protected ?stdClass $preparedVariables = null;
    protected ?BpmnProcess $bpmnProcess = null;

    public function __construct(Container $container)
    {
        $this->container = $container;

        /** @var EntityManager $entityManager */
        $entityManager = $container->get('entityManager');
        /** @var InjectableFactory $injectableFactory */
        $injectableFactory = $container->get('injectableFactory');

        $this->entityManager = $entityManager;
        $this->injectableFactory = $injectableFactory;
    }

    abstract protected function run(Entity $entity, stdClass $actionData): bool;

    public function process(
        Entity $entity,
        stdClass $actionData,
        ?stdClass $createdEntitiesData = null,
        ?stdClass $variables = null,
        ?BpmnProcess $bpmnProcess = null
    ) {
        $this->entity = $entity;
        $this->action = $actionData;
        $this->createdEntitiesData = $createdEntitiesData;
        $this->variables = $variables;
        $this->bpmnProcess = $bpmnProcess;

        if (!property_exists($actionData, 'cid')) {
            $actionData->cid = 0;
        }

        $GLOBALS['log']->debug(
            'Workflow\Actions: Start ['.$actionData->type.'] with cid ['.$actionData->cid.'] for entity ['.
            $entity->getEntityType().', '.$entity->get('id').'].'
        );

        $result = $this->run($entity, $actionData);

        $GLOBALS['log']->debug(
            'Workflow\Actions: End ['.$actionData->type.'] with cid ['.$actionData->cid.'] for entity ['.
            $entity->getEntityType().', '.$entity->get('id').'].'
        );

        if (!$result) {
            $GLOBALS['log']->debug(
                'Workflow['.$this->getWorkflowId().']: Action failed [' . $actionData->type .
                '] with cid [' . $actionData->cid . '].'
            );
        }
    }

    protected function getContainer(): Container
    {
        return $this->container;
    }

    public function isCreatedEntitiesDataChanged(): bool
    {
        return $this->createdEntitiesDataIsChanged;
    }

    public function getCreatedEntitiesData(): stdClass
    {
        return $this->createdEntitiesData;
    }

    public function setWorkflowId($workflowId)
    {
        $this->workflowId = $workflowId;
    }

    protected function getWorkflowId(): ?string
    {
        return $this->workflowId;
    }

    protected function getEntityManager(): EntityManager
    {
        return $this->entityManager;
    }

    protected function getServiceFactory(): ServiceFactory
    {
        /** @var ServiceFactory */
        return $this->container->get('serviceFactory');
    }

    protected function getMetadata(): Metadata
    {
        /** @var Metadata */
        return $this->container->get('metadata');
    }

    protected function getConfig(): Config
    {
        /** @var Config */
        return $this->container->get('config');
    }

    protected function getFormulaManager(): FormulaManager
    {
        /** @var FormulaManager */
        return $this->container->get('formulaManager');
    }

    protected function getUser(): User
    {
        /** @var User */
        return $this->container->get('user');
    }

    protected function getEntity(): Entity
    {
        return $this->entity;
    }

    protected function getActionData(): stdClass
    {
        return $this->action;
    }

    protected function getHelper(): Helper
    {
        /** @var Helper */
        return $this->container->get('workflowHelper');
    }

    protected function getEntityHelper(): EntityHelper
    {
        /** @var EntityHelper */
        return $this->getHelper()->getEntityHelper();
    }

    protected function clearSystemVariables(stdClass $variables): void
    {
        unset($variables->__targetEntity);
        unset($variables->__processEntity);
        unset($variables->__createdEntitiesData);
    }

    /**
     * Return variables. Can be changed after action is processed.
     */
    public function getVariablesBack(): stdClass
    {
        $variables = clone $this->variables;

        $this->clearSystemVariables($variables);

        return $variables;
    }

    /**
     * Get variables for usage within an action.
     */
    public function getVariables(): stdClass
    {
        $variables = clone $this->getFormulaVariables();

        $this->clearSystemVariables($variables);

        return $variables;
    }

    protected function hasVariables(): bool
    {
        return !!$this->variables;
    }

    protected function updateVariables(stdClass $variables): void
    {
        if (!$this->hasVariables()) {
            return;
        }

        $variables = clone $variables;

        $this->clearSystemVariables($variables);

        foreach (get_object_vars($variables) as $k => $v) {
            $this->variables->$k = $v;
        }
    }

    protected function getFormulaVariables(): stdClass
    {
        if (!$this->preparedVariables) {
            $o = (object) [];

            $o->__targetEntity = $this->getEntity();

            if ($this->bpmnProcess) {
                $o->__processEntity = $this->bpmnProcess;
            }

            if ($this->createdEntitiesData) {
                $o->__createdEntitiesData = $this->createdEntitiesData;
            }

            if ($this->variables) {
                foreach ($this->variables as $k => $v) {
                    $o->$k = $v;
                }
            }

            $this->preparedVariables = $o;
        }

        return $this->preparedVariables;
    }

    /**
     * Get execute time defined in workflow
     */
    protected function getExecuteTime($data): string
    {
        $executeTime = date(DateTime::SYSTEM_DATE_TIME_FORMAT);

        if (!property_exists($data, 'execution')) {
            return $executeTime;
        }

        $execution = $data->execution;

        switch ($execution->type) {
            case 'immediately':
                return $executeTime;

            case 'later':
                $field = $execution->field ?? null;

                if ($field) {
                    $executeTime = Utils::getFieldValue($this->getEntity(), $field);
                    $attributeType = Utils::getAttributeType($this->getEntity(), $field);
                    $timezone = $this->getConfig()->get('timeZone') ?? 'UTC';

                    if ($attributeType === 'date') {
                        $executeTime = (new DateTimeImmutable($executeTime))
                            ->setTimezone(new DateTimeZone($timezone))
                            ->setTime(0, 0)
                            ->setTimezone(new DateTimeZone('UTC'))
                            ->format(DateTime::SYSTEM_DATE_TIME_FORMAT);
                    }
                }

                $execution->shiftDays = $execution->shiftDays ?? 0;
                $shiftUnit = $execution->shiftUnit ?? 'days';

                $executeTime = Utils::shiftDays(
                    $execution->shiftDays,
                    $executeTime,
                    'datetime',
                    $shiftUnit
                );

                break;

            default:
                throw new Error("Workflow[{$this->getWorkflowId()}]: Unknown execution type [$execution->type]");
        }

        return $executeTime;
    }

    protected function getCreatedEntity(string $target): ?Entity
    {
        $provider = $this->injectableFactory->create(TargetProvider::class);

        return $provider->getCreated($target, $this->createdEntitiesData);
    }

    protected function getFirstTargetFromTargetItem(Entity $entity, ?string $target): ?Entity
    {
        foreach ($this->getTargetsFromTargetItem($entity, $target) as $it) {
            return $it;
        }

        return null;
    }

    /**
     * @return iterable<Entity>
     */
    protected function getTargetsFromTargetItem(Entity $entity, ?string $target): iterable
    {
        $provider = $this->injectableFactory->create(TargetProvider::class);

        return $provider->get($entity, $target, $this->createdEntitiesData);
    }

    protected function getRecipients(Entity $entity, string $target): RecipientIds
    {
        $provider = $this->injectableFactory->create(RecipientProvider::class);

        return $provider->get($entity, $target);
    }
}
