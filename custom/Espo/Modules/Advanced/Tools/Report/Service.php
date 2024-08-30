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

use Espo\Core\Acl\Table as AclTable;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\FieldProcessing\ListLoadProcessor;
use Espo\Core\FieldProcessing\Loader\Params as LoaderParams;
use Espo\Core\InjectableFactory;
use Espo\Core\Record\ServiceContainer as RecordServiceContainer;
use Espo\Core\Select\SearchParams;
use Espo\Core\Utils\Acl\UserAclManagerProvider;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\Advanced\Core\ORM\CustomEntityFactory;
use Espo\Modules\Advanced\Core\ORM\SthCollection;
use Espo\Modules\Advanced\Entities\Report;
use Espo\Modules\Advanced\Reports\GridReport;
use Espo\Modules\Advanced\Reports\ListReport;
use Espo\Modules\Advanced\Tools\Report\GridType\GridBuilder;
use Espo\Modules\Advanced\Tools\Report\GridType\Helper as GridHelper;
use Espo\Modules\Advanced\Tools\Report\GridType\Data as GridData;
use Espo\Modules\Advanced\Tools\Report\GridType\QueryPreparator as GridQueryPreparator;
use Espo\Modules\Advanced\Tools\Report\GridType\Result as GridResult;
use Espo\Modules\Advanced\Tools\Report\GridType\ResultHelper;
use Espo\Modules\Advanced\Tools\Report\GridType\RunParams as GridRunParams;
use Espo\Modules\Advanced\Tools\Report\GridType\Util as GridUtil;
use Espo\Modules\Advanced\Tools\Report\ListType\Data as ListData;
use Espo\Modules\Advanced\Tools\Report\ListType\QueryPreparator as ListQueryPreparator;
use Espo\Modules\Advanced\Tools\Report\ListType\Result as ListResult;
use Espo\Modules\Advanced\Tools\Report\ListType\RunParams as ListRunParams;
use Espo\Modules\Advanced\Tools\Report\ListType\SubListQueryPreparator;
use Espo\Modules\Advanced\Tools\Report\ListType\SubReportParams;
use Espo\Modules\Advanced\Tools\Report\ListType\SubReportQueryPreparator;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Expression;
use Espo\Core\Select\Where\Item as WhereItem;

use Espo\ORM\Query\SelectBuilder;
use Exception;
use PDOException;
use PDO;

class Service
{
    private const GRID_SUB_LIST_LIMIT = 500;

    private ?CustomEntityFactory $customEntityFactory = null;

    private EntityManager $entityManager;
    private Metadata $metadata;
    private Config $config;
    private User $user;
    private InjectableFactory $injectableFactory;
    private RecordServiceContainer $recordServiceContainer;
    private ResultHelper $gridResultHelper;
    private GridHelper $gridHelper;
    private GridBuilder $gridBuilder;
    private GridUtil $gridUtil;
    private ReportHelper $reportHelper;
    private ListQueryPreparator $listQueryPreparator;
    private SubReportQueryPreparator $subReportQueryPreparator;
    private ListLoadProcessor $listLoadProcessor;
    private Log $log;
    private GridQueryPreparator $gridQueryPreparator;
    private SubListQueryPreparator $subListQueryPreparator;
    private UserAclManagerProvider $userAclManagerProvider;

    public function __construct(
        EntityManager $entityManager,
        Metadata $metadata,
        Config $config,
        User $user,
        InjectableFactory $injectableFactory,
        UserAclManagerProvider $userAclManagerProvider,
        RecordServiceContainer $recordServiceContainer,
        ResultHelper $gridResultHelper,
        GridHelper $gridHelper,
        GridBuilder $gridBuilder,
        GridUtil $gridUtil,
        ReportHelper $reportHelper,
        ListQueryPreparator $listQueryPreparator,
        SubReportQueryPreparator $subReportQueryPreparator,
        ListLoadProcessor $listLoadProcessor,
        Log $log,
        GridQueryPreparator $gridQueryPreparator,
        SubListQueryPreparator $subListQueryPreparator
    ) {
        $this->entityManager = $entityManager;
        $this->metadata = $metadata;
        $this->config = $config;
        $this->user = $user;
        $this->injectableFactory = $injectableFactory;
        $this->userAclManagerProvider = $userAclManagerProvider;
        $this->recordServiceContainer = $recordServiceContainer;
        $this->gridResultHelper = $gridResultHelper;
        $this->gridHelper = $gridHelper;
        $this->gridBuilder = $gridBuilder;
        $this->gridUtil = $gridUtil;
        $this->reportHelper = $reportHelper;
        $this->listQueryPreparator = $listQueryPreparator;
        $this->subReportQueryPreparator = $subReportQueryPreparator;
        $this->listLoadProcessor = $listLoadProcessor;
        $this->log = $log;
        $this->gridQueryPreparator = $gridQueryPreparator;
        $this->subListQueryPreparator = $subListQueryPreparator;
    }

    /**
     * Fetch a report. Access control is applied if a user is passed.
     *
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    private function fetchReportForRun(string $id, ?User $user = null): Report
    {
        /** @var ?Report $report */
        $report = $this->entityManager->getEntityById(Report::ENTITY_TYPE, $id);

        if (!$report) {
            throw new NotFound("Report $id not found.");
        }

        $this->reportHelper->checkReportCanBeRunToRun($report);

        if (!$user) {
            return $report;
        }

        $aclManager = $this->userAclManagerProvider->get($user);

        if (!$aclManager->checkEntity($user, $report)) {
            throw new Forbidden("No access to report $id for user {$user->getId()}.");
        }

        $entityType = $report->getTargetEntityType();

        if (
            !$aclManager->checkScope($user, $entityType, AclTable::ACTION_READ) &&
            !$user->isPortal() // @todo Revise.
        ) {
            throw new Forbidden("No 'read' access to $entityType.");
        }

        return $report;
    }

    /**
     * Run a list report. Access control is applied if a user is passed.
     *
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function runList(
        string $id,
        ?SearchParams $searchParams = null,
        ?User $user = null,
        ?ListRunParams $runParams = null
    ): ListResult {

        $runParams = $runParams ?? ListRunParams::create();

        $report = $this->fetchReportForRun($id, $user);

        if ($report->isInternal()) {
            $impl = $this->reportHelper->createInternalReport($report);

            if (!$impl instanceof ListReport) {
                throw new Error("Bad report class.");
            }

            return $impl->run($searchParams, $user);
        }

        if ($report->getType() !== Report::TYPE_LIST) {
            throw new Error("Can't run non-List report as List.");
        }

        if (!$report->getTargetEntityType()) {
            throw new Error("No entity type in report $id.");
        }

        if (
            $searchParams &&
            $searchParams->getWhere() &&
            !$runParams->skipRuntimeFiltersCheck()
        ) {
            $this->reportHelper->checkRuntimeFilters($searchParams->getWhere(), $report);
        }

        return $this->executeListReport(
            $this->reportHelper->fetchListDataFromReport($report),
            $searchParams,
            $runParams,
            $user
        );
    }

    /**
     * Run a sub-report list. Access control is applied if a user is passed.
     *
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function runSubReportList(
        string $id,
        SearchParams $searchParams,
        SubReportParams $subReportParams,
        ?User $user = null,
        ?ListRunParams $runParams = null
    ): ListResult {

        $report = $this->fetchReportForRun($id, $user);

        if ($report->isInternal()) {
            $impl = $this->reportHelper->createInternalReport($report);

            if (!$impl instanceof GridReport) {
                throw new Error("Bad report class.");
            }

            return $impl->runSubReport($searchParams, $subReportParams, $user);
        }

        if (!in_array($report->getType(), [Report::TYPE_GRID, Report::TYPE_JOINT_GRID])) {
            throw new Error("Can't run sub-report for non-Grid report.");
        }

        if (!$report->getTargetEntityType()) {
            throw new Error("No entity type in report $id.");
        }

        if (
            $searchParams->getWhere() &&
            (!$runParams || !$runParams->skipRuntimeFiltersCheck())
        ) {
            $this->reportHelper->checkRuntimeFilters($searchParams->getWhere(), $report);
        }

        return $this->executeSubReportList(
            $this->reportHelper->fetchGridDataFromReport($report),
            $searchParams,
            $subReportParams,
            $runParams,
            $user
        );
    }

    /**
     * Run a grid or joint-grid report. Access control is applied if a user is passed.
     *
     * @param ?array<string, ?WhereItem> $idWhereMap
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function runGrid(
        string $id,
        ?WhereItem $whereItem = null,
        ?User $user = null,
        GridRunParams $runParams = null,
        ?array $idWhereMap = null
    ): GridResult {

        return $this->runGridOrJoint(
            $id,
            $whereItem,
            $user,
            $runParams,
            $idWhereMap
        );
    }

    /**
     * Access control is applied if a user is passed.
     *
     * @param ?array<string, ?WhereItem> $idWhereMap
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    private function runGridOrJoint(
        string $id,
        ?WhereItem $whereItem = null,
        ?User $user = null,
        ?GridRunParams $runParams = null,
        ?array $idWhereMap = null
    ): GridResult {

        $report = $this->fetchReportForRun($id, $user);

        if ($report->isInternal()) {
            $impl = $this->reportHelper->createInternalReport($report);

            if (!$impl instanceof GridReport) {
                throw new Error("Bad report class.");
            }

            return $impl->run($whereItem, $user);
        }

        if (
            $whereItem &&
            (!$runParams || !$runParams->skipRuntimeFiltersCheck())
        ) {
            $this->reportHelper->checkRuntimeFilters($whereItem, $report);
        }

        switch ($report->getType()) {
            case Report::TYPE_GRID:

                return $this->executeGridReport(
                    $this->reportHelper->fetchGridDataFromReport($report),
                    $whereItem,
                    $user
                );

            case Report::TYPE_JOINT_GRID:

                return $this->injectableFactory
                    ->createWith(JointGridExecutor::class, ['service' => $this])
                    ->execute(
                        $this->reportHelper->fetchJointDataFromReport($report),
                        $user,
                        $idWhereMap
                    );
        }

        throw new Error("Unknown type.");
    }

    private function getForeignFieldType(string $entityType, string $link, string $field): ?string
    {
        $defs = $this->entityManager->getMetadata()->get($entityType);

        if (!empty($defs['relations']) && !empty($defs['relations'][$link])) {
            $foreignScope = $defs['relations'][$link]['entity'];

            return $this->metadata->get(['entityDefs', $foreignScope, 'fields', $field, 'type']);
        }

        return null;
    }

    private function getForeignAttributeType(string $entityType, string $link, string $attribute): ?string
    {
        $metadata = $this->entityManager->getMetadata();

        $defs = $metadata->get($entityType);

        if (empty($defs['relations']) || empty($defs['relations'][$link])) {
            return null;
        }

        $foreignEntityType = $defs['relations'][$link]['entity'] ?? null;

        if (!$foreignEntityType) {
            return null;
        }

        return $metadata->get($foreignEntityType, ['attributes', $attribute, 'type']) ??
            $metadata->get($foreignEntityType, ['fields', $attribute, 'type']);
    }

    /**
     * @throws Forbidden
     * @throws Error
     */
    public function prepareSelectBuilder(Report $report, ?User $user = null): SelectBuilder
    {
        $data = $this->reportHelper->fetchListDataFromReport($report);

        return $this->listQueryPreparator->prepare($data, null, $user);
    }

    /**
     * @throws Forbidden
     * @throws Error
     */
    private function executeListReport(
        ListData $data,
        ?SearchParams $searchParams = null,
        ?ListRunParams $runParams = null,
        ?User $user = null
    ): ListResult {

        $entityType = $data->getEntityType();

        $searchParams = $searchParams ?? SearchParams::create();
        $runParams = $runParams ?? ListRunParams::create();

        if ($runParams->getCustomColumnList()) {
            $initialColumnList = $data->getColumns();

            $newColumnList = [];

            foreach ($runParams->getCustomColumnList() as $item) {
                if (strpos($item, '.') !== false) {
                    if (!in_array($item, $initialColumnList)) {
                        break;
                    }
                }

                $newColumnList[] = $item;
            }

            $data = $data->withColumns($newColumnList);
        }

        if (!$searchParams->getOrderBy()) {
            if ($data->getOrderBy()) {
                [$order, $orderBy] = explode(':', $data->getOrderBy());
            }
            else {
                $orderBy = $this->metadata->get(['entityDefs', $entityType, 'collection', 'orderBy']);
                $order = $this->metadata->get(['entityDefs', $entityType, 'collection', 'order']);
            }

            if ($orderBy) {
                $searchParams = $searchParams
                    ->withOrderBy($orderBy)
                    ->withOrder(strtoupper($order));
            }
        }

        $queryBuilder = $this->listQueryPreparator->prepare($data, $searchParams, $user);

        if ($runParams->isFullSelect()) {
            $queryBuilder->select(['*']);
        }

        $additionalAttributeDefs = [];
        $linkMultipleFieldList = [];
        $foreignLinkFieldDataList = [];

        foreach ($data->getColumns() as $column) {
            if (strpos($column, '.') === false) {
                $fieldType = $this->metadata->get(['entityDefs', $entityType, 'fields', $column, 'type']);

                if (in_array($fieldType, ['linkMultiple', 'attachmentMultiple'])) {
                    $linkMultipleFieldList[] = $column;
                }

                continue;
            }

            $arr = explode('.', $column);
            $link = $arr[0];
            $attribute = $arr[1];

            $foreignAttributeType = $this->getForeignAttributeType($entityType, $link, $attribute);
            $foreignAttribute = $link . '_' . $attribute;
            $foreignType = $this->getForeignFieldType($entityType, $link, $attribute);

            if (in_array($foreignType, ['image', 'file', 'link'])) {
                $additionalAttributeDefs[$foreignAttribute . 'Id'] = [
                    'type' => 'foreign'
                ];

                if ($foreignType === 'link') {
                    $additionalAttributeDefs[$foreignAttribute . 'Name'] = [
                        'type' => 'varchar'
                    ];

                    $foreignEntityType = $this->getForeignLinkForeignEntityType($entityType, $link, $attribute);

                    if ($foreignEntityType) {
                        $foreignLinkFieldDataList[] = (object) [
                            'name' => $foreignAttribute,
                            'entityType' => $foreignEntityType
                        ];
                    }
                }
            }
            else {
                $additionalAttributeDefs[$foreignAttribute] = [
                    'type' => $foreignAttributeType,
                    'relation' => $link,
                    'foreign' => $attribute,
                ];
            }
        }

        $query = $queryBuilder->build();

        try {
            $sth = $this->entityManager
                ->getQueryExecutor()
                ->execute($query);
        }
        catch (PDOException $e) {
            $this->handlePDOException($e);
        }
        catch (Exception $e) {
            $this->handleExecuteQueryException($e);
        }

        $count = $this->entityManager
            ->getRDBRepository($entityType)
            ->clone($query)
            ->count();

        $collection = $this->entityManager
            ->getCollectionFactory()
            ->create($entityType);

        $entityDefs = $this->entityManager->getMetadata()->get($entityType) ?? [];
        $attributeDefs = $entityDefs['attributes'] ?? $entityDefs['fields'] ?? [];
        $attributeDefs = array_merge($attributeDefs, $additionalAttributeDefs);

        if ($runParams->isExport() || $runParams->returnSthCollection()) {
            $collection = new SthCollection(
                $sth,
                $entityType,
                $this->entityManager,
                $attributeDefs,
                $linkMultipleFieldList,
                $foreignLinkFieldDataList,
                $this->getCustomEntityFactory()
            );
        }
        else {
            while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
                $rowData = [];

                foreach ($row as $attr => $value) {
                    $attribute = str_replace('.', '_', $attr);
                    $rowData[$attribute] = $value;
                }

                $entity = $this->getCustomEntityFactory()->create($entityType, $attributeDefs);

                $entity->set($rowData);
                $entity->setAsFetched();

                $this->listLoadProcessor->process($entity);

                foreach ($linkMultipleFieldList as $field) {
                    $entity->loadLinkMultipleField($field);
                }

                foreach ($foreignLinkFieldDataList as $item) {
                    $foreignId = $entity->get($item->name . 'Id');

                    if ($foreignId) {
                        $foreignEntity = $this->entityManager
                            ->getRDBRepository($item->entityType)
                            ->where(['id' => $foreignId])
                            ->select(['name'])
                            ->findOne();

                        if ($foreignEntity) {
                            $entity->set($item->name . 'Name', $foreignEntity->get('name'));
                        }
                    }
                }

                $collection[] = $entity;
            }
        }

        return new ListResult(
            $collection,
            $count,
            $data->getColumns(),
            $data->getColumnsData()
        );
    }

    private function getForeignLinkForeignEntityType(string $entityType, string $link, string $field): ?string
    {
        $foreignEntityType1 = $this->metadata->get(['entityDefs', $entityType, 'links', $link, 'entity']);

        return $this->metadata->get(['entityDefs', $foreignEntityType1, 'links', $field, 'entity']);
    }

    /**
     * @throws Forbidden
     * @throws Error
     */
    private function executeSubReportList(
        GridData $data,
        SearchParams $searchParams,
        SubReportParams $subReportParams,
        ?ListRunParams $runParams = null,
        ?User $user = null
    ): ListResult {

        $entityType = $data->getEntityType();

        $queryBuilder = $this->subReportQueryPreparator->prepare(
            $data,
            $searchParams,
            $subReportParams,
            $user
        );

        if ($runParams && $runParams->isFullSelect()) {
            $queryBuilder->select(['*']);
        }

        $collection = $this->entityManager
            ->getRDBRepository($entityType)
            ->clone($queryBuilder->build())
            ->find();

        $count = $this->entityManager
            ->getRDBRepository($entityType)
            ->clone($queryBuilder->build())
            ->count();

        $service = $this->recordServiceContainer->get($entityType);

        $loaderParams = LoaderParams::create()->withSelect($searchParams->getSelect());

        foreach ($collection as $entity) {
            $this->listLoadProcessor->process($entity, $loaderParams);

            $service->prepareEntityForOutput($entity);
        }

        return new ListResult($collection, $count);
    }

    /**
     * @throws Forbidden
     * @throws Error
     */
    public function executeGridReport(
        GridData $data,
        ?WhereItem $where,
        ?User $user = null
    ): GridResult {

        $groupValueMap = [];
        $numericColumnList = [];
        $subListColumnList = [];
        $summaryColumnList = [];

        foreach ($data->getColumns() as $item) {
            if ($this->gridHelper->isColumnNumeric($item, $data)) {
                $numericColumnList[] = $item;
            }
        }

        foreach ($data->getColumns() as $item) {
            if ($this->gridHelper->isColumnSummary($item, $data)) {
                $summaryColumnList[] = $item;
            }
            else if ($this->gridHelper->isColumnSubList($item, $data->getGroupBy()[0] ?? null)) {
                $subListColumnList[] = $item;
            }
        }

        if (count($data->getGroupBy()) === 2) {
            $subListColumnList = [];
        }

        $columnToBuildList = count($data->getGroupBy()) === 2 ?
            $summaryColumnList :
            $data->getColumns();

        $columnToBuildList = array_values(array_filter(
            $columnToBuildList,
            fn (string $item) => !in_array($item, $subListColumnList)
        ));

        $aggregatedColumnList = array_values(array_filter(
            $data->getColumns(),
            fn (string $item) => !in_array($item, $subListColumnList)
        ));

        $data = $data->withAggregatedColumns($aggregatedColumnList);

        if ($aggregatedColumnList === [] && $data->getGroupBy() === []) {
            $data = $data->withAggregatedColumns(['COUNT:(id)']);
        }

        if (count($subListColumnList)) {
            foreach ($columnToBuildList as $column) {
                if ($this->gridHelper->isColumnSubListAggregated($column)) {
                    $subListColumnList[] = $column;
                }
            }
        }

        $this->gridHelper->checkColumnsAvailability($data->getEntityType(), $data->getGroupBy());
        $this->gridHelper->checkColumnsAvailability($data->getEntityType(), $aggregatedColumnList);

        $query = $this->gridQueryPreparator->prepare($data, $where, $user);

        if ($query->getHaving() && !$query->getGroup()) {
            $this->throwError('badParams', 'havingFilterWithoutGroupByError');
        }

        try {
            $sth = $this->entityManager
                ->getQueryExecutor()
                ->execute($query);
        }
        catch (PDOException $e) {
            $this->handlePDOException($e);
        }
        catch (Exception $e) {
            $this->handleExecuteQueryException($e);
        }

        $rows = $sth->fetchAll(PDO::FETCH_ASSOC);

        $linkColumnList = array_merge(
            $this->gridHelper->obtainLinkColumnList($data),
            $this->gridHelper->obtainLinkColumnListFromColumns($data, $aggregatedColumnList),
        );

        $grouping = [];
        $sums = [];
        $cellValueMaps = (object) [];
        $nonSummaryColumnGroupMap = (object) [];
        $columnTypeMap = [];
        $columnDecimalPlacesMap = [];
        $columnNameMap = [];
        $nonSummaryColumnList = array_values(array_diff($data->getColumns(), $summaryColumnList));
        $emptyStringGroupExcluded = false;

        $groupList = array_map(
            function (Expression $expr): string {
                return $expr->getValue();
            },
            $query->getGroup()
        );

        $this->gridResultHelper->fixRows($rows, $groupList, $emptyStringGroupExcluded);
        $this->gridResultHelper->populateGroupValueMap($data, $groupList, $rows, $groupValueMap);
        $this->gridResultHelper->populateGrouping($data, $groupList, $rows, $where, $grouping);
        $this->gridResultHelper->populateRows($data, $groupList, $grouping, $rows, $nonSummaryColumnList);
        $this->gridResultHelper->populateGroupValueMapByLinkColumns($data, $linkColumnList, $rows, $groupValueMap);
        $this->gridResultHelper->populateGroupValueMapForDateFunctions($data, $grouping, $groupValueMap);
        $this->gridResultHelper->populateColumnInfo($data, $columnTypeMap, $columnDecimalPlacesMap, $columnNameMap);
        $this->gridResultHelper->sortGrouping($data, $grouping, $groupValueMap);

        $reportData = $this->gridBuilder->build(
            $data,
            $rows,
            $groupList,
            $columnToBuildList,
            $sums,
            $cellValueMaps
        );

        $nonSummaryData = $this->gridBuilder->buildNonSummary(
            $data->getColumns(),
            $summaryColumnList,
            $data,
            $rows,
            $groupList,
            $cellValueMaps,
            $nonSummaryColumnGroupMap
        );

        $subListData = $this->executeGridReportSubList(
            $grouping[0],
            $subListColumnList,
            $data,
            $where,
            $user
        );

        $resultObject = new GridResult(
            $data->getEntityType(),
            $data->getGroupBy(),
            $data->getColumns(),
            $numericColumnList,
            $summaryColumnList,
            $nonSummaryColumnList,
            $subListColumnList,
            $aggregatedColumnList,
            $nonSummaryColumnGroupMap, // stdClass
            $subListData, // object<stdClass[]>
            (object) $sums, // object<int|float>
            $groupValueMap, // array<string, array<string, mixed>>
            $columnNameMap, // array<string, string>
            $columnTypeMap, // array<string, string>
            $cellValueMaps, // object<object> (when grouping by link)
            $grouping, // array{string[]}|array{string[], string[]}
            $reportData, // object<object>|object<object<object>>
            $nonSummaryData, // object<object<object>>
            $data->getChartType(),
            $data->getChartDataList(), // stdClass[]
            (object) $columnDecimalPlacesMap, // object<?int>,
            $emptyStringGroupExcluded
        );

        $resultObject->setSuccess($data->getSuccess());

        if ($data->getChartColors()) {
            $resultObject->setChartColors((object) $data->getChartColors());
        }

        if ($data->getChartColor() && $data->getChartType()) {
            $resultObject->setChartColor($data->getChartColor());
        }

        $this->gridResultHelper->calculateSums($data, $resultObject);

        return $resultObject;
    }

    /**
     * @return never-return
     */
    private function throwError(string $reason, string $message): void
    {
        // As of v7.1.
        if (class_exists("Espo\\Core\\Exceptions\\Error\\Body")) {
            throw Error::createWithBody(
                $reason,
                Error\Body::create()
                    ->withMessageTranslation($message, 'Report')
                    ->encode()

            );
        }

        throw new Error($message);
    }

    /**
     * @param string[] $groupValueList
     * @param string[] $columnList
     * @return object // object<stdClass[]>
     */
    private function executeGridReportSubList(
        array $groupValueList,
        array $columnList,
        GridData $data,
        ?WhereItem $where,
        ?User $user = null
    ): object {

        if ($columnList === []) {
            return (object) [];
        }

        $result = (object) [];

        foreach ($groupValueList as $groupValue) {
            $result->$groupValue = $this->executeGridReportSubListItem(
                $groupValue,
                $columnList,
                $data,
                $where,
                $user
            );
        }

        return $result;
    }

    /**
     * @param ?scalar $groupValue
     * @throws Forbidden
     * @throws Error
     */
    private function executeGridReportSubListItem(
        $groupValue,
        array $columnList,
        GridData $data,
        ?WhereItem $where,
        ?User $user = null
    ): array {

        if ($groupValue === '') {
            $groupValue = null;
        }

        $realColumnList = array_map(
            function (string $column): string {
                return strpos($column, ':') === false ?
                    $column :
                    explode(':', $column)[1];
            },
            $columnList
        );

        $query = $this->subListQueryPreparator->prepare(
            $data,
            $groupValue,
            $columnList,
            $realColumnList,
            $where,
            $user
        );

        $linkColumnList = $this->gridHelper->obtainLinkColumnListFromColumns($data, $realColumnList);

        $columnAttributeMap = [];

        foreach ($columnList as $column) {
            if (in_array($column, $linkColumnList)) {
                $columnAttributeMap[$column] = $column . 'Name';

                continue;
            }

            if (strpos($column, ':') !== false) {
                $columnAttributeMap[$column] = explode(':', $column)[1];

                continue;
            }

            $columnAttributeMap[$column] = $column;
        }

        $limit = $this->config->get('reportGridSubListLimit') ?? self::GRID_SUB_LIST_LIMIT;

        $collection = $this->entityManager
            ->getRDBRepository($data->getEntityType())
            ->clone($query)
            ->limit(0, $limit)
            ->find();

        $itemList = [];

        foreach ($collection as $entity) {
            $item = (object) ['id' => $entity->getId()];

            foreach ($columnList as $column) {
                $attribute = $columnAttributeMap[$column];
                $columnData = $this->gridHelper->getDataFromColumnName($data->getEntityType(), $column);

                $item->$column = $this->getCellDisplayValueFromEntity($entity, $attribute, $columnData);
            }

            $itemList[] = $item;
        }

        return $itemList;
    }

    /**
     * @return scalar
     */
    private function getCellDisplayValueFromEntity(Entity $entity, string $attribute, object $columnData)
    {
        if ($columnData->fieldType === 'datetimeOptional' && $entity->get($attribute . 'Date')) {
            $attribute = $attribute . 'Date';
            $columnData->fieldType = 'date';
        }

        return $this->gridUtil->getCellDisplayValue($entity->get($attribute), $columnData);
    }

    /**
     * @throws Forbidden
     * @throws NotFound
     * @throws Error
     */
    public function getReportResultsTableData(
        string $id,
        ?WhereItem $where = null,
        ?string $column = null,
        ?User $user = null
    ): array {

        /** @var ?Report $report */
        $report = $this->entityManager->getEntityById(Report::ENTITY_TYPE, $id);

        if (!$report) {
            throw new NotFound();
        }

        if ($report->getType() === Report::TYPE_LIST) {
            $searchParams = SearchParams::create();

            if ($where) {
                $searchParams = $searchParams->withWhere($where);
            }

            $result = $this->runList($id, $searchParams, $user);
        }
        else {
            $result = $this->runGrid($id, $where, $user);
        }

        $resultData = $result;

        if ($result instanceof ListResult) {
            $resultData = [];

            foreach ($result->getCollection() as $e) {
                $resultData[] = get_object_vars($e->getValueMap());
            }
        }

        /** @var ?Report $report */
        $report = $this->entityManager->getEntity(Report::ENTITY_TYPE, $id);

        if (!$report) {
            throw new NotFound();
        }

        $data = (object) [
            'userId' => $user ? $user->getId() : $this->user->getId(),
            'specificColumn' => $column,
        ];

        $service = $this->injectableFactory->create(SendingService::class);

        $service->buildData($data, $resultData, $report);

        return $data->tableData ?? [];
    }

    private function getCustomEntityFactory(): CustomEntityFactory
    {
        if (!$this->customEntityFactory) {
            $this->customEntityFactory = new CustomEntityFactory(
                $this->injectableFactory,
                $this->entityManager
            );
        }

        return $this->customEntityFactory;
    }

    /**
     * @return never-return
     */
    private function handlePDOException(PDOException $e): void
    {
        if ((int)$e->getCode() === 42000) {
            $message = strpos($e->getMessage(), ': 1055') !== false ?
                'onlyFullGroupByError' :
                'sqlSyntaxError';

            $this->log->error($e->getMessage());
            $this->throwError('sqlSyntaxError', $message);
        }

        if ($e->getCode() === '42S22') {
            $this->log->error($e->getMessage());
            $this->throwError('invalidColumnError', 'invalidColumnError');
        }

        $this->log->error($e->getMessage());
        $this->throwError('executionError', 'executionError');
    }

    /**
     * @return never-return
     */
    private function handleExecuteQueryException(Exception $e): void
    {
        $msg = $e->getMessage() . "; file: {$e->getFile()}; line: {$e->getLine()}";

        $this->log->error($msg);
        $this->throwError('executionError', 'executionError');
    }
}
