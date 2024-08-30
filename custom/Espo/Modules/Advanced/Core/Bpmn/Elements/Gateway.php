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

abstract class Gateway extends Base
{
    public function process(): void
    {
        if ($this->isDivergent()) {
            $this->processDivergent();

            return;
        }

        if ($this->isConvergent()) {
            $this->processConvergent();

            return;
        }

        $this->setFailed();
    }

    abstract protected function processDivergent(): void;

    abstract protected function processConvergent(): void;

    protected function isDivergent(): bool
    {
        $nextElementIdList = $this->getAttributeValue('nextElementIdList') ?? [];

        return !$this->isConvergent() && count($nextElementIdList);
    }

    protected function isConvergent(): bool
    {
        $previousElementIdList = $this->getAttributeValue('previousElementIdList') ?? [];

        return is_array($previousElementIdList) && count($previousElementIdList) > 1;
    }

    private function checkElementsBelongSingleFlowRecursive(
        $divergentElementId,
        $forkElementId,
        $currentElementId,
        bool &$result,
        &$metElementIdList = null
    ): void {

        if ($divergentElementId === $currentElementId) {
            return;
        }

        if ($forkElementId === $currentElementId) {
            $result = true;

            return;
        }

        if (!$metElementIdList) {
            $metElementIdList = [];
        }

        $flowchartElementsDataHash = $this->getProcess()->get('flowchartElementsDataHash');

        $elementData = $flowchartElementsDataHash->$currentElementId;

        if (!isset($elementData->previousElementIdList)) {
            return;
        }

        foreach ($elementData->previousElementIdList as $elementId) {
            if (in_array($elementId, $metElementIdList)) {
                continue;
            }

            $this->checkElementsBelongSingleFlowRecursive(
                $divergentElementId,
                $forkElementId,
                $elementId,
                $result,
                $metElementIdList
            );
        }
    }

    protected function checkElementsBelongSingleFlow(
        $divergentElementId,
        $forkElementId,
        $elementId
    ): bool {

        $result = false;

        $this->checkElementsBelongSingleFlowRecursive($divergentElementId, $forkElementId, $elementId, $result);

        return $result;
    }
}
