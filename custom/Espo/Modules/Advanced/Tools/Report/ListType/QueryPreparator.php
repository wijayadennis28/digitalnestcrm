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

namespace Espo\Modules\Advanced\Tools\Report\ListType;

use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Core\Select\Where\ItemBuilder as WhereItemBuilder;
use Espo\Core\Utils\Config;
use Espo\Entities\Preferences;
use Espo\Entities\User;
use Espo\Modules\Advanced\Tools\Report\SelectHelper;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Selection;
use Espo\ORM\Query\SelectBuilder;

class QueryPreparator
{
    private SelectHelper $selectHelper;
    private SelectBuilderFactory $selectBuilderFactory;
    private Config $config;
    private EntityManager $entityManager;

    public function __construct(
        SelectHelper $selectHelper,
        SelectBuilderFactory $selectBuilderFactory,
        Config $config,
        EntityManager $entityManager
    ) {
        $this->selectHelper = $selectHelper;
        $this->selectBuilderFactory = $selectBuilderFactory;
        $this->config = $config;
        $this->entityManager = $entityManager;
    }

    /**
     * Complex expression check is not applied for search parameters as it's supposed
     * to be checked the by runtime filter checker.
     *
     * @throws Forbidden
     * @throws Error
     */
    public function prepare(
        Data $data,
        ?SearchParams $searchParams = null,
        ?User $user = null
    ): SelectBuilder {

        $searchParams = $searchParams ?? SearchParams::create();

        $orderBy = $searchParams->getOrderBy();
        $order = $searchParams->getOrder();

        if ($orderBy && strpos($orderBy, '_') !== false) {
            $searchParams = $searchParams
                ->withOrderBy(null);
        }

        if ($searchParams->getWhere() && $user) {
            $searchParams = $this->applyTimeZoneToSearchParams($searchParams, $user);
        }

        $selectBuilder = $this->selectBuilderFactory
            ->create()
            ->from($data->getEntityType())
            ->withSearchParams($searchParams->withSelect(['id']));

        if ($user) {
            $selectBuilder
                ->forUser($user)
                ->withWherePermissionCheck()
                ->withAccessControlFilter();
        }

        // Applies access control check.
        $intermediateQuery = $selectBuilder->build();

        $selectBuilder = $this->selectBuilderFactory
            ->create()
            ->from($data->getEntityType())
            ->withSearchParams($searchParams);

        if ($user) {
            $selectBuilder
                ->forUser($user)
                ->withAccessControlFilter();
        }

        $queryBuilder = $selectBuilder
            ->buildQueryBuilder()
            ->from($data->getEntityType(), lcfirst($data->getEntityType()));

        if ($data->getColumns() !== []) {
            // Add columns applied from order-by.

            $queryBuilder->select(
                // Prevent issue in ORM (as of v7.5).
                array_map(function (Selection $selection) {
                    return !$selection->getAlias() ?
                        $selection->getExpression() :
                        $selection;
                }, $intermediateQuery->getSelect())
            );

            $this->selectHelper->handleColumns($data->getColumns(), $queryBuilder);
        }

        if ($data->getFiltersWhere()) {
            [$whereItem, $havingItem] = $this->selectHelper->splitHavingItem($data->getFiltersWhere());

            $this->selectHelper->handleFiltersWhere($whereItem, $queryBuilder);
            $this->selectHelper->handleFiltersHaving($havingItem, $queryBuilder);
        }

        if ($orderBy) {
            $this->selectHelper->handleOrderByForList($orderBy, $order, $queryBuilder);
        }

        if ($searchParams->getWhere()) {
            $this->selectHelper->applyDistinctFromWhere($searchParams->getWhere(), $queryBuilder);
        }

        return $queryBuilder;
    }

    private function applyTimeZoneToSearchParams(SearchParams $searchParams, User $user): SearchParams
    {
        $where = $searchParams->getWhere();

        if (!$where) {
            return $searchParams;
        }

        return $searchParams->withWhere(
            $this->addUserTimeZoneToWhere($where, $user)
        );
    }

    private function addUserTimeZoneToWhere(WhereItem $item, User $user, ?string $timeZone = null): WhereItem
    {
        $timeZone ??= $this->getUserTimeZone($user);

        if (
            $item->getType() === WhereItem\Type::AND ||
            $item->getType() === WhereItem\Type::OR
        ) {
            $items = [];

            foreach ($item->getItemList() as $subItem) {
                $items[] = $this->addUserTimeZoneToWhere($subItem, $user, $timeZone);
            }

            return WhereItemBuilder::create()
                ->setType($item->getType())
                ->setItemList($items)
                ->build();
        }

        if (!method_exists($item, 'getData')) {
            return $item;
        }

        $data = $item->getData();

        if (!$data) {
            return $item;
        }

        if (
            !$data instanceof WhereItem\Data\DateTime &&
            !method_exists($data, 'withTimeZone')
        ) {
            return $item;
        }

        return $item->withData(
            $data->withTimeZone($timeZone)
        );
    }

    private function getUserTimeZone(User $user): string
    {
        $preferences = $this->entityManager->getEntityById(Preferences::ENTITY_TYPE, $user->getId());

        if ($preferences->get('timeZone')) {
            return $preferences->get('timeZone');
        }

        return $this->config->get('timeZone') ?? 'UTC';
    }
}
