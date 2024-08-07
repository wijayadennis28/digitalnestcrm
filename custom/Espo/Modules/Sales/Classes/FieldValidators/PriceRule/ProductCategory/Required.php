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

namespace Espo\Modules\Sales\Classes\FieldValidators\PriceRule\ProductCategory;

use Espo\Core\FieldValidation\Validator;
use Espo\Core\FieldValidation\Validator\Data;
use Espo\Core\FieldValidation\Validator\Failure;
use Espo\Modules\Sales\Entities\PriceRule;
use Espo\ORM\Entity;

/**
 * @implements Validator<PriceRule>
 */
class Required implements Validator
{
    public function validate(Entity $entity, string $field, Data $data): ?Failure
    {
        if (!$entity->get('target')) {
            return null;
        }

        if ($entity->getTarget() !== PriceRule::TARGET_PRODUCT_CATEGORY) {
            return null;
        }

        if ($entity->get('productCategoryId')) {
            return null;
        }

        return Failure::create();
    }
}
