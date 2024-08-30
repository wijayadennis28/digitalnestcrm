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

namespace Espo\Modules\Advanced\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\Modules\Advanced\Core\Bpmn\BpmnManager;

use Exception;

class ProcessPendingProcessFlows implements JobDataLess
{
    private BpmnManager $bpmnManager;
    private Log $log;

    public function __construct(
        BpmnManager $bpmnManager,
        Log $log
    ) {
        $this->bpmnManager = $bpmnManager;
        $this->log = $log;
    }

    public function run(): void
    {
        try {
            $this->bpmnManager->processPendingFlows();
        }
        catch (Exception $e) {
            $this->log->error("ProcessPendingProcessFlows: {$e->getCode()}, {$e->getMessage()}");
        }
    }
}
