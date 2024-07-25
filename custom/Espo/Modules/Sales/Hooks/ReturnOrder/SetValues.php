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

use Espo\Core\Utils\Metadata;
use Espo\Modules\Sales\Entities\ReturnOrder;
use Espo\ORM\Entity;

/**
 * @noinspection PhpUnused
 */
class SetValues
{
    public function __construct(
        private Metadata $metadata
    ) {}

    /**
     * @param ReturnOrder $entity
     * @noinspection PhpUnusedParameterInspection
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        $this->setStateValues($entity);
    }

    private function setStateValues(ReturnOrder $entity): void
    {
        if (!$entity->has('status')) {
            return;
        }

        $doneStatusList = $this->metadata->get("scopes.{$entity->getEntityType()}.doneStatusList") ?? [];
        $canceledStatusList = $this->metadata->get("scopes.{$entity->getEntityType()}.canceledStatusList") ?? [];

        $isNotActual = in_array($entity->getStatus(), array_merge(
            $doneStatusList,
            $canceledStatusList,
        ));

        $entity->set('isDone', in_array($entity->getStatus(), $doneStatusList));
        $entity->set('isNotActual', $isNotActual);
    }
}
