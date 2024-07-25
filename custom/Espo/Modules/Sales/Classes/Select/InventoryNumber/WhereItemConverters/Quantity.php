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

namespace Espo\Modules\Sales\Classes\Select\InventoryNumber\WhereItemConverters;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Select\Where\Item;
use Espo\Core\Select\Where\ItemConverter;
use Espo\Core\Utils\Config;
use Espo\Modules\Sales\Tools\Sales\ConfigDataProvider;
use Espo\ORM\Query\Part\Condition;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\Part\WhereItem as WhereClauseItem;
use Espo\ORM\Query\SelectBuilder as QueryBuilder;
use RuntimeException;

/**
 * @noinspection PhpUnused
 */
class Quantity implements ItemConverter
{
    public function __construct(
        private Config $config,
        private ConfigDataProvider $configDataProvider
    ) {}

    /**
     * @throws BadRequest
     */
    public function convert(QueryBuilder $queryBuilder, Item $item): WhereClauseItem
    {
        if (!$this->toApply()) {
            return Expr::equal('id', null);
        }

        $sqAlias = $item->getAttribute() . 'Sq';

        $expr =
            Expr::coalesce(
                Expr::column($sqAlias . '.sum'),
                Expr::value(0.0)
            );

        $where = null;

        switch ($item->getType()) {
            case Item\Type::GREATER_THAN:
                $where = Condition::greater($expr, $item->getValue());

                break;

            case Item\Type::GREATER_THAN_OR_EQUALS:
                $where = Condition::greaterOrEqual($expr, $item->getValue());

                break;

            case Item\Type::LESS_THAN:
                $where = Condition::less($expr, $item->getValue());

                break;

            case Item\Type::LESS_THAN_OR_EQUALS:
                $where = Condition::lessOrEqual($expr, $item->getValue());

                break;

            case Item\Type::EQUALS:
                $where = Condition::equal($expr, $item->getValue());

                break;

            case Item\Type::NOT_EQUALS:
                $where = Condition::notEqual($expr, $item->getValue());

                break;

            case Item\Type::BETWEEN:
                if (!is_array($item->getValue())) {
                    throw new BadRequest();
                }

                [$v1, $v2] = $item->getValue();

                $where = Condition::and(
                    Condition::greaterOrEqual($expr, $v1),
                    Condition::lessOrEqual($expr, $v2)
                );

                break;

            case Item\Type::IS_NULL:
                $where = Condition::equal($expr, 0);

                break;

            case Item\Type::IS_NOT_NULL:
                $where = Condition::notEqual($expr, 0);

                break;
        }

        if (!$where) {
            throw new RuntimeException("Bad where item.");
        }

        return $where;
    }

    private function toApply(): bool
    {
        $version = $this->config->get('version');

        if (version_compare($version, '8.0.0') < 0) {
            return false;
        }

        if (!$this->configDataProvider->isInventoryTransactionsEnabled()) {
            return false;
        }

        return true;
    }
}
