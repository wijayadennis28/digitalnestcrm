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

namespace Espo\Modules\Sales\Hooks\PurchaseOrder;

use Espo\Modules\Sales\Entities\PurchaseOrder;
use Espo\Modules\Sales\Hooks\Quote\SaveItems as QuoteSaveItems;
use Espo\Modules\Sales\Tools\Inventory\PurchaseOrderProcessor;
use Espo\Modules\Sales\Tools\Inventory\WebSocketSubmitter;
use Espo\Modules\Sales\Tools\Sales\ConfigDataProvider;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** @noinspection PhpUnused */
class SaveItems
{
    public function __construct(
        private QuoteSaveItems $hook,
        private PurchaseOrderProcessor $purchaseOrderProcessor,
        private EntityManager $entityManager,
        private WebSocketSubmitter $webSocketSubmitter,
        private ConfigDataProvider $configDataProvider
    ) {}

    /**
     * @param PurchaseOrder $entity
     */
    public function afterSave(Entity $entity, array $options): void
    {
        $toSubmit = false;

        $this->entityManager
            ->getTransactionManager()
            ->run(function () use ($entity, $options, &$toSubmit) {
                $this->hook->afterSave($entity, $options);

                if (!$this->toProcessInventorySave($entity)) {
                    return;
                }

                $this->purchaseOrderProcessor->processSave($entity);

                $toSubmit = true;
            });

        if ($toSubmit) {
            $this->webSocketSubmitter->process($entity);
        }
    }

    private function toProcessInventorySave(PurchaseOrder $entity): bool
    {
        return
            $this->configDataProvider->isInventoryTransactionsEnabled() &&
            !$this->configDataProvider->isReceiptOrdersEnabled() &&
            !$entity->isLocked() &&
            (
                $entity->isNew() ||
                $entity->isAttributeChanged('status') ||
                $entity->isAttributeChanged('itemList')
            );
    }

    /**
     * @param PurchaseOrder $entity
     */
    public function afterRemove(Entity $entity): void
    {
        $this->hook->afterRemove($entity);

        if (
            $this->configDataProvider->isInventoryTransactionsEnabled() &&
            !$entity->isLocked()
        ) {
            $this->purchaseOrderProcessor->processRemove($entity);
        }
    }
}
