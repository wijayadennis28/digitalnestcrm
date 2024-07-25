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

namespace Espo\Modules\Sales\Tools\Inventory;

use Espo\Core\Utils\Metadata;
use Espo\Modules\Sales\Entities\InventoryTransaction;
use Espo\Modules\Sales\Entities\ReceiptOrder;
use Espo\Modules\Sales\Entities\ReceiptOrderItem;
use Espo\ORM\EntityManager;

class ReceiptOrderProcessor
{
    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
        private TransactionManager $transactionManager
    ) {}

    public function processSave(ReceiptOrder $receiptOrder): void
    {
        $this->startTransactionAndLock($receiptOrder);

        if ($this->toTransfer($receiptOrder)) {
            $this->transactionManager->adjustIn($receiptOrder, InventoryTransaction::TYPE_TRANSFER);
        }
        else {
            $this->transactionManager->adjustIn($receiptOrder);
        }

        $this->entityManager
            ->getTransactionManager()
            ->commit();
    }

    public function processRemove(ReceiptOrder $receiptOrder): void
    {
        $this->transactionManager->adjustIn($receiptOrder);
    }

    private function toTransfer(ReceiptOrder $salesOrder): bool
    {
        if (!in_array($salesOrder->getStatus(), $this->getDoneStatusList())) {
            return false;
        }

        return true;
    }

    private function startTransactionAndLock(ReceiptOrder $receiptOrder): void
    {
        $this->entityManager
            ->getTransactionManager()
            ->start();

        $this->entityManager
            ->getRDBRepository(ReceiptOrder::ENTITY_TYPE)
            ->sth()
            ->select('id')
            ->forUpdate()
            ->where(['id' => $receiptOrder->getId()])
            ->find();

        $this->entityManager
            ->getRDBRepository(ReceiptOrderItem::ENTITY_TYPE)
            ->sth()
            ->select('id')
            ->forUpdate()
            ->where(['receiptOrderId' => $receiptOrder->getId()])
            ->find();

        $this->entityManager
            ->getRDBRepository(InventoryTransaction::ENTITY_TYPE)
            ->sth()
            ->select('id')
            ->forUpdate()
            ->where(['parentType' => ReceiptOrder::ENTITY_TYPE])
            ->where(['parentId' => $receiptOrder->getId()])
            ->find();
    }

    /**
     * @return string[]
     */
    private function getDoneStatusList(): array
    {
        return $this->metadata->get('scopes.ReceiptOrder.doneStatusList', []);
    }
}
