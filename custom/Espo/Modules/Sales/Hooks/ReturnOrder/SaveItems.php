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

namespace Espo\Modules\Sales\Hooks\ReturnOrder;

use Espo\Modules\Sales\Tools\Quote\ItemsRemoveProcessor;
use Espo\Modules\Sales\Tools\Quote\ItemsSaveProcessor;
use Espo\Modules\Sales\Tools\Sales\OrderEntity;
use Espo\ORM\Entity;

class SaveItems
{
    public function __construct(
        private ItemsSaveProcessor $itemsSaveProcessor,
        private ItemsRemoveProcessor $itemsRemoveProcessor
    ) {}

    /**
     * @param OrderEntity $entity
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
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

    /**
     * @param OrderEntity $entity
     */
    public function afterRemove(Entity $entity): void
    {
        $this->itemsRemoveProcessor->process($entity);
    }
}
