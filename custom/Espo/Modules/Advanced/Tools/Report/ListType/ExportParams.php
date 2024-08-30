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

class ExportParams
{
    /** @var ?string[] */
    private ?array $attributeList;
    /** @var ?string[] */
    private ?array $fieldList;
    private ?string $format;
    /** @var ?string[] */
    private ?array $ids;
    /** @var ?array<string, mixed> */
    private ?array $params;

    /**
     * @param ?string[] $attributeList
     * @param ?string[] $fieldList
     * @param ?string[] $ids
     * @params ?array<string, mixed> $params
     */
    public function __construct(
        ?array $attributeList,
        ?array $fieldList,
        ?string $format,
        ?array $ids,
        ?array $params
    ) {
        $this->attributeList = $attributeList;
        $this->fieldList = $fieldList;
        $this->format = $format;
        $this->ids = $ids;
        $this->params = $params;
    }

    /**
     * @return ?string[]
     */
    public function getAttributeList(): ?array
    {
        return $this->attributeList;
    }

    /**
     * @return ?string[]
     */
    public function getFieldList(): ?array
    {
        return $this->fieldList;
    }

    public function getFormat(): ?string
    {
        return $this->format;
    }

    /**
     * @return ?string[]
     */
    public function getIds(): ?array
    {
        return $this->ids;
    }

    /**
     * @return ?array<string, mixed>
     */
    public function getParams(): ?array
    {
        return $this->params;
    }
}