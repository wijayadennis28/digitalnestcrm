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

namespace Espo\Modules\Sales\Classes\App;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;

use Espo\Entities\Extension;

class JobRunner implements Job
{
    public function __construct(
        private Config $config,
        private EntityManager $entityManager
    ) {}

    private $name = 'Sales Pack';

    public function run(Data $data): void
    {
        $current = $this->entityManager
            ->getRDBRepository(Extension::ENTITY_TYPE)
            ->where([
                'name' => $this->name,
            ])
            ->order('createdAt', true)
            ->findOne();

        $responseData = $this->validate($this->getData($current));

        $status = $responseData['status'] ?? null;
        $statusMessage = $responseData['statusMessage'] ?? null;

        if (!$status) {
            return;
        }

        if (!$current) {
            return;
        }

        if (!$current->has('licenseStatus')) {
            return;
        }

        if (
            $current->get('licenseStatus') == $status &&
            $current->get('licenseStatusMessage') == $statusMessage
        ) {
            return;
        }

        $current->set([
            'licenseStatus' => $status,
            'licenseStatusMessage' => $statusMessage,
        ]);

        $this->entityManager->saveEntity($current, [
            'skipAll' => true,
        ]);
    }

    private function getData(?Extension $current): array
    {
        $first = $this->entityManager
            ->getRDBRepository(Extension::ENTITY_TYPE)
            ->select(['createdAt'])
            ->where([
                'name' => $this->name,
            ])
            ->order('createdAt')
            ->findOne(['withDeleted' => true]);

        return [
            'id' => 'bcd3361258b6d66fc350488ed9575786',
            'name' => $this->name,
            'version' => $current?->get('version'),
            'updatedAt' => $current?->get('createdAt'),
            'installedAt' => $first ? $first->get('createdAt') : null,
            'site' => $this->config->get('siteUrl'),
            'espoVersion' => $this->config->get('version'),
            'applicationName' => $this->config->get('applicationName'),
        ];
    }

    private function validate(array $data): ?array
    {
        if (!function_exists('curl_version')) {
            return null;
        }

        $ch = curl_init();

        $payload = json_encode($data);
        curl_setopt($ch, CURLOPT_URL, base64_decode('aHR0cHM6Ly9zLmVzcG9jcm0uY29tL2xpY2Vuc2Uv'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return json_decode($result, true);
        }

        return null;
    }
}
