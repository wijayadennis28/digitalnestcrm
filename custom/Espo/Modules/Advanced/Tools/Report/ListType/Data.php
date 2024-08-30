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

use Espo\Core\Select\Where\Item as WhereItem;

use stdClass;

class Data
{
    private string $entityType;
    /** @var string[] */
    private array $columns;
    /** @var ?string */
    private ?string $orderBy;
    private ?stdClass $columnsData;
    private ?WhereItem $filtersWhere;

    /**
     * @param string[] $columns
     */
    public function __construct(
        string $entityType,
        array $columns,
        ?string $orderBy,
        ?stdClass $columnsData,
        ?WhereItem $filtersWhere
    ) {
        $this->entityType = $entityType;
        $this->columns = $columns;
        $this->orderBy = $orderBy;
        $this->columnsData = $columnsData;
        $this->filtersWhere = $filtersWhere;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    /**
     * @return string[]
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    public function getOrderBy(): ?string
    {
        return $this->orderBy;
    }

    public function getColumnsData(): ?stdClass
    {
        return $this->columnsData;
    }

    /**
     * @param string[] $columns
     */
    public function withColumns(array $columns): self
    {
        $obj = clone $this;
        $obj->columns = $columns;

        return $obj;
    }

    public function getFiltersWhere(): ?WhereItem
    {
        return $this->filtersWhere;
    }
}
