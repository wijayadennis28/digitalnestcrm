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

namespace Espo\Modules\Sales\Classes\Select\InventoryNumber\PrimaryFilters;

use Espo\Core\Select\Primary\Filter;
use Espo\Modules\Sales\Entities\InventoryTransaction;
use Espo\ORM\Query\Part\Condition as Cond;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\Part\Join;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Query\SelectBuilder as QueryBuilder;

/**
 * @noinspection PhpUnused
 */
class NotOnHand implements Filter
{
    public function apply(QueryBuilder $queryBuilder): void
    {
        $query = SelectBuilder::create()
            ->from(InventoryTransaction::ENTITY_TYPE)
            ->select(
                Expr::coalesce(
                    Expr::sum(Expr::column('quantity')),
                    Expr::value(0.0)
                ),
                'sum'
            )
            ->select('inventoryNumberId', 'nid')
            ->where(['type!=' => InventoryTransaction::TYPE_SOFT_RESERVE])
            ->group('inventoryNumberId')
            ->build();

        $queryBuilder
            ->leftJoin(
                Join::createWithSubQuery($query, 'quantitySq')
                    ->withConditions(
                        Cond::equal(
                            Expr::column('quantitySq.nid'),
                            Expr::column('inventoryNumber.id')
                        )
                    )
            )
            ->where(
                Cond::or(
                    Expr::isNull(Expr::column('quantitySq.sum')),
                    Expr::lessOrEqual(Expr::column('quantitySq.sum'), 0.0),
                )
            );
    }
}
