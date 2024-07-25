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

namespace Espo\Modules\Sales\Classes\Record\Hooks\ReceiptOrder;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Error\Body;
use Espo\Core\Record\Hook\UpdateHook;
use Espo\Core\Record\UpdateParams;
use Espo\Modules\Sales\Entities\ReceiptOrder;
use Espo\Modules\Sales\Tools\ReceiptOrder\AvailabilityCheck;
use Espo\Modules\Sales\Tools\ReceiptOrder\ReceivedInventoryCheck;
use Espo\Modules\Sales\Tools\ReceiptOrder\ReceivedQuantityCheck;
use Espo\Modules\Sales\Tools\ReceiptOrder\ValidationHelper;
use Espo\ORM\Entity;

/**
 * @noinspection PhpUnused
 * @implements UpdateHook<ReceiptOrder>
 */
class BeforeUpdateValidation implements UpdateHook
{
    public function __construct(
        private AvailabilityCheck $availabilityCheck,
        private ValidationHelper $validationHelper,
        private ReceivedQuantityCheck $receivedQuantityCheck,
        private ReceivedInventoryCheck $receivedInventoryCheck
    ) {}

    /**
     * @param ReceiptOrder $entity
     * @throws Conflict
     * @throws BadRequest
     */
    public function process(Entity $entity, UpdateParams $params): void
    {
        if (
            $entity->isAttributeChanged('status') ||
            $entity->isAttributeChanged('itemList')
        ) {
            $this->receivedQuantityCheck->validate($entity);
        }

        $this->processInAdjustmentCheck($entity);
        $this->processInventoryCheck($entity);
    }

    /**
     * @throws Conflict
     */
    private function processInAdjustmentCheck(ReceiptOrder $entity): void
    {
        if (
            !$this->validationHelper->toProcessInventorySave($entity) ||
            $this->availabilityCheck->checkNotBeingAdjusted($entity)
        ) {
            return;
        }

        $idPart = $entity->hasId() ? $entity->getId() : '(new)';

        throw Conflict::createWithBody(
            "Inventory for ReceiptOrder $idPart is in adjustment.",
            Body::create()
                ->withMessageTranslation('inventoryIsInAdjustment', ReceiptOrder::ENTITY_TYPE)
                ->encode()
        );
    }

    /**
     * @throws BadRequest
     */
    private function processInventoryCheck(ReceiptOrder $entity): void
    {
        if (
            !$entity->isAttributeChanged('status') &&
            !$entity->isAttributeChanged('itemList') &&
            !$entity->isAttributeChanged('receivedItemList')
        ) {
            return;
        }

        $this->receivedInventoryCheck->validate($entity);
    }
}
