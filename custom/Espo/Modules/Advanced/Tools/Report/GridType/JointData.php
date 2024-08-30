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

namespace Espo\Modules\Advanced\Tools\Report\GridType;

class JointData
{
    /** @var ?array<int, object{id: string}> */
    private ?array $joinedReportDataList;
    private ?string $chartType;

    /**
     * @param ?array<int, object{id: string}> $joinedReportDataList
     * @param string|null $chartType
     */
    public function __construct(
        ?array $joinedReportDataList,
        ?string $chartType
    ) {
        $this->joinedReportDataList = $joinedReportDataList;
        $this->chartType = $chartType;
    }

    /**
     * @return array<int, object{id: string}>
     */
    public function getJoinedReportDataList(): array
    {
        return $this->joinedReportDataList;
    }

    public function getChartType(): ?string
    {
        return $this->chartType;
    }
}
