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
use Espo\Modules\Crm\Entities\Account;
use Espo\Modules\Sales\Tools\Price\PricePairFetcher;
use Espo\Modules\Sales\Tools\Price\PriceService;
use Espo\Modules\Sales\Tools\Price\Service\SalesData;
use Espo\ORM\EntityManager;

/**
 * @noinspection PhpUnused
 */
class PostGetSalesPrice implements Action
{
    public function __construct(
        private PriceService $service,
        private PricePairFetcher $pricePairFetcher,
        private EntityManager $entityManager
    ) {}

    public function process(Request $request): Response
    {
        $pairs = $this->pricePairFetcher->fetch($request);
        $accountId = $request->getParsedBody()->accountId ?? null;
        $priceBookId = $request->getParsedBody()->priceBookId ?? null;
        $applyAccountPriceBook = $request->getParsedBody()->applyAccountPriceBook ?? false;

        if ($applyAccountPriceBook && !$priceBookId && $accountId) {
            $priceBookId = $this->obtainPriceBookId($accountId);
        }

        $data = new SalesData(
            accountId: $request->getParsedBody()->accountId ?? null,
            orderType: $request->getParsedBody()->orderType ?? null,
            orderId: $request->getParsedBody()->orderId ?? null,
            currency: $request->getParsedBody()->currency ?? null,
        );

        $results = $this->service->getSalesMultiple($pairs, $priceBookId, $data);

        $response = array_map(fn($result) => (object) [
            'unitPrice' => $result->getUnit()?->getAmount(),
            'listPrice' => $result->getList()?->getAmount(),
            'unitPriceCurrency' => $result->getUnit()?->getCode(),
            'listPriceCurrency' => $result->getList()?->getCode(),
        ], $results);

        return ResponseComposer::json($response);
    }

    private function obtainPriceBookId(string $accountId): ?string
    {
        $account = $this->entityManager->getEntityById(Account::ENTITY_TYPE, $accountId);

        if (!$account) {
            return null;
        }

        return $account->get('priceBookId');
    }
}
