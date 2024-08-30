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

class SubReportParams
{
    private int $groupIndex;
    /** @var ?scalar */
    private $groupValue;
    private bool $hasGroupValue2;
    /** @var ?scalar */
    private $groupValue2;

    public function __construct(
        int $groupIndex,
        $groupValue,
        bool $hasGroupValue2 = false,
        $groupValue2 = null
    ) {
        $this->groupIndex = $groupIndex;
        $this->groupValue = $groupValue;
        $this->groupValue2 = $groupValue2;
        $this->hasGroupValue2 = $hasGroupValue2;
    }

    public function getGroupIndex(): int
    {
        return $this->groupIndex;
    }

    /**
     * @return ?scalar
     */
    public function getGroupValue()
    {
        return $this->groupValue;
    }

    public function hasGroupValue2(): bool
    {
        return $this->hasGroupValue2;
    }

    /**
     * @return ?scalar
     */
    public function getGroupValue2()
    {
        return $this->groupValue2;
    }
}
