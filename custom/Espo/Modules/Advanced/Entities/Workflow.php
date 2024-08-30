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
 * License ID: 02847865974db42443189e5f30908f60
 ************************************************************************************/

namespace Espo\Modules\Advanced\Entities;

use Espo\Core\ORM\Entity;

class Workflow extends Entity
{
    public const ENTITY_TYPE = 'Workflow';

    public const TYPE_MANUAL = 'manual';
    public const TYPE_SCHEDULED = 'scheduled';
    public const TYPE_SEQUENTIAL = 'sequential';
    public const TYPE_SIGNAL = 'signal';

    public function getName(): ?string
    {
        return $this->get('name');
    }

    public function getManualLabel(): ?string
    {
        return $this->get('manualLabel');
    }

    public function getType(): string
    {
        return $this->get('type');
    }

    public function getTargetEntityType(): string
    {
        return $this->get('entityType');
    }

    public function isActive(): bool
    {
        return $this->get('isActive');
    }

    public function getScheduling(): ?string
    {
        return $this->get('scheduling');
    }
}
