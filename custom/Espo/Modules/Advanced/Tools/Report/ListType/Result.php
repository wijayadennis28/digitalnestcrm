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

use Espo\ORM\Collection;
use stdClass;

class Result
{
    private Collection $collection;
    private int $total;
    /** @var ?string[] */
    private ?array $columns;
    private ?stdClass $columnsData;

    /**
     * @param ?string[] $columns
     */
    public function __construct(
        Collection $collection,
        int $total,
        ?array $columns = null,
        ?stdClass $columnsData = null
    ) {
        $this->collection = $collection;
        $this->total = $total;
        $this->columns = $columns;
        $this->columnsData = $columnsData;
    }

    public function getCollection(): Collection
    {
        return $this->collection;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    /**
     * @return string[]
     */
    public function getColumns(): ?array
    {
        return $this->columns;
    }

    public function getColumnsData(): ?stdClass
    {
        return $this->columnsData;
    }
}