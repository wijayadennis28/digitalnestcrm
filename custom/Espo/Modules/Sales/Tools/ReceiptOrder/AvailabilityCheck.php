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

namespace Espo\Modules\Sales\Tools\ReceiptOrder;

use Espo\Modules\Sales\Entities\ReceiptOrder;
use Espo\Modules\Sales\Tools\InventoryAdjustment\InAdjustmentCheck;

class AvailabilityCheck
{
    public function __construct(
        private InAdjustmentCheck $inAdjustmentCheck
    ) {}

    public function checkNotBeingAdjusted(ReceiptOrder $order): bool
    {
        return $this->inAdjustmentCheck->check($order);
    }
}
