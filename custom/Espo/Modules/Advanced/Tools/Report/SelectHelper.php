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

use DateTime;
use DateTimeZone;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\Where\Converter;
use Espo\Core\Select\Where\ConverterFactory;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Core\Select\Where\ItemBuilder as WhereItemBuilder;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\FieldUtil;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\Advanced\Tools\Report\GridType\Helper as GridHelper;
use Espo\Modules\Advanced\Tools\Report\GridType\Util;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Expression;
use Espo\ORM\Query\Part\Order;
use Espo\ORM\Query\Part\Selection;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\QueryComposer\Util as QueryComposerUtil;
use Exception;
use LogicException;
use RuntimeException;

class SelectHelper
{
    private const WHERE_TYPE_AND = 'and';
    private const WHERE_TYPE_OR = 'or';

    private const ATTR_HAVING = '_having';

    private Config $config;
    private Metadata $metadata;
    private Util $gridUtil;
    private EntityManager $entityManager;
    private GridHelper $gridHelper;
    private FieldUtil $fieldUtil;
    private User $user;
    private ConverterFactory $converterFactory;

    public function __construct(
        Config $config,
        Metadata $metadata,
        Util $gridUtil,
        EntityManager $entityManager,
        GridHelper $gridHelper,
        FieldUtil $fieldUtil,
        User $user,
        ConverterFactory $converterFactory
    ) {
        $this->config = $config;
        $this->metadata = $metadata;
        $this->gridUtil = $gridUtil;
        $this->entityManager = $entityManager;
        $this->gridHelper = $gridHelper;
        $this->fieldUtil = $fieldUtil;
        $this->user = $user;
        $this->converterFactory = $converterFactory;
    }

    /**
     * @return array{0: WhereItem, 1: WhereItem}
     */
    public function splitHavingItem(WhereItem $andItem): array
    {
        $whereItemList = [];
        $havingItemList = [];

        foreach ($andItem->getItemList() as $item) {
            if (
                $item->getType() === self::WHERE_TYPE_AND &&
                $item->getAttribute() === self::ATTR_HAVING
            ) {
                foreach ($item->getItemList() as $subItem) {
                    $havingItemList[] = $subItem;
                }

                continue;
            }

            $whereItemList[] = $item;
        }

        $whereItem = WhereItemBuilder::create()
            ->setType(self::WHERE_TYPE_AND)
            ->setItemList($whereItemList)
            ->build();

        $havingItem = WhereItemBuilder::create()
            ->setType(self::WHERE_TYPE_AND)
            ->setItemList($havingItemList)
            ->build();

        return [$whereItem, $havingItem];
    }

    public function handleOrderByForList(string $orderBy, string $order, SelectBuilder $queryBuilder): void
    {
        $entityType = $queryBuilder->build()->getFrom();

        if (!$entityType) {
            throw new LogicException("No from.");
        }

        $entityDefs = $this->entityManager->getDefs()->getEntity($entityType);

        $fieldType = $entityDefs->hasField($orderBy) ?
            $entityDefs->getField($orderBy)->getType() :
            null;

        if (
            in_array($fieldType, ['link', 'file', 'image']) &&
            !$queryBuilder->hasLeftJoinAlias($orderBy)
        ) {
            $queryBuilder->leftJoin($orderBy);
        }

        if (strpos($orderBy, '_') !== false) {
            if (strpos($orderBy, ':') !== false) {
                throw new Forbidden("Functions are not allowed in orderBy.");
            }

            $orderBy = $this->getRealForeignOrderColumn($entityType, $orderBy);

            $this->addSelect($orderBy, $queryBuilder);

            $queryBuilder
                ->order([])
                ->order($orderBy, $order)
                ->order('id', $order);

            return;
        }

        foreach ($this->fieldUtil->getAttributeList($entityType, $orderBy) as $attribute) {
            if (!$entityDefs->hasAttribute($attribute)) {
                continue;
            }

            $this->addSelect($attribute, $queryBuilder);
        }
    }

    private function getRealForeignOrderColumn(string $entityType, string $item): string
    {
        $item = str_replace('_', '.', $item);

        $data = $this->gridHelper->getDataFromColumnName($entityType, $item);

        if (!$data->entityType) {
            throw new RuntimeException("Bad foreign order by '$item'.");
        }

        if (in_array($data->fieldType, ['link', 'linkParent', 'image', 'file'])) {
            return $item . 'Id';
        }

        return $item;
    }

    /**
     * @param string[] $groupBy
     */
    public function handleGroupBy(array $groupBy, SelectBuilder $queryBuilder): void
    {
        $entityType = $queryBuilder->build()->getFrom();

        if (!$entityType) {
            throw new LogicException("No from.");
        }

        foreach ($groupBy as $item) {
            $this->handleGroupByItem($item, $entityType, $queryBuilder);
        }
    }

    private function handleGroupByItem(string $item, string $entityType, SelectBuilder $queryBuilder): void
    {
        $alias = $this->gridUtil->sanitizeSelectAlias($item);

        $function = null;
        $argument = $item;

        if (strpos($item, ':') !== false) {
            [$function, $argument] = explode(':', $item);
        }

        if (strpos($item, '(') !== false && strpos($item, ':') !== false) {
            $this->handleLeftJoins($item, $entityType, $queryBuilder, true);

            $queryBuilder
                ->select($item, $alias)
                ->group($item);

            return;
        }

        if ($function === 'YEAR_FISCAL') {
            $fiscalYearShift = $this->config->get('fiscalYearShift', 0);

            $function = $fiscalYearShift ?
                'YEAR_' . $fiscalYearShift :
                'YEAR';

            $item = $function . ':' . $argument;
        }
        else if ($function === 'QUARTER_FISCAL') {
            $fiscalYearShift = $this->config->get('fiscalYearShift', 0);

            $function = $fiscalYearShift ?
                'QUARTER_' . $fiscalYearShift :
                'QUARTER';

            $item = $function . ':' . $argument;
        }
        else if ($function === 'WEEK') {
            $function = $this->config->get('weekStart') ?
                'WEEK_1' :
                'WEEK_0';

            $item = $function . ':' . $argument;
        }

        if (strpos($item, '.') === false) {
            $fieldType = $this->metadata->get(['entityDefs', $entityType, 'fields', $argument, 'type']);

            if (in_array($fieldType, ['link', 'file', 'image'])) {
                if (!$queryBuilder->hasLeftJoinAlias($item)) {
                    $queryBuilder->leftJoin($item);
                }

                $queryBuilder
                    ->select($item . 'Id')
                    ->group($item . 'Id');

                return;
            }

            if ($fieldType === 'linkParent') {
                if (!$queryBuilder->hasLeftJoinAlias($item)) {
                    // @todo Revise
                    $queryBuilder->leftJoin($item);
                }

                $queryBuilder
                    ->select($item . 'Id')
                    ->select($item . 'Type')
                    ->group($item . 'Id')
                    ->group($item . 'Type');

                return;
            }

            if ($function && in_array($fieldType, ['datetime', 'datetimeOptional'])) {
                $tzOffset = (string) $this->getTimeZoneOffset();

                if ($tzOffset) {
                    $groupBy = "$function:TZ:($argument,$tzOffset)";

                    $queryBuilder
                        ->select($groupBy)
                        ->group($groupBy);

                    return;
                }

                $queryBuilder
                    ->select($item)
                    ->group($item);

                return;
            }

            $queryBuilder
                ->select($item)
                ->group($item);

            return;
        }

        [$link, $field] = explode('.', $argument);

        $skipSelect = false;

        $entityDefs = $this->entityManager->getDefs()->getEntity($entityType);

        if ($entityDefs->hasRelation($link)) {
            $relationType = $entityDefs->getRelation($link)->getType();

            $foreignEntityType = $entityDefs->getRelation($link)->hasForeignEntityType() ?
                $entityDefs->getRelation($link)->getForeignEntityType() : null;

            $foreignEntityDefs = $this->entityManager->getDefs()->getEntity($foreignEntityType);

            $foreignFieldType = $foreignEntityDefs->hasField($field) ?
                $foreignEntityDefs->getField($field)->getType() : null;

            if ($foreignEntityDefs->hasRelation($field)) {
                $foreignRelationType = $foreignEntityDefs->getRelation($field)->getType();

                if (
                    (
                        $relationType === Entity::BELONGS_TO ||
                        $relationType === Entity::HAS_ONE
                    ) &&
                    $foreignRelationType === Entity::BELONGS_TO
                ) {
                    $queryBuilder
                        ->select($item . 'Id')
                        ->group($item . 'Id');

                    $skipSelect = true;
                }
            }

            if ($function && in_array($foreignFieldType, ['datetime', 'datetimeOptional'])) {
                $tzOffset = (string) $this->getTimeZoneOffset();

                if ($tzOffset) {
                    $skipSelect = true;

                    $groupBy =  "$function:TZ:($link.$field,$tzOffset)";

                    $queryBuilder
                        ->select($groupBy)
                        ->group($groupBy);
                }
            }
        }

        $this->handleLeftJoins($item, $entityType, $queryBuilder, true);

        if ($skipSelect) {
            return;
        }

        $queryBuilder
            ->select($item)
            ->group($item);
    }

    private function handleLeftJoins(
        string $item,
        string $entityType,
        SelectBuilder $queryBuilder,
        bool $skipDistinct = false
    ): void {

        if (strpos($item, ':') !== false) {
            $argumentList = QueryComposerUtil::getAllAttributesFromComplexExpression($item);

            foreach ($argumentList as $argument) {
                $this->handleLeftJoins($argument, $entityType, $queryBuilder, $skipDistinct);
            }

            return;
        }

        $entityDefs = $this->entityManager
            ->getDefs()
            ->getEntity($entityType);

        if (strpos($item, '.') !== false) {
            [$relation,] = explode('.', $item);

            if ($queryBuilder->hasLeftJoinAlias($relation)) {
                return;
            }

            $queryBuilder->leftJoin($relation);

            if (!$entityDefs->hasRelation($relation)) {
                return;
            }

            $relationType = $entityDefs->getRelation($relation)->getType();

            if (
                !$skipDistinct &&
                in_array($relationType, [Entity::HAS_MANY, Entity::MANY_MANY, Entity::HAS_CHILDREN])
            ) {
                $queryBuilder->distinct();
            }

            return;
        }

        if (!$entityDefs->hasAttribute($item)) {
            return;
        }

        $attributeDefs = $entityDefs->getAttribute($item);

        if ($attributeDefs->getType() === Entity::FOREIGN) {
            $relation = $attributeDefs->getParam('relation');

            if ($relation && !$queryBuilder->hasLeftJoinAlias($relation)) {
                $queryBuilder->leftJoin($relation);
            }
        }
    }

    /**
     * @param string[] $columns
     */
    public function handleColumns(array $columns, SelectBuilder $queryBuilder): void
    {
        $entityType = $queryBuilder->build()->getFrom();

        if (!$entityType) {
            throw new LogicException("No from.");
        }

        foreach ($columns as $item) {
            $this->handleColumnsItem($item, $entityType, $queryBuilder);
        }
    }

    /**
     * @todo Use the selectDefs attribute dependency map.
     */
    private function handleColumnsItem(
        string $item,
        string $entityType,
        SelectBuilder $queryBuilder
    ): void {

        $columnData = $this->gridHelper->getDataFromColumnName($entityType, $item);

        if ($columnData->function && !$columnData->link && $columnData->field) {
            $this->addSelect($item, $queryBuilder);

            return;
        }

        if ($columnData->link) {
            $this->handleLeftJoins($item, $entityType, $queryBuilder);

            if (in_array($columnData->fieldType, ['link', 'file', 'image'])) {
                $this->addSelect($item . 'Id', $queryBuilder);

                return;
            }

            $this->addSelect($item, $queryBuilder);

            return;
        }

        if (str_contains($item, ':') && str_contains($item, '.')) {
            $this->handleLeftJoins($item, $entityType, $queryBuilder);
        }

        $type = $columnData->fieldType;

        if (in_array($type, ['link', 'file', 'image', 'linkOne'])) {
            $this->addSelect($item . 'Name', $queryBuilder);
            $this->addSelect($item . 'Id', $queryBuilder);

            if (!$queryBuilder->hasLeftJoinAlias($item)) {
                $queryBuilder->leftJoin($item);
            }

            return;
        }

        if ($type === 'linkParent') {
            $this->addSelect($item . 'Type', $queryBuilder);
            $this->addSelect($item . 'Id', $queryBuilder);

            return;
        }

        if ($type === 'currency') {
            $this->addSelect($item, $queryBuilder);
            $this->addSelect($item . 'Currency', $queryBuilder);
            $this->addSelect($item . 'Converted', $queryBuilder);

            return;
        }

        if ($type === 'duration') {
            $start = $this->metadata->get(['entityDefs', $entityType, 'fields', $item, 'start']);
            $end = $this->metadata->get(['entityDefs', $entityType , 'fields', $item, 'end']);

            $this->addSelect($start, $queryBuilder);
            $this->addSelect($end, $queryBuilder);
            $this->addSelect($item, $queryBuilder);

            return;
        }

        if ($type === 'personName') {
            $this->addSelect($item, $queryBuilder);
            $this->addSelect('first' . ucfirst($item), $queryBuilder);
            $this->addSelect('last' . ucfirst($item), $queryBuilder);

            return;
        }

        if ($type === 'address') {
            $pList = ['city', 'country', 'postalCode', 'street', 'state'];

            foreach ($pList as $p) {
                $this->addSelect($item . ucfirst($p), $queryBuilder);
            }

            return;
        }

        if ($type === 'datetimeOptional') {
            $this->addSelect($item, $queryBuilder);
            $this->addSelect($item . 'Date', $queryBuilder);

            return;
        }

        if ($type === 'linkMultiple' || $type === 'attachmentMultiple') {
            return;
        }

        $this->addSelect($item, $queryBuilder);
    }

    private function isInSelect(string $item, SelectBuilder $queryBuilder): bool
    {
        $currentList = array_map(
            function (Selection $selection): string {
                return $selection->getExpression()->getValue();
            },
            $queryBuilder->build()->getSelect()
        );

        return in_array($item, $currentList);
    }

    private function addSelect(string $item, SelectBuilder $queryBuilder): void
    {
        if ($this->isInSelect($item, $queryBuilder)) {
            return;
        }

        $alias = $this->gridUtil->sanitizeSelectAlias($item);

        $queryBuilder->select($item, $alias);
    }

    /**
     * @param string[] $orderBy
     */
    public function handleOrderBy(array $orderBy, SelectBuilder $queryBuilder): void
    {
        $entityType = $queryBuilder->build()->getFrom();

        foreach ($orderBy as $item) {
            $this->handleOrderByItem($item, $entityType, $queryBuilder);
        }
    }

    private function handleOrderByItem(string $item, string $entityType, SelectBuilder $queryBuilder): void
    {
        $entityDefs = $this->entityManager->getDefs()->getEntity($entityType);

        if (strpos($item, 'LIST:') !== false) {
            // @todo Check is actual as processed afterwards.

            $orderBy = substr($item, 5);

            if (strpos($orderBy, '.') !== false) {
                [$rel, $field] = explode('.', $orderBy);

                if (!$entityDefs->hasRelation($rel)) {
                    return;
                }

                $relationDefs = $entityDefs->getRelation($rel);

                $foreignEntityType = $relationDefs->hasForeignEntityType() ?
                    $relationDefs->getForeignEntityType() : null;

                if (!$foreignEntityType) {
                    return;
                }

                $optionList = $this->metadata
                    ->get(['entityDefs', $foreignEntityType, 'fields', $field, 'options']) ?? [];
            }
            else {
                $optionList = $this->metadata->get(['entityDefs', $entityType, 'fields', $orderBy, 'options']) ?? [];
            }

            if (!$optionList) {
                return;
            }

            $queryBuilder->order(
                Order::createByPositionInList(Expression::column($orderBy), $optionList)
            );

            return;
        }

        if (strpos($item, 'ASC:') !== false) {
            $orderBy = substr($item, 4);
            $order = 'ASC';
        }
        else if (strpos($item, 'DESC:') !== false) {
            $orderBy = substr($item, 5);
            $order = 'DESC';
        }
        else {
            return;
        }

        $field = $orderBy;
        $orderEntityType = $entityType;
        $link = null;

        if (strpos($orderBy, '.') !== false) {
            [$link, $field] = explode('.', $orderBy);

            if (!$entityDefs->hasRelation($link)) {
                return;
            }

            $relationDefs = $entityDefs->getRelation($link);

            $orderEntityType = $relationDefs->hasForeignEntityType() ?
                $relationDefs->getForeignEntityType() : null;

            if (!$orderEntityType) {
                return;
            }
        }

        $entityDefs = $this->entityManager->getDefs()->getEntity($orderEntityType);

        $fieldType = $entityDefs->hasField($field) ?
            $entityDefs->getField($field)->getType() : null;

        if (in_array($fieldType, ['link', 'file', 'image'])) {
            /*if ($link) {
                continue;
            }*/

            // MariaDB issue with ONLY_FULL_GROUP_BY.
            /*$orderBy = $orderBy . 'Name';

            if (!in_array($orderBy, $params['select'])) {
                $params['select'][] = $orderBy;
            }*/

            return;
        }

        if ($fieldType === 'linkParent') {
            if ($link) {
                return;
            }

            $orderBy = $orderBy . 'Type';
        }

        if (!$this->isInSelect($orderBy, $queryBuilder)) {
            return;
        }

        $queryBuilder->order($orderBy, $order);
    }
    /**
     * @throws Forbidden
     */
    public function handleFiltersWhere(
        WhereItem $whereItem,
        SelectBuilder $queryBuilder,
        bool $isGrid = false
    ): void {
        $entityType = $queryBuilder->build()->getFrom();

        if (!$entityType) {
            throw new LogicException("No from.");
        }

        $this->applyLeftJoinsFromWhere($whereItem, $queryBuilder);

        $queryBuilder->where(
            $this->createConverter($entityType)->convert($queryBuilder, $whereItem)
        );

        if (!$isGrid) {
            // Distinct is already supposed to be applied by the scanner.
            $this->applyDistinctFromWhere($whereItem, $queryBuilder);
        }
    }

    /**
     * @throws Forbidden
     */
    public function handleFiltersHaving(
        WhereItem $havingItem,
        SelectBuilder $queryBuilder,
        bool $isGrid = false
    ): void {
        $entityType = $queryBuilder->build()->getFrom();

        if (!$entityType) {
            throw new LogicException("No from.");
        }

        if ($havingItem->getItemList() === []) {
            return;
        }

        $havingClause = $this->createConverter($entityType)->convert($queryBuilder, $havingItem);

        if ($isGrid) {
            $this->applyLeftJoinsFromWhere($havingItem, $queryBuilder);

            $queryBuilder->having($havingClause);

            return;
        }

        $subQueryBuilder = SelectBuilder::create()
            ->from($entityType, lcfirst($entityType))
            ->select('id')
            ->group('id')
            ->having($havingClause);

        $this->applyLeftJoinsFromWhere($havingItem, $subQueryBuilder);

        $queryBuilder->where(['id=s' => $subQueryBuilder->build()->getRaw()]);
    }

    public function applyLeftJoinsFromWhere(WhereItem $item, SelectBuilder $queryBuilder): void
    {
        $entityType = $queryBuilder->build()->getFrom();

        if (!$entityType) {
            throw new LogicException();
        }

        if ($queryBuilder->build()->isDistinct()) {
            return;
        }

        if (in_array($item->getType(), [self::WHERE_TYPE_OR, self::WHERE_TYPE_AND])) {
            foreach ($item->getItemList() as $listItem) {
                $this->applyLeftJoinsFromWhere($listItem, $queryBuilder);
            }

            return;
        }

        if (!$item->getAttribute()) {
            return;
        }

        $this->handleLeftJoins($item->getAttribute(), $entityType, $queryBuilder, true);
    }

    public function applyDistinctFromWhere(WhereItem $item, SelectBuilder $queryBuilder): void
    {
        $entityType = $queryBuilder->build()->getFrom();

        if (!$entityType) {
            throw new LogicException();
        }

        if ($queryBuilder->build()->isDistinct()) {
            return;
        }

        if (in_array($item->getType(), [self::WHERE_TYPE_OR, self::WHERE_TYPE_AND])) {
            foreach ($item->getItemList() as $listItem) {
                $this->applyDistinctFromWhere($listItem, $queryBuilder);
            }

            return;
        }

        if (!$item->getAttribute()) {
            return;
        }

        $this->handleDistinct($item->getAttribute(), $entityType, $queryBuilder);
    }

    private function handleDistinct(string $item, string $entityType, SelectBuilder $queryBuilder): void
    {
        if (strpos($item, ':') !== false) {
            $argumentList = QueryComposerUtil::getAllAttributesFromComplexExpression($item);

            foreach ($argumentList as $argument) {
                $this->handleDistinct($argument, $entityType, $queryBuilder);
            }

            return;
        }

        if (strpos($item, '.') === false) {
            return;
        }

        [$relation,] = explode('.', $item);

        $entityDefs = $this->entityManager->getDefs()->getEntity($entityType);

        if (!$entityDefs->hasRelation($relation)) {
            return;
        }

        $relationsDefs = $entityDefs->getRelation($relation);

        if (in_array($relationsDefs->getType(), [Entity::HAS_MANY, Entity::MANY_MANY])) {
            $queryBuilder->distinct();
        }
    }

    /**
     * @return float|int
     */
    private function getTimeZoneOffset()
    {
        $timeZone = $this->config->get('timeZone', 'UTC');

        if ($timeZone === 'UTC') {
            return 0;
        }

        try {
            $dateTimeZone = new DateTimeZone($timeZone);
            $dateTime = new DateTime('now', $dateTimeZone);

            $dateTime->modify('first day of january');
            $tzOffset = $dateTimeZone->getOffset($dateTime) / 3600;
        }
        catch (Exception $e) {
            return 0;
        }

        return $tzOffset;
    }

    private function createConverter(string $entityType): Converter
    {
        return $this->converterFactory->create($entityType, $this->user);
    }
}
