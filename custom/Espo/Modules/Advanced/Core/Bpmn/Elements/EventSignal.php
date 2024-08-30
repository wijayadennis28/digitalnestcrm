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

namespace Espo\Modules\Advanced\Core\Bpmn\Elements;

use Espo\Modules\Advanced\Core\Bpmn\Utils\Helper;
use Espo\Modules\Advanced\Core\SignalManager;

abstract class EventSignal extends Event
{
    protected function getSignalManager(): SignalManager
    {
        /** @var SignalManager */
        return $this->getContainer()->get('signalManager');
    }

    protected function getSignal(): ?string
    {
        $name = $this->getAttributeValue('signal');

        if (!$name) {
            return $name;
        }

        return Helper::applyPlaceholders($name, $this->getTarget(), $this->getVariables());
    }
}
