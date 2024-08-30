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

namespace Espo\Modules\Advanced\Hooks\CampaignTrackingUrl;

use Espo\Modules\Advanced\Core\SignalManager;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class Signal
{
    public static $order = 100;

    private EntityManager $entityManager;
    private SignalManager $signalManager;

    public function __construct(
        EntityManager $entityManager,
        SignalManager $signalManager
    ) {
        $this->entityManager = $entityManager;
        $this->signalManager = $signalManager;
    }

    /** @noinspection PhpUnused */
    public function afterClick(Entity $entity, array $options, array $hookData): void
    {
        if (!empty($options['skipWorkflow'])) {
            return;
        }

        if (!empty($options['skipSignal'])) {
            return;
        }

        if (!empty($options['silent'])) {
            return;
        }

        $uid = $hookData['uid'] ?? null;

        if ($uid) {
            $this->signalManager->trigger(
                implode('.', ['clickUniqueUrl', $uid])
            );
        }

        $targetType = $hookData['targetType'] ?? null;
        $targetId = $hookData['targetId'] ?? null;

        if (!$targetType || !$targetId) {
            return;
        }

        $target = $this->entityManager->getEntityById($targetType, $targetId);

        if (!$target) {
            return;
        }

        $signalManager = $this->signalManager;

        $signalManager->trigger(implode('.', ['@clickUrl', $entity->getId()]), $target);
        $signalManager->trigger(implode('.', ['@clickUrl']), $target);

        $signalManager->trigger(implode('.', ['clickUrl', $targetType, $targetId, $entity->getId()]));
        $signalManager->trigger(implode('.', ['clickUrl', $targetType, $targetId]));
    }
}
