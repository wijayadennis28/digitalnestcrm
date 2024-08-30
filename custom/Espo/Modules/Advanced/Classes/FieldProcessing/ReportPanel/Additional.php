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

namespace Espo\Modules\Advanced\Classes\FieldProcessing\ReportPanel;

use Espo\Core\FieldProcessing\Loader;
use Espo\Core\FieldProcessing\Loader\Params;
use Espo\Modules\Advanced\Entities\Report as ReportEntity;
use Espo\Modules\Advanced\Tools\Report\GridType\Helper;
use Espo\Modules\Advanced\Tools\Report\ReportHelper;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class Additional implements Loader
{
    private Helper $helper;
    private EntityManager $entityManager;
    private ReportHelper $reportHelper;

    public function __construct(
        Helper $helper,
        EntityManager $entityManager,
        ReportHelper $reportHelper
    ) {
        $this->helper = $helper;
        $this->entityManager = $entityManager;
        $this->reportHelper = $reportHelper;
    }

    public function process(Entity $entity, Params $params): void
    {
        if (
            $entity->get('reportType') === ReportEntity::TYPE_GRID &&
            $entity->get('reportId')
        ) {
            /** @var ?ReportEntity $report */
            $report = $this->entityManager->getEntityById(ReportEntity::ENTITY_TYPE, $entity->get('reportId'));

            if ($report) {
                $columnList = $report->get('columns');

                $numericColumnList = [];

                $gridData = $this->reportHelper->fetchGridDataFromReport($report);

                foreach ($columnList as $column) {
                    if ($this->helper->isColumnNumeric($column, $gridData)) {
                        $numericColumnList[] = $column;
                    }
                }

                if (
                    is_array($report->get('groupBy')) &&
                    (
                        count($report->get('groupBy')) === 1 ||
                        count($report->get('groupBy')) === 0
                    ) &&
                    count($numericColumnList) > 1
                ) {
                    array_unshift($numericColumnList, '');
                }

                $entity->set('columnList', $numericColumnList);
            }

            $entity->set('columnsData', $report->get('columnsData') ?? (object) []);
        }

        $displayType = $entity->get('displayType');
        $reportType = $entity->get('reportType');
        $displayTotal = $entity->get('displayTotal');
        $displayOnlyTotal = $entity->get('displayOnlyTotal');

        if (!$displayType) {
            if (
                $reportType === ReportEntity::TYPE_GRID ||
                $reportType === ReportEntity::TYPE_JOINT_GRID
            ) {
                if ($displayOnlyTotal) {
                    $displayType = 'Total';
                }
                else if ($displayTotal) {
                    $displayType = 'Chart-Total';
                }
                else {
                    $displayType = 'Chart';
                }
            }
            else if ($reportType === ReportEntity::TYPE_LIST) {
                if ($displayOnlyTotal) {
                    $displayType = 'Total';
                }
                else {
                    $displayType = 'List';
                }
            }

            $entity->set('displayType', $displayType);
        }
    }
}
