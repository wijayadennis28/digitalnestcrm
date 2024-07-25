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

/** @noinspection PhpUnused */

namespace Espo\Modules\Sales\Tools\Sales;

use stdClass;

class ReceivedOrderItem extends OrderItem
{
    private ?float $quantityReceived = null;

    public function withQuantityReceived(?float $quantityReceived): self
    {
        $obj = clone $this;
        $obj->quantityReceived = $quantityReceived;

        return $obj;
    }

    public function getQuantityReceived(): ?float
    {
        return $this->quantityReceived;
    }

    public function with(string $name, mixed $value): self
    {
        if ($name === 'quantityReceived') {
            return $this->withQuantityReceived($value);
        }

        return parent::with($name, $value);
    }

    public static function fromRaw(object $raw): static
    {
        $rawCloned = clone $raw;
        unset($rawCloned->quantityReceived);

        return parent::fromRaw($rawCloned)
            ->withQuantityReceived($raw->quantityReceived ?? null);
    }

    public function toRaw(): stdClass
    {
        $raw = parent::toRaw();
        $raw->quantityReceived = $this->quantityReceived;

        return $raw;
    }
}
