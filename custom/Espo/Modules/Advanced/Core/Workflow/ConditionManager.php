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

namespace Espo\Modules\Advanced\Core\Workflow;

use Espo\Core\Exceptions\Error;
use Espo\Core\Formula\Exceptions\Error as FormulaException;
use Espo\Core\Formula\Manager as FormulaManager;

use stdClass;

class ConditionManager extends BaseManager
{
    protected string $dirName = 'Conditions';
    protected array $requiredOptions = [
        'comparison',
        'fieldToCompare',
    ];

    /**
     * @param ?stdClass[] $conditionsAll
     * @param ?stdClass[] $conditionsAny
     */
    public function check(
        ?array $conditionsAll = null,
        ?array$conditionsAny = null,
        ?string $conditionsFormula = null
    ): bool {

        $result = true;

        if (!is_null($conditionsAll)) {
            $result &= $this->checkConditionsAll($conditionsAll);
        }

        if (!is_null($conditionsAny)) {
            $result &= $this->checkConditionsAny($conditionsAny);
        }

        if (!empty($conditionsFormula)) {
            $result &= $this->checkConditionsFormula($conditionsFormula);
        }

        return $result;
    }

    /**
     * @param stdClass[] $conditions
     */
    public function checkConditionsAny(array $conditions): bool
    {
        if (empty($conditions)) {
            return true;
        }

        foreach ($conditions as $condition) {
            if ($this->processCheck($condition)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param stdClass[] $conditions
     * @throws Error
     */
    public function checkConditionsAll(array $conditions): bool
    {
        if (!isset($conditions)) {
            return true;
        }

        foreach ($conditions as $condition) {
            if (!$this->processCheck($condition)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $variables Formula variables to pass.
     * @throws Error
     * @throws FormulaException
     */
    public function checkConditionsFormula(?string $formula, array $variables = []): bool
    {
        if (empty($formula)) {
            return true;
        }

        $formula = trim($formula, " \t\n\r");

        if (empty($formula)) {
            return true;
        }

        if (substr($formula, -1) === ';') {
            $formula = substr($formula, 0, -1);
        }

        if (empty($formula)) {
            return true;
        }

        $actualVariables = (object) [];

        $actualVariables->__targetEntity = $this->getEntity();

        foreach ($variables as $key => $value) {
            $actualVariables->$key = $value;
        }

        return (bool) $this->getFormulaManager()->run($formula, $this->getEntity(), $actualVariables);
    }

    /**
     * @throws Error
     */
    private function processCheck(stdClass $condition): bool
    {
        $entity = $this->getEntity();
        $entityType = $entity->getEntityType();

        if (!$this->validate($condition)) {
            $this->getLog()->warning(
                'Workflow['.$this->getWorkflowId().']: Condition data is broken for the Entity ['.$entityType.'].');

            return false;
        }

        $impl = $this->getImpl($condition->comparison);

        return $impl->process($entity, $condition);
    }

    private function getFormulaManager(): FormulaManager
    {
        /** @var FormulaManager */
        return $this->getContainer()->get('formulaManager');
    }
}
