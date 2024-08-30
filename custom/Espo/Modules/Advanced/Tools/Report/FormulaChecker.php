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

use Espo\Core\Exceptions\Error\Body;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Metadata;

class FormulaChecker
{
    private array $allowedFunctionList = [
        'ifThen',
        'ifThenElse',
        'env\\userAttribute',
        'record\\attribute',
    ];

    private array $allowedNamespaceList = [
        'datetime',
        'number',
        'string',
    ];

    public function __construct(
        private Metadata $metadata
    ) {}

    public function sanitize(string $script): string
    {
        $script = str_replace('record\\attribute', 'report\\recordAttribute', $script);

        if (!class_exists("Espo\\Core\\Formula\\Functions\\EnvGroup\\UserAttributeSafeType")) {
            return $script;
        }

        return str_replace('env\\userAttribute', 'env\\userAttributeSafe', $script);
    }

    /**
     * Check a formula script for a complex expression.
     *
     * @throws Forbidden
     */
    public function check(string $script): void
    {
        $script = str_replace(["\n", "\r", "\t", ' '], '', $script);
        $script = str_replace(';', ' ', $script);

        preg_match_all('/[a-zA-Z1-9\\\\]*\(/', $script, $matches);

        if (!$matches) {
            return;
        }

        $allowedFunctionList = array_merge(
            $this->allowedFunctionList,
            $this->metadata->get('app.advancedReport.allowedFilterFormulaFunctionList', [])
        );

        foreach ($matches[0] as $part) {
            $part = substr($part, 0, -1);

            if (in_array($part, $allowedFunctionList)) {
                continue;
            }

            foreach ($this->allowedNamespaceList as $namespace) {
                if (str_starts_with($part, $namespace . '\\')) {
                    continue 2;
                }
            }

            throw Forbidden::createWithBody(
                "Not allowed formula in filter.",
                Body::create()
                    ->withMessageTranslation('notAllowedFormulaInFilter', 'Report')
                    ->encode()
            );
        }
    }
}
