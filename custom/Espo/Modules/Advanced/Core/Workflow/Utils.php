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

namespace Espo\Modules\Advanced\Core\Workflow;

use DateTime;
use DateTimeZone;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\DateTime as DateTimeUtil;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use RuntimeException;
use stdClass;

class Utils
{
    /**
     * String to lower case.
     * @todo Revise. Remove?
     */
    public static function strtolower(?string $str): ?string
    {
        if (!empty($str)) {
            return mb_strtolower($str, 'UTF-8');
        }

        return $str;
    }

    /**
     * Shift date days.
     *
     * @param int $shiftDays
     * @param ?string $input
     * @param 'datetime'|'date' $type
     * @param string $unit
     * @param ?string $timezone
     * @return string
     */
    public static function shiftDays(
        $shiftDays = 0,
        $input = null,
        $type = 'datetime',
        $unit = 'days',
        $timezone = null
    ): string {

        if (!in_array($unit, ['hours', 'minutes', 'days', 'months'])) {
            throw new RuntimeException("Not supported date shift interval unit $unit.");
        }

        $dateTime = new DateTime($input);
        $dateTime->setTimezone(new DateTimeZone($timezone ?? 'UTC'));

        if ($type === 'date') {
            $dateTime->setTime(0, 0);
        }

        if ($shiftDays) {
            $dateTime->modify("$shiftDays $unit");
        }

        if ($type === 'datetime') {
            $dateTime->setTimezone(new DateTimeZone('UTC'));

            return $dateTime->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT);
        }

        $dateTime->setTime(0, 0);

        return $dateTime->format(DateTimeUtil::SYSTEM_DATE_FORMAT);
    }

    /**
     * @deprecated
     *
     * Get field value for a field/related field. If this field has a relation, get value from the relation.
     *
     * @param ?string $fieldName
     * @param bool $returnEntity
     * @param ?EntityManager $entityManager
     * @param ?stdClass $createdEntitiesData
     * @return mixed
     */
    public static function getFieldValue(
        Entity $entity,
        $fieldName,
        $returnEntity = false,
        $entityManager = null,
        $createdEntitiesData = null
    ) {

        if (str_starts_with($fieldName, 'created:')) {
            [$alias, $field] = explode('.', substr($fieldName, 8));

            if (!$createdEntitiesData) {
                return null;
            }

            if (!isset($createdEntitiesData->$alias)) {
                return null;
            }

            $entity = $entityManager
                ->getEntity($createdEntitiesData->$alias->entityType, $createdEntitiesData->$alias->entityId);

            if (!$entity) {
                return null;
            }

            $fieldName = $field;
        }
        else if (str_contains($fieldName, '.')) {
            list($entityFieldName, $relatedEntityFieldName) = explode('.', $fieldName);

            $relatedEntity = $entity->get($entityFieldName);

            // If entity is just created and doesn't have added relations.
            if (
                isset($entityManager) &&
                !isset($relatedEntity) &&
                $entity->hasRelation($entityFieldName)
            ) {
                $foreignEntityType = $entity->getRelationParam($entityFieldName, 'entity');

                $normalizedEntityFieldName = static::normalizeFieldName($entity, $entityFieldName);

                if (
                    $foreignEntityType &&
                    $entity->hasAttribute($normalizedEntityFieldName) &&
                    $entity->get($normalizedEntityFieldName)
                ) {
                    $relatedEntity = $entityManager
                        ->getEntity($foreignEntityType, $entity->get($normalizedEntityFieldName));
                }
            }

            if ($relatedEntity instanceof Entity) {
                $entity = $relatedEntity;

                $fieldName = $relatedEntityFieldName;
            }
            else {
                $GLOBALS['log']->error(
                    'Workflow [Utils::getFieldValue]: The related field [' . $fieldName . '] entity [' .
                    $entity->getEntityType() . '] has unsupported instance [' .
                    (isset($relatedEntity) ? get_class($relatedEntity) : var_export($relatedEntity, true)) .
                    '].'
                );

                return null;
            }
        }

        if ($entity->hasRelation($fieldName)) {
            $relatedEntity = null;

            if ($entity->getRelationType($fieldName) === 'belongsToParent') {
                if ($entity->get($fieldName . 'Type') && $entity->get($fieldName . 'Id')) {
                    $relatedEntity = $entityManager
                        ->getEntity($entity->get($fieldName . 'Type'), $entity->get($fieldName . 'Id'));
                }
            }
            else {
                $relatedEntity = $entity->get($fieldName);
            }

            if ($relatedEntity instanceof Entity) {
                $foreignKey = Utils::getRelationOption($entity, 'foreignKey', $fieldName, 'id');

                return $returnEntity ? $relatedEntity : $relatedEntity->get($foreignKey);
            }

            if (!isset($relatedEntity)) {
                $normalizedFieldName = static::normalizeFieldName($entity, $fieldName);

                if (!$entity->isNew()) {
                    if ($entity instanceof CoreEntity && $entity->hasLinkMultipleField($fieldName)) {
                        $entity->loadLinkMultipleField($fieldName);
                    }
                }

                $fieldValue = $returnEntity ?
                    static::getParentEntity($entity, $fieldName, $entityManager) :
                    static::getParentValue($entity, $normalizedFieldName);

                if (isset($fieldValue)) {
                    return $fieldValue;
                }
            }

            if ($entity instanceof CoreEntity && $entity->hasLinkMultipleField($fieldName)) {
                $entity->loadLinkMultipleField($fieldName);
            }

            return $returnEntity ? null : $entity->get($fieldName . 'Ids');
        }

        switch ($entity->getAttributeType($fieldName)) {
            // @todo Revise.
            case 'linkParent':
                $fieldName .= 'Id';

                break;
        }

        if ($returnEntity) {
            return $entity;
        }

        if ($entity->hasAttribute($fieldName)) {
            return $entity->get($fieldName);
        }

        return null;
    }

    /**
     * Get parent field value. Works for parent and regular fields,
     *
     * @param string|string[] $normalizedFieldName
     * @return mixed
     */
    public static function getParentValue(Entity $entity, $normalizedFieldName)
    {
        if (is_array($normalizedFieldName)) {
            $value = [];

            foreach ($normalizedFieldName as $fieldName) {
                if ($entity->hasAttribute($fieldName)) {
                    $value[$fieldName] = $entity->get($fieldName);
                }
            }

            return $value;
        }

        if ($entity->hasAttribute($normalizedFieldName)) {
            return $entity->get($normalizedFieldName);
        }

        return null;
    }

    public static function getParentEntity(Entity $entity, $fieldName, $entityManager)
    {
        if (!$entity->hasRelation($fieldName)) {
            return $entity;
        }

        if ($entityManager instanceof \Espo\Core\ORM\EntityManager) {
            $normalizedFieldName = static::normalizeFieldName($entity, $fieldName);

            $fieldValue = static::getParentValue($entity, $normalizedFieldName);

            if (isset($fieldValue) && is_string($fieldValue)) {
                $fieldEntityDefs = $entityManager->getMetadata()->get($entity->getEntityType());

                if (isset($fieldEntityDefs['relations'][$fieldName]['entity'])) {
                    $fieldEntity = $fieldEntityDefs['relations'][$fieldName]['entity'];

                    return $entityManager->getEntity($fieldEntity, $fieldValue);
                }
            }
        }

        return null;
    }

    /**
     * @param string $field
     * @return string
     * @deprecated Use getActualAttributes in Helper.
     * Normalize field name for fields and relations.
     */
    public static function normalizeFieldName(Entity $entity, $field)
    {
        if ($entity->hasRelation($field)) {
            $type = $entity->getRelationType($field);

            $key = $entity->getRelationParam($field, 'key');

            switch ($type) {
                case 'belongsTo':
                    if ($key) {
                        $field = $key;
                    }

                    break;

                case 'belongsToParent':
                    $field = [
                        $field . 'Id',
                        $field . 'Type',
                    ];

                    break;

                case 'hasChildren':
                case 'hasMany':
                case 'manyMany':
                    $field .= 'Ids';

                    break;
            }

            return $field;
        }

        if ($entity->hasAttribute($field . 'Id')) {
            $fieldType = $entity->getAttributeParam($field . 'Id', 'fieldType');

            if ($fieldType === 'link' || $fieldType === 'linkParent') {
                $field = $field . 'Id';
            }
        }

        return $field;
    }

    /**
     * Get option value for the relation.
     *
     * @param string $optionName
     * @param string $relationName
     * @param Entity $entity
     * @param mixed $returns
     * @return mixed
     */
    public static function getRelationOption(Entity $entity, $optionName, $relationName, $returns = null)
    {
        if (!$entity->hasRelation($relationName)) {
            return $returns;
        }

        return $entity->getRelationParam($relationName, $optionName) ?? $returns;
    }

    public static function getAttributeType(Entity $entity, string $name): ?string
    {
        if (!$entity->hasAttribute($name)) {
            $name = static::normalizeFieldName($entity, $name);

            if (!is_string($name)) {
                return null;
            }
        }

        return $entity->getAttributeType($name);
    }
}
