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

namespace Espo\Modules\Sales\Entities;

use Espo\Core\Field\Currency;
use Espo\Core\Field\Link;
use Espo\Core\ORM\Entity;

use RuntimeException;

/** @noinspection PhpUnused */
class ProductPrice extends Entity
{
    public const ENTITY_TYPE = 'ProductPrice';

    public const STATUS_ACTIVE = 'Active';
    public const STATUS_INACTIVE = 'Inactive';

    /** @noinspection PhpUnused */
    public function _hasName(): ?string
    {
        return $this->hasInContainer('productName');
    }

    /** @noinspection PhpUnused */
    public function _getName(): ?string
    {
        return $this->getFromContainer('productName');
    }

    public function getPrice(): Currency
    {
        /** @var ?Currency $value */
        $value = $this->getValueObject('price');

        if (!$value) {
            throw new RuntimeException("No price value in ProductPrice '$this->id'.");
        }

        return $value;
    }

    public function setPrice(Currency $price): self
    {
        $this->setValueObject('price', $price);

        return $this;
    }

    public function getPriceBook(): Link
    {
        /** @var ?Link $value */
        $value = $this->getValueObject('priceBook');

        if (!$value) {
            throw new RuntimeException("No price book in ProductPrice '$this->id'.");
        }

        return $value;
    }
}
