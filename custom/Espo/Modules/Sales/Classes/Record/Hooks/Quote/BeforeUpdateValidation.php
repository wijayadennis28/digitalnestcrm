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

namespace Espo\Modules\Sales\Classes\Record\Hooks\Quote;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Record\Hook\UpdateHook;
use Espo\Core\Record\UpdateParams;
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\Modules\Sales\Tools\Sales\OrderEntity;
use Espo\Modules\Sales\Tools\Sales\RecordValidator;
use Espo\ORM\Entity;

/**
 * @noinspection PhpUnused
 * @implements UpdateHook<Entity>
 */
class BeforeUpdateValidation implements UpdateHook
{
    public function __construct(
        private RecordValidator $recordValidator
    ) {}

    /**
     * @param Opportunity|OrderEntity $entity
     * @throws Conflict
     * @throws BadRequest
     * @noinspection PhpDocSignatureInspection
     */
    public function process(Entity $entity, UpdateParams $params): void
    {
        if (
            !$entity->isAttributeChanged('status') &&
            !$entity->isAttributeChanged('itemList')
        ) {
            return;
        }

        $this->recordValidator->process($entity);
    }
}
