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

namespace Espo\Modules\Advanced\Core\Workflow\Conditions;

use Espo\Modules\Advanced\Core\Workflow\Utils;
use Espo\ORM\Entity;

class Equals extends Base
{
    /**
     * @param mixed $fieldValue
     */
    protected function compare($fieldValue): bool
    {
        $subjectValue = $this->getSubjectValue();

        return ($fieldValue == $subjectValue);
    }

    protected function compareComplex(Entity $entity, \stdClass $condition): bool
    {
        if (empty($condition->fieldValueMap)) {
            return false;
        }

        $fieldValueMap = $condition->fieldValueMap;

        foreach ($fieldValueMap as $field => $value) {
            $v = Utils::getFieldValue($entity, $field, false, $this->getEntityManager(), $this->createdEntitiesData);

            if ($v !== $value) {
                return false;
            }
        }

        return true;
    }
}
