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
 * License ID: bcd3361258b6d66fc350488ed9575786
 ************************************************************************************/

namespace Espo\Modules\Sales\Tools\Price\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Modules\Sales\Tools\Price\PricePairFetcher;
use Espo\Modules\Sales\Tools\Price\PriceService;

/**
 * @noinspection PhpUnused
 */
class PostGetPurchasePrice implements Action
{
    public function __construct(
        private PriceService $service,
        private PricePairFetcher $pricePairFetcher
    ) {}

    public function process(Request $request): Response
    {
        $pairs = $this->pricePairFetcher->fetch($request);
        $supplierId = $request->getParsedBody()->supplierId ?? null;
        $currency = $request->getParsedBody()->currency ?? null;

        $results = $this->service->getPurchaseMultiple($pairs, $supplierId, $currency);

        $response = array_map(fn($result) => (object) [
            'unitPrice' => $result->getUnit()?->getAmount(),
            'listPrice' => $result->getList()?->getAmount(),
            'unitPriceCurrency' => $result->getUnit()?->getCode(),
            'listPriceCurrency' => $result->getList()?->getCode(),
        ], $results);

        return ResponseComposer::json($response);
    }
}
