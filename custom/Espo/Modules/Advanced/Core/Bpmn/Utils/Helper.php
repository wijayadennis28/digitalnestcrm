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

namespace Espo\Modules\Advanced\Core\Bpmn\Utils;

use Espo\ORM\Entity;
use stdClass;

class Helper
{
    /**
     * @return array{
     *     elementsDataHash: stdClass,
     *     eventStartIdList: string[],
     *     eventStartAllIdList: string[]
     * }
     */
    public static function getElementsDataFromFlowchartData(stdClass $data): array
    {
        $elementsDataHash = (object) [];
        $eventStartIdList = [];
        $eventStartAllIdList = [];

        if (isset($data->list) && is_array($data->list)) {
            foreach ($data->list as $item) {
                if (!is_object($item)) {
                    continue;
                }

                if ($item->type === 'flow') {
                    continue;
                }

                $nextElementIdList = [];
                $previousElementIdList = [];

                foreach ($data->list as $itemAnother) {
                    if ($itemAnother->type !== 'flow') {
                        continue;
                    }

                    if (!isset($itemAnother->startId) || !isset($itemAnother->endId)) {
                        continue;
                    }

                    if ($itemAnother->startId === $item->id) {
                        $nextElementIdList[] = $itemAnother->endId;
                    } else if ($itemAnother->endId === $item->id) {
                        $previousElementIdList[] = $itemAnother->startId;
                    }
                }

                usort($nextElementIdList, function ($id1, $id2) use ($data) {
                    $item1 = self::getItemById($data, $id1);
                    $item2 = self::getItemById($data, $id2);

                    if (isset($item1->center) && isset($item2->center)) {
                        if ($item1->center->y > $item2->center->y) {
                            return true;
                        }

                        if ($item1->center->y == $item2->center->y) {
                            if ($item1->center->x > $item2->center->x) {
                                return true;
                            }
                        }
                    }

                    return false;
                });

                $id = $item->id;
                $o = clone $item;
                $o->nextElementIdList = $nextElementIdList;
                $o->previousElementIdList = $previousElementIdList;

                if (isset($item->flowList)) {
                    $o->flowList = [];

                    foreach ($item->flowList as $nextFlowData) {
                        $nextFlowDataCloned = clone $nextFlowData;

                        foreach ($data->list as $itemAnother) {
                            if ($itemAnother->id !== $nextFlowData->id) {
                                continue;
                            }

                            $nextFlowDataCloned->elementId = $itemAnother->endId;
                            break;
                        }

                        $o->flowList[] = $nextFlowDataCloned;
                    }
                }

                if (!empty($item->defaultFlowId)) {
                    foreach ($data->list as $itemAnother) {
                        if ($itemAnother->id !== $item->defaultFlowId) {
                            continue;
                        }

                        $o->defaultNextElementId = $itemAnother->endId;

                        break;
                    }
                }

                if ($item->type === 'eventStart') {
                    $eventStartIdList[] = $id;
                }

                if (strpos($item->type, 'eventStart') === 0) {
                    $eventStartAllIdList[] = $id;
                }

                $elementsDataHash->$id = $o;
            }
        }

        return [
            'elementsDataHash' => $elementsDataHash,
            'eventStartIdList' => $eventStartIdList,
            'eventStartAllIdList' => $eventStartAllIdList,
        ];
    }

    private static function getItemById(stdClass $data, string $id): ?stdClass
    {
        foreach ($data->list as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }

        return null;
    }

    public static function applyPlaceholders(string $text, Entity $target, stdClass $variables = null): string
    {
        foreach ($target->getAttributeList() as $attribute) {
            $value = $target->get($attribute);

            if ($value === null) {
                continue;
            }

            if (is_numeric($value)) {
                $value = (string) $value;
            }

            if (!is_string($value)) {
                continue;
            }

            $text = str_replace('{$' . $attribute . '}', $value, $text);
        }

        $variables = $variables ?? (object) [];

        foreach (get_object_vars($variables) as $key => $value) {
            if (is_numeric($value)) {
                $value = (string) $value;
            }

            if (!is_string($value)) {
                continue;
            }

            $text = str_replace('{$$' . $key . '}', $value, $text);
        }

        return $text;
    }
}
