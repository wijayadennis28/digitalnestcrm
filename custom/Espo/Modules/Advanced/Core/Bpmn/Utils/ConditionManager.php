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

namespace Espo\Modules\Advanced\Core\Bpmn\Utils;

use Espo\Core\Exceptions\Error;
use Espo\Core\Container;
use Espo\Core\Formula\Manager as FormulaManager;
use Espo\Modules\Advanced\Core\Workflow\Conditions\Base;
use Espo\ORM\Entity;

use stdClass;

class ConditionManager
{
    private ?stdClass $createdEntitiesData = null;

    private array $requiredOptionList = [
        'comparison',
        'fieldToCompare',
    ];

    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function check(
        Entity $entity,
        ?array $conditionsAll = null,
        ?array$conditionsAny = null,
        ?string $conditionsFormula = null,
        stdClass $variables = null
    ): bool {

        $result = true;

        if (!is_null($conditionsAll)) {
            $result &= $this->checkConditionsAll($entity, $conditionsAll);
        }

        if (!is_null($conditionsAny)) {
            $result &= $this->checkConditionsAny($entity, $conditionsAny);
        }

        if (!empty($conditionsFormula)) {
            $result &= $this->checkConditionsFormula($entity, $conditionsFormula, $variables);
        }

        return (bool) $result;
    }

    public function checkConditionsAll(Entity $entity, array $conditionList): bool
    {
        if (!isset($conditionList)) {
            return true;
        }

        foreach ($conditionList as $condition) {
            if (!$this->processCheck($entity, $condition)) {
                return false;
            }
        }

        return true;
    }

    public function checkConditionsAny(Entity $entity, array $conditionList): bool
    {
        if (empty($conditionList)) {
            return true;
        }

        foreach ($conditionList as $condition) {
            if ($this->processCheck($entity, $condition)) {
                return true;
            }
        }

        return false;
    }

    public function checkConditionsFormula(Entity $entity, $formula, $variables = null): bool
    {
        if (empty($formula)) {
            return true;
        }

        $formula = trim($formula, " \t\n\r");

        if (substr($formula, -1) === ';') {
            $formula = substr($formula, 0, -1);
        }

        if (empty($formula)) {
            return true;
        }

        $o = (object) [];

        $o->__targetEntity = $entity;

        if ($variables) {
            foreach (get_object_vars($variables) as $name => $value) {
                $o->$name = $value;
            }
        }

        if ($this->createdEntitiesData) {
            $o->__createdEntitiesData = $this->createdEntitiesData;
        }

        return $this->getFormulaManager()->run($formula, $entity, $o);
    }

    private function processCheck(Entity $entity, stdClass $condition): bool
    {
        if (!$this->validate($condition)) {
            return false;
        }

        $compareImpl = $this->getConditionImplementation($condition->comparison);

        return $compareImpl->process($entity, $condition, $this->createdEntitiesData);
    }

    /**
     * @throws Error
     */
    private function getConditionImplementation(string $name): Base
    {
        $name = ucfirst($name);
        $name = str_replace("\\", "", $name);

        $className = 'Espo\\Modules\\Advanced\\Core\\Workflow\\Conditions\\' . $name;

        if (!class_exists($className)) {
            $className .= 'Type';

            if (!class_exists($className)) {
                throw new Error('ConditionManager: Class ' . $className . ' does not exist.');
            }
        }

        return new $className($this->getContainer());
    }

    public function setCreatedEntitiesData(stdClass $createdEntitiesData)
    {
        $this->createdEntitiesData = $createdEntitiesData;
    }

    private function validate($options): bool
    {
        foreach ($this->requiredOptionList as $optionName) {
            if (!property_exists($options, $optionName)) {
                return false;
            }
        }

        return true;
    }

    private function getContainer(): Container
    {
        return $this->container;
    }

    private function getFormulaManager(): FormulaManager
    {
        /** @var FormulaManager */
        return $this->getContainer()->get('formulaManager');
    }
}
