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

namespace Espo\Modules\Advanced\Tools\Report;

use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Formula\Exceptions\Error as FormulaError;
use Espo\Core\Formula\Manager as FormulaManager;
use Espo\Core\InjectableFactory;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Metadata;
use Espo\Entities\Preferences;
use Espo\Modules\Advanced\Entities\Report;
use Espo\Modules\Advanced\Reports\GridReport;
use Espo\Modules\Advanced\Reports\ListReport;
use Espo\Modules\Advanced\Tools\Report\GridType\Data as GridData;
use Espo\Modules\Advanced\Tools\Report\GridType\JointData;
use Espo\Modules\Advanced\Tools\Report\ListType\Data as ListData;

use stdClass;

class ReportHelper
{
    private const WHERE_TYPE_AND = 'and';
    private const WHERE_TYPE_OR = 'or';
    private const WHERE_TYPE_HAVING = 'having';
    private const WHERE_TYPE_NOT = 'not';
    private const WHERE_TYPE_SUB_QUERY_IN =  'subQueryIn';
    private const WHERE_TYPE_SUB_QUERY_NOT_IN = 'subQueryNotIn';

    private const ATTR_HAVING = '_having';

    private Metadata $metadata;
    private InjectableFactory $injectableFactory;
    private FormulaManager $formulaManager;
    private Config $config;
    private Preferences $preferences;
    private FormulaChecker $formulaChecker;

    public function __construct(
        Metadata $metadata,
        InjectableFactory $injectableFactory,
        FormulaManager $formulaManager,
        Config $config,
        Preferences $preferences,
        FormulaChecker $formulaChecker
    ) {
        $this->metadata = $metadata;
        $this->injectableFactory = $injectableFactory;
        $this->formulaManager = $formulaManager;
        $this->config = $config;
        $this->preferences = $preferences;
        $this->formulaChecker = $formulaChecker;
    }

    /**
     * @throws Error
     * @return ListReport|GridReport
     */
    public function createInternalReport(Report $report): object
    {
        $className = $report->get('internalClassName');

        if ($className) {
            if (stripos($className, ':') !== false) {
                [$moduleName, $reportName] = explode(':', $className);

                if ($moduleName === 'Custom') {
                    $className = "Espo\\Custom\\Reports\\$reportName";
                } else {
                    $className = "Espo\\Modules\\$moduleName\\Reports\\$reportName";
                }
            }
        }

        if (!$className) {
            throw new Error('No class name specified for internal report.');
        }

        return $this->injectableFactory->create($className);
    }

    /**
     * @throws Forbidden
     */
    public function checkReportCanBeRunToRun(Report $report): void
    {
        if (
            in_array(
                $report->getTargetEntityType(),
                $this->metadata->get('entityDefs.Report.entityListToIgnore', [])
            )
        ) {
            throw new Forbidden();
        }
    }

    /**
     * @throws Error
     */
    public function fetchGridDataFromReport(Report $report): GridData
    {
        if ($report->getType() !== Report::TYPE_GRID) {
            throw new Error("Non-grid report.");
        }

        return new GridData(
            $report->getTargetEntityType(),
            $report->get('columns') ?? [],
            $report->get('groupBy') ?? [],
            $report->get('orderBy') ?? [],
            $report->get('applyAcl'),
            $this->fetchFiltersWhereFromReport($report),
            $report->get('chartType'),
            get_object_vars($report->get('chartColors') ?? (object) []),
            $report->get('chartColor'),
            $report->get('chartDataList'),
            ($report->get('data') ?? (object) [])->success ?? null,
            $report->get('columnsData') ?? (object) [],
        );
    }

    /**
     * @throws Error
     */
    public function fetchListDataFromReport(Report $report): ListData
    {
        if ($report->getType() !== Report::TYPE_LIST) {
            throw new Error("Non-list report.");
        }

        return new ListData(
            $report->getTargetEntityType(),
            $report->get('columns') ?? [],
            $report->get('orderByList'),
            $report->get('columnsData'),
            $this->fetchFiltersWhereFromReport($report)
        );
    }

    public function fetchJointDataFromReport(Report $report): JointData
    {
        if ($report->getType() !== Report::TYPE_JOINT_GRID) {
            throw new Error("Non-joint-grid report.");
        }

        return new JointData(
            $report->get('joinedReportDataList') ?? [],
            $report->get('chartType')
        );
    }

    public function fetchFiltersWhereFromReport(Report $report): ?WhereItem
    {
        $isNotList = $report->getType() !== Report::TYPE_LIST;

        $raw = $report->get('filtersData') && !$report->get('filtersDataList') ?
            $this->convertFiltersData($report->get('filtersData')) :
            $this->convertFiltersDataList($report->get('filtersDataList') ?? [], $isNotList);

        if (!$raw) {
            return null;
        }

        return WhereItem::fromRawAndGroup(json_decode(json_encode($raw), true));
    }

    /**
     * @throws Error
     */
    private function convertFiltersData(?array $filtersData): ?array
    {
        if (empty($filtersData)) {
            return null;
        }

        $arr = [];

        foreach ($filtersData as $name => $defs) {
            $field = $name;

            if (empty($defs)) {
                continue;
            }

            if (isset($defs->where)) {
                $arr[] = $defs->where;
            }
            else {
                if (isset($defs->field)) {
                    $field = $defs->field;
                }

                $type = $defs->type ?? null;

                if (!empty($defs->dateTime)) {
                    $arr[] = $this->fixDateTimeWhere(
                        $type,
                        $field,
                        $defs->value ?? null,
                        false
                    );
                }
                else {
                    $o = new stdClass();

                    $o->type = $type;
                    $o->field = $field;
                    $o->value = $defs->value;

                    $arr[] = $o;
                }
            }
        }

        return $arr;
    }

    /**
     * @throws Error
     */
    private function convertFiltersDataList(array $filtersDataList, bool $useSystemTimeZone): ?array
    {
        if (empty($filtersDataList)) {
            return null;
        }

        $arr = [];

        foreach ($filtersDataList as $defs) {
            $field = null;

            if (isset($defs->name)) {
                $field = $defs->name;
            }

            if (empty($defs) || empty($defs->params)) {
                continue;
            }

            $params = $defs->params;

            $type = $defs->type ?? null;

            if (
                in_array($type, [
                    self::WHERE_TYPE_OR,
                    self::WHERE_TYPE_AND,
                    self::WHERE_TYPE_NOT,
                    self::WHERE_TYPE_SUB_QUERY_IN,
                    self::WHERE_TYPE_SUB_QUERY_NOT_IN,
                    self::WHERE_TYPE_HAVING,
                ])
            ) {
                if (empty($params->value)) {
                    continue;
                }

                $o = new stdClass();

                $o->type = $params->type;

                if ($o->type === self::WHERE_TYPE_NOT) {
                    $o->type = self::WHERE_TYPE_SUB_QUERY_NOT_IN;
                }

                if ($o->type === self::WHERE_TYPE_HAVING) {
                    $o->type = self::WHERE_TYPE_AND;
                    $o->attribute = self::ATTR_HAVING;
                }

                $o->value = $this->convertFiltersDataList($params->value, $useSystemTimeZone);

                $arr[] = $o;

                continue;
            }

            if ($type === 'complexExpression') {
                $o = (object) [];

                $function = null;
                if (isset($params->function)) {
                    $function = $params->function;
                }

                if ($function === 'custom') {
                    if (empty($params->expression)) {
                        continue;
                    }

                    $o->attribute = $params->expression;
                    $o->type = 'expression';
                }
                else if ($function === 'customWithOperator') {
                    if (empty($params->expression)) {
                        continue;
                    }

                    if (empty($params->operator)) {
                        continue;
                    }

                    $o->attribute = $params->expression;
                    $o->type = $params->operator;
                }
                else {
                    if (empty($params->attribute)) {
                        continue;
                    }

                    if (empty($params->operator)) {
                        continue;
                    }

                    $o->attribute = $params->attribute;

                    if ($function) {
                        $o->attribute = $params->function . ':' . $o->attribute;
                    }

                    $o->type = $params->operator;
                }

                if (isset($params->value) && is_string($params->value) && strlen($params->value)) {
                    try {
                        $o->value = $this->runFormula($params->value);
                    }
                    catch (FormulaError $e) {
                        throw new Error($e->getMessage());
                    }
                }

                $arr[] = $o;

                continue;
            }

            if (isset($params->where)) {
                $arr[] = $params->where;

                continue;
            }

            if (isset($params->field)) {
                $field = $params->field;
            }

            if (empty($params->type)) {
                continue;
            }

            $type = $params->type;

            if (!empty($params->dateTime)) {
                $arr[] = $this->fixDateTimeWhere(
                    $type,
                    $field,
                    $params->value ?? null,
                    $useSystemTimeZone
                );

                continue;
            }

            $o = new stdClass();

            $o->type = $type;
            $o->field = $field;
            $o->attribute = $field;
            $o->value = $params->value ?? null;

            $arr[] = $o;
        }

        return $arr;
    }

    /**
     * @param mixed $value
     */
    private function fixDateTimeWhere(string $type, string $field, $value, bool $useSystemTimeZone): ?object
    {
        $timeZone = null;

        if (!$useSystemTimeZone) {
            $timeZone = $this->preferences->get('timeZone');
        }

        if (!$timeZone) {
            $timeZone = $this->config->get('timeZone') ?? 'UTC';
        }

        return (object) [
            'attribute' => $field,
            'type' => $type,
            'value' => $value,
            'dateTime' => true,
            'timeZone' => $timeZone,
        ];
    }

    /**
     * @throws Forbidden
     */
    public function checkRuntimeFilters(WhereItem $where, Report $report): void
    {
        $this->checkRuntimeFiltersItem($where, $report->getRuntimeFilters());
    }

    /**
     * @param string[] $allowedFilterList
     * @throws Forbidden
     */
    private function checkRuntimeFiltersItem(WhereItem $item, array $allowedFilterList): void
    {
        $type = $item->getType();

        if ($type === self::WHERE_TYPE_AND || $type === self::WHERE_TYPE_OR) {
            foreach ($item->getItemList() as $subItem) {
                $this->checkRuntimeFiltersItem($subItem, $allowedFilterList);
            }

            return;
        }

        $attribute = $item->getAttribute();

        if (!$attribute) {
            throw new Forbidden("Not allowed runtime filter item.");
        }

        if ($attribute === 'id') {
            return;
        }

        if (strpos($attribute, ':') !== false) {
            throw new Forbidden("Expressions are not allowed in runtime filter.");
        }

        if (strpos($attribute, '.') === false) {
            return;
        }

        $isAllowed = in_array($attribute, $allowedFilterList);

        if (!$isAllowed && substr($attribute, -2) === 'Id') {
            $isAllowed = in_array(substr($attribute, 0, -2), $allowedFilterList);
        }

        if (!$isAllowed) {
            throw new Forbidden("Not allowed runtime filter $attribute.");
        }
    }

    /**
     * @return mixed
     * @throws Forbidden
     */
    private function runFormula(string $script)
    {
        $this->formulaChecker->check($script);

        $script = $this->formulaChecker->sanitize($script);

        return $this->formulaManager->run($script);
    }
}
