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
use stdClass;

class BpmnProcess extends Entity
{
    public const ENTITY_TYPE = 'BpmnProcess';

    public const STATUS_CREATED = 'Created';
    public const STATUS_STARTED = 'Started';
    public const STATUS_ENDED = 'Ended';
    public const STATUS_STOPPED = 'Stopped';
    public const STATUS_INTERRUPTED = 'Interrupted';
    public const STATUS_PAUSED = 'Paused';

    public function getStatus(): ?string
    {
        return $this->get('status');
    }

    public function getStartElementId(): ?string
    {
        return $this->get('startElementId');
    }

    public function getTargetId(): ?string
    {
        return $this->get('targetId');
    }

    public function getTargetType(): ?string
    {
        return $this->get('targetType');
    }

    public function isSubProcess(): bool
    {
        return $this->hasParentProcess();
    }

    public function hasParentProcess(): bool
    {
        return $this->getParentProcessId() && $this->getParentProcessFlowNodeId();
    }

    public function getParentProcessId(): ?string
    {
        return $this->get('parentProcessId');
    }

    public function getParentProcessFlowNodeId(): ?string
    {
        return $this->get('parentProcessFlowNodeId');
    }

    public function getRootProcessId(): ?string
    {
        return $this->get('rootProcessId');
    }

    public function getFlowchartId(): ?string
    {
        return $this->get('flowchartId');
    }

    public function getVariables(): ?stdClass
    {
        return $this->get('variables');
    }

    /**
     * @param bool $notSorted
     * @return string[]
     */
    public function getElementIdList(bool $notSorted = false)
    {
        $elementsDataHash = $this->get('flowchartElementsDataHash');

        if (!$elementsDataHash) {
            $elementsDataHash = (object) [];
        }

        $elementIdList = array_keys(get_object_vars($elementsDataHash));

        if ($notSorted) {
            return $elementIdList;
        }

        usort($elementIdList, function ($id1, $id2) use ($elementsDataHash) {
            $item1 = $elementsDataHash->$id1;
            $item2 = $elementsDataHash->$id2;

            if (isset($item1->center) && isset($item2->center)) {
                if ($item1->center->y > $item2->center->y) {
                    return 1;
                }

                if ($item1->center->y == $item2->center->y) {
                    if ($item1->center->x > $item2->center->x) {
                        return 1;
                    }
                }
            }

            return 0;
        });

        return $elementIdList;
    }

    /**
     * @return string[]
     */
    public function getElementNextIdList(string $id): array
    {
        $item = $this->getElementDataById($id);

        if (!$item) {
            return [];
        }

        return $item->nextElementIdList ?? [];
    }

    public function getElementDataById(string $id): ?stdClass
    {
        if (!$id) {
            return null;
        }

        $elementsDataHash = $this->get('flowchartElementsDataHash');

        if (!$elementsDataHash) {
            $elementsDataHash = (object) [];
        }

        if (!property_exists($elementsDataHash, $id)) {
            return null;
        }

        return $elementsDataHash->$id;
    }

    /**
     * @return string[]
     */
    public function getAttachedToFlowNodeElementIdList(BpmnFlowNode $flowNode): array
    {
        $elementIdList = [];

        foreach ($this->getElementIdList() as $id) {
            $item = $this->getElementDataById($id);

            if (!isset($item->attachedToId)) {
                continue;
            }

            if ($item->attachedToId === $flowNode->getElementId()) {
                $elementIdList[] = $id;
            }
        }

        return $elementIdList;
    }

    public function setVariables(stdClass $variables): self
    {
        $this->set('variables', $variables);

        return $this;
    }
}
