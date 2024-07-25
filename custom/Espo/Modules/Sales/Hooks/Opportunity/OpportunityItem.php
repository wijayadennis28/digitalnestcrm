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

namespace Espo\Modules\Sales\Hooks\Opportunity;

use Espo\Modules\Sales\Tools\Quote\ItemsRemoveProcessor;
use Espo\Modules\Sales\Tools\Quote\ItemsSaveProcessor;
use Espo\ORM\Entity;

/** @noinspection PhpUnused */
class OpportunityItem
{
    private const ROUND_PRECISION = 2;

    private ItemsSaveProcessor $itemsSaveProcessor;
    private ItemsRemoveProcessor $itemsRemoveProcessor;

    public function __construct(
        ItemsSaveProcessor $itemsSaveProcessor,
        ItemsRemoveProcessor $itemsRemoveProcessor
    ) {
        $this->itemsSaveProcessor = $itemsSaveProcessor;
        $this->itemsRemoveProcessor = $itemsRemoveProcessor;
    }

    public function beforeSave(Entity $entity): void
    {
        if (!$entity->has('itemList')) {
            return;
        }

        $itemList = $entity->get('itemList');

        if (!is_array($itemList)) {
            return;
        }

        if ($entity->has('amountCurrency')) {
            foreach ($itemList as $o) {
                $o->listPriceCurrency = $entity->get('amountCurrency');
                $o->unitPriceCurrency = $entity->get('amountCurrency');
                $o->amountCurrency = $entity->get('amountCurrency');
            }
        }

        foreach ($itemList as $o) {
            if (!isset($o->quantity)) {
                $o->quantity = 1;
            }

            if (!isset($o->amount) && isset($o->unitPrice)) {
                $o->amount = (float) $o->unitPrice * $o->quantity;
            }
        }

        if (count($itemList)) {
            $amount = 0.0;

            foreach ($itemList as $o) {
                $amount += $o->amount;
            }

            $amount = round($amount, self::ROUND_PRECISION);

            $entity->set('amount', $amount);
        }
    }

    public function afterSave(Entity $entity, array $options = []): void
    {
        if (!empty($options['skipWorkflow']) && empty($options['addItemList'])) {
            return;
        }

        $isNew = $entity->isNew();

        if ($options['forceIsNotNew'] ?? false) {
            $isNew = false;
        }

        $this->itemsSaveProcessor->process($entity, $isNew);
    }

    public function afterRemove(Entity $entity): void
    {
        $this->itemsRemoveProcessor->process($entity);
    }
}
