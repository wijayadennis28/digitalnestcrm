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

namespace Espo\Modules\Advanced\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Controllers\Record;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\SearchParamsFetcher;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Modules\Advanced\Tools\Report\GridExportService;
use Espo\Modules\Advanced\Tools\Report\ListExportService;
use Espo\Modules\Advanced\Tools\Report\ListType\ExportParams;
use Espo\Modules\Advanced\Tools\Report\ListType\SubReportParams;
use Espo\Modules\Advanced\Tools\Report\SendingService;
use Espo\Modules\Advanced\Tools\Report\Service;
use Espo\Modules\Advanced\Tools\Report\TargetListSyncService;

use stdClass;

class Report extends Record
{
    /**
     * List report or grid sub-report.
     *
     * @throws BadRequest
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function actionRunList(Request $request): stdClass
    {
        $id = $request->getQueryParam('id');

        if (!$id) {
            throw new BadRequest();
        }

        $searchParams = $this->injectableFactory
            ->create(SearchParamsFetcher::class)
            ->fetch($request);

        $subReportParams = $this->fetchSubReportParamsFromRequest($request);

        // Passing the user is important.
        $result = $subReportParams ?
            $this->getReportService()->runSubReportList($id, $searchParams, $subReportParams, $this->user) :
            $this->getReportService()->runList($id, $searchParams, $this->user);

        return (object) [
            'list' => $result->getCollection()->getValueMapList(),
            'total' => $result->getTotal(),
            'columns' => $result->getColumns(),
            'columnsData' => $result->getColumnsData(),
        ];
    }

    private function fetchSubReportParamsFromRequest(Request $request): ?SubReportParams
    {
        if (!$request->hasQueryParam('groupValue')) {
            return null;
        }

        $groupValue = $request->getQueryParam('groupValue');

        if ($groupValue === '') {
            $groupValue = null;
        }

        $groupValue2 = null;

        if ($request->hasQueryParam('groupValue2')) {
            $groupValue2 = $request->getQueryParam('groupValue2');

            if ($groupValue2 === '') {
                $groupValue2 = null;
            }
        }

        return new SubReportParams(
            (int) ($request->getQueryParam('groupIndex') ?? 0),
            $groupValue,
            $request->hasQueryParam('groupValue2'),
            $groupValue2
        );
    }

    /**
     * Grid report.
     *
     * @throws BadRequest
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function actionRun(Request $request): stdClass
    {
        $id = $request->getQueryParam('id');
        $where = $request->getQueryParams()['where'] ?? null;

        if ($where === '') {
            $where = null;
        }

        $whereItem = null;

        if ($where) {
            $whereItem = WhereItem::fromRawAndGroup(json_decode(json_encode($where), true));
        }

        if (!$id) {
            throw new BadRequest();
        }

        // Passing the user is important.
        return $this->getReportService()
            ->runGrid($id, $whereItem, $this->user)
            ->toRaw();
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function postActionPopulateTargetList(Request $request): bool
    {
        $data = $request->getParsedBody();

        $id = $data->id ?? null;
        $targetListId = $data->targetListId ?? null;

        if (!$id || !$targetListId) {
            throw new BadRequest();
        }

        $this->getTargetListSyncService()->populateTargetList($id, $targetListId);

        return true;
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function postActionSyncTargetListWithReports(Request $request): bool
    {
        $data = $request->getParsedBody();

        $targetListId = $data->targetListId;

        if (!$targetListId) {
            throw new BadRequest();
        }

        $this->getTargetListSyncService()->syncTargetListWithReportsById($targetListId);

        return true;
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function postActionExportList(Request $request): stdClass
    {
        $data = $request->getParsedBody();

        $id = $data->id ?? null;
        $orderBy = $data->orderBy ?? null;
        $order = $data->order ?? 'asc';
        $where = $data->where ?? null;

        if (!$id) {
            throw new BadRequest("No `id`.");
        }

        $whereItem = $where ?
            WhereItem::fromRawAndGroup(json_decode(json_encode($where), true)) :
            null;

        $searchParams = SearchParams::create()
            ->withOrderBy($orderBy)
            ->withOrder(strtoupper($order));

        if ($whereItem) {
            $searchParams = $searchParams->withWhere($whereItem);
        }

        $exportParams = new ExportParams(
            $data->attributeList ?? null,
            $data->fieldList ?? null,
            $data->format ?? null,
            $data->ids ?? null,
            ($data->params ?? null) ? get_object_vars($data->params) : null,
        );

        $subReportParams = null;

        if (property_exists($data, 'groupValue')) {
            $groupValue = $data->groupValue;

            if ($groupValue === '') {
                $groupValue = null;
            }

            $groupValue2 = $data->groupValue2 ?? null;

            if ($groupValue2 === '') {
                $groupValue2 = null;
            }

            $hasGroupValue2 = property_exists($data, 'groupValue2');

            $subReportParams = new SubReportParams(
                $data->groupIndex ?? 0,
                $groupValue,
                $hasGroupValue2,
                $groupValue2
            );
        }

        $attachmentId = $this->getListExportService()->export(
            $id,
            $searchParams,
            $exportParams,
            $subReportParams,
            $this->user
        );

        return (object) ['id' => $attachmentId];
    }

    /**
     * @throws BadRequest
     * @throws NotFound
     * @throws Error
     * @throws Forbidden
     */
    public function postActionGetEmailAttributes(Request $request): stdClass
    {
        $data = $request->getParsedBody();

        $id = $data->id;
        $where = $data->where ?? null;

        if (!$id) {
            throw new BadRequest();
        }

        $whereItem = $where ?
            WhereItem::fromRawAndGroup(json_decode(json_encode($where), true)) :
            null;

        return (object) $this->injectableFactory
            ->create(SendingService::class)
            ->getEmailAttributes($id, $whereItem, $this->user);
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function postActionExportGridXlsx(Request $request): stdClass
    {
        $data = $request->getParsedBody();

        $id = $data->id;
        $where = $data->where ?? null;

        if (!$id) {
            throw new BadRequest();
        }

        $whereItem = $where ?
            WhereItem::fromRawAndGroup(json_decode(json_encode($where), true)) :
            null;

        $attachmentId = $this->getGridExportService()->exportXlsx($id, $whereItem, $this->user);

        return (object) ['id' => $attachmentId];
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     * @throws NotFound
     * @throws Error
     */
    public function postActionExportGridCsv(Request $request): stdClass
    {
        $data = $request->getParsedBody();

        $id = $data->id;
        $where = $data->where ?? null;
        $column = $data->column ?? null;

        if (!$id) {
            throw new BadRequest();
        }

        $whereItem = $where ?
            WhereItem::fromRawAndGroup(json_decode(json_encode($data->where), true)) :
            null;

        $attachmentId = $this->getGridExportService()->exportCsv($id, $whereItem, $column, $this->user);

        return (object) ['id' => $attachmentId];
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function postActionPrintPdf(Request $request): stdClass
    {
        $data = $request->getParsedBody();

        $id = $data->id ?? null;
        $templateId = $data->templateId ?? null;
        $where = $data->where ?? null;

        $whereItem = $where ?
            WhereItem::fromRawAndGroup(json_decode(json_encode($data->where), true)) :
            null;

        if (!$id || !$templateId) {
            throw new BadRequest();
        }

        $attachmentId = $this->getGridExportService()->exportPdf($id, $whereItem, $templateId, $this->user);

        return (object) ['id' => $attachmentId];
    }

    private function getReportService(): Service
    {
        return $this->injectableFactory->create(Service::class);
    }

    private function getListExportService(): ListExportService
    {
        return $this->injectableFactory->create(ListExportService::class);
    }

    private function getGridExportService(): GridExportService
    {
        return $this->injectableFactory->create(GridExportService::class);
    }

    private function getTargetListSyncService(): TargetListSyncService
    {
        return $this->injectableFactory->create(TargetListSyncService::class);
    }
}
