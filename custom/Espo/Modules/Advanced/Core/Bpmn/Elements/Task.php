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

namespace Espo\Modules\Advanced\Core\Bpmn\Elements;

use Espo\Core\Exceptions\Error;
use Espo\Modules\Advanced\Core\Workflow\Actions\Base as BaseAction;

use Throwable;
use stdClass;

class Task extends Activity
{
    private array $localVariableList = ['_lastHttpResponseBody'];

    public function process(): void
    {
        try {
            $actionList = $this->getAttributeValue('actionList');

            if (!is_array($actionList)) {
                $actionList = [];
            }

            $localVariables = (object) [];

            foreach ($actionList as $item) {
                if (empty($item->type)) {
                    continue;
                }

                $actionImpl = $this->getActionImplementation($item->type);

                $item = clone $item;
                $item->elementId = $this->getFlowNode()->get('elementId');
                $actionData = $item;
                $originalVariables = $this->getVariablesForFormula();

                $this->copyLocalVariables($localVariables, $originalVariables);

                $target = $this->getTarget();

                $actionImpl->process(
                    $target,
                    $actionData,
                    $this->getCreatedEntitiesData(),
                    $originalVariables,
                    $this->getProcess()
                );

                $variables = $actionImpl->getVariablesBack();

                // To preserve variables needed to be available within a task
                // but not needed to be stored in a process.
                $this->copyLocalVariables($variables, $localVariables);

                foreach ($this->localVariableList as $var) {
                    unset($variables->$var);
                }

                if (
                    $actionImpl->isCreatedEntitiesDataChanged() ||
                    $variables != $originalVariables // @todo Revise.
                ) {
                    $this->sanitizeVariables($variables);

                    $this->getProcess()->set('createdEntitiesData', $actionImpl->getCreatedEntitiesData());
                    $this->getProcess()->set('variables', $variables);

                    $this->getEntityManager()->saveEntity($this->getProcess(), ['silent' => true]);
                }
            }
        } catch (Throwable $e) {
            $GLOBALS['log']->error(
                'Process ' . $this->getProcess()->get('id') . ' element ' .
                $this->getFlowNode()->get('elementId') . ': ' . $e->getMessage());

            $this->setFailedWithException($e);

            return;
        }

        $this->processNextElement();
    }

    protected function copyLocalVariables(stdClass $source, stdClass $destination)
    {
        foreach ($this->localVariableList as $key) {
            if (!property_exists($source, $key)) {
                continue;
            }

            $destination->$key = $source->$key;
        }
    }

    /**
     * @todo Use factory.
     */
    private function getActionImplementation(string $name): BaseAction
    {
        $name = ucfirst($name);
        $name = str_replace("\\", "", $name);

        $className = 'Espo\\Modules\\Advanced\\Core\\Workflow\\Actions\\' . $name;

        if (!class_exists($className)) {
            $className .= 'Type';

            if (!class_exists($className)) {
                throw new Error('Action class ' . $className . ' does not exist.');
            }
        }

        /** @var BaseAction $impl */
        $impl = new $className($this->getContainer());

        $workflowId = $this->getProcess()->get('workflowId');

        if ($workflowId) {
            $impl->setWorkflowId($workflowId);
        }

        return $impl;
    }
}
