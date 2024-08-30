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

use Espo\Core\Container;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Utils\FieldUtil;
use Espo\Core\Utils\Metadata;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

use Exception;
use stdClass;

class EntityHelper
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    private function getEntityManager(): EntityManager
    {
        /** @var EntityManager */
        return $this->container->get('entityManager');
    }

    private function getRecordServiceContainer(): ServiceContainer
    {
        /** @var ServiceContainer */
        return $this->container->get('recordServiceContainer');
    }

    private function getMetadata(): Metadata
    {
        /** @var Metadata */
        return $this->container->get('metadata');
    }

    private function getFieldUtil(): FieldUtil
    {
        /** @var FieldUtil */
        return $this->container->get('fieldUtil');
    }

    private function normalizeRelatedFieldName(Entity $entity, $fieldName)
    {
        if ($entity->hasRelation($fieldName)) {
            $type = $entity->getRelationType($fieldName);

            $key = $entity->getRelationParam($fieldName, 'key');
            $foreignKey = $entity->getRelationParam($fieldName, 'foreignKey');

            switch ($type) {
                case Entity::HAS_CHILDREN:
                    if ($foreignKey) {
                        $fieldName = $foreignKey;
                    }

                    break;

                case Entity::BELONGS_TO:
                    if ($key) {
                        $fieldName = $key;
                    }

                    break;

                case Entity::HAS_MANY:
                case Entity::MANY_MANY:
                    $fieldName .= 'Ids';

                    break;
            }
        }

        return $fieldName;
    }

    /**
     * Get actual attribute list w/o additional.
     *
     * @param Entity $entity
     * @param string $field
     * @return string[]
     */
    public function getActualAttributes(Entity $entity, string $field): array
    {
        $entityType = $entity->getEntityType();

        $fieldUtil = $this->getFieldUtil();

        $list = [];
        $actualList = $fieldUtil->getActualAttributeList($entityType, $field);
        $additionalList = $fieldUtil->getAdditionalActualAttributeList($entityType, $field);

        foreach ($actualList as $item) {
            if (!in_array($item, $additionalList)) {
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * Get field value for a field/related field. If this field has a relation, get value from the relation.
     */
    public function getFieldValues(
        Entity $fromEntity,
        Entity $toEntity,
        string $fromField,
        string $toField
    ): stdClass {

        $entity = $fromEntity;
        $field = $fromField;

        $values = (object) [];

        if (strstr($field, '.')) {
            [$relation, $foreignField] = explode('.', $field);

            $relatedEntity = $this->getRelatedEntity($entity, $relation);

            if (!$relatedEntity) {
                $GLOBALS['log']->debug(
                    "Workflow EntityHelper:getFieldValues: No related record for '$field', entity " .
                    "{$entity->getEntityType()}.");

                return (object) [];
            }

            $entity = $relatedEntity;
            $field = $foreignField;
        }

        if ($entity->hasRelation($field) && !$entity->isNew()) {
            $this->loadLink($entity, $field);
        }

        $attributeMap = $this->getRelevantAttributeMap($entity, $toEntity, $field, $toField);

        $service = $this->getRecordServiceContainer()->get($entity->getEntityType());

        $toAttribute = null;

        foreach ($attributeMap as $fromAttribute => $toAttribute) {
            // @todo Revise.
            $getCopiedMethodName = 'getCopied' . ucfirst($fromAttribute);

            if (method_exists($entity, $getCopiedMethodName)) {
                $values->$toAttribute = $entity->$getCopiedMethodName();

                continue;
            }

            // @todo Revise.
            $getCopiedMethodName = 'getCopiedEntityAttribute' . ucfirst($fromAttribute);

            if (method_exists($service, $getCopiedMethodName)) {
                $values->$toAttribute = $service->$getCopiedMethodName($entity);

                continue;
            }

            $values->$toAttribute = $entity->get($fromAttribute);
        }

        $toFieldType = $this->getFieldType($toEntity, $toField);

        if ($toFieldType === 'personName' && $toAttribute) {
            $this->handlePersonName($toAttribute, $values, $toField);
        }

        // Correct field types. E.g. set teamsIds from defaultTeamId.
        if ($toEntity->hasRelation($toField)) {
            $normalizedFieldName = $this->normalizeRelatedFieldName($toEntity, $toField);

            if (
                $toEntity->getRelationType($toField) === Entity::MANY_MANY &&
                isset($values->$normalizedFieldName) &&
                !is_array($values->$normalizedFieldName)
            ) {
                $values->$normalizedFieldName = (array)$values->$normalizedFieldName;
            }
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    private function getRelevantAttributeMap(
        Entity $entity1,
        Entity $entity2,
        string $field1,
        string $field2
    ): array {

        $attributeList1 = $this->getActualAttributes($entity1, $field1);
        $attributeList2 = $this->getActualAttributes($entity2, $field2);

        $fieldType1 = $this->getFieldType($entity1, $field1);
        $fieldType2 = $this->getFieldType($entity2, $field2);

        $ignoreActualAttributesOnValueCopyFieldList = $this->getMetadata()
            ->get(['entityDefs', 'Workflow', 'ignoreActualAttributesOnValueCopyFieldList'], []);

        if (in_array($fieldType1, $ignoreActualAttributesOnValueCopyFieldList)) {
            $attributeList1 = [$field1];
        }

        if (in_array($fieldType2, $ignoreActualAttributesOnValueCopyFieldList)) {
            $attributeList2 = [$field2];
        }

        $attributeMap = [];

        if (count($attributeList1) == count($attributeList2)) {
            if (
                $fieldType1 === 'datetimeOptional' &&
                $fieldType2 === 'datetimeOptional'
            ) {
                if ($entity1->get($attributeList1[1])) {
                    $attributeMap[$attributeList1[1]] = $attributeList2[1];
                } else {
                    $attributeMap[$attributeList1[0]] = $attributeList2[0];
                }

                return $attributeMap;
            }

            foreach ($attributeList1 as $key => $name) {
                $attributeMap[$name] = $attributeList2[$key];
            }

            return $attributeMap;
        }

        if (
            $fieldType1 === 'datetimeOptional' ||
            $fieldType2 === 'datetimeOptional'
        ) {
            if (count($attributeList2) > count($attributeList1)) {
                if ($fieldType1 === 'date') {
                    $attributeMap[$attributeList1[0]] = $attributeList2[1];
                } else {
                    $attributeMap[$attributeList1[0]] = $attributeList2[0];
                }

                return $attributeMap;
            }

            if ($fieldType2 === 'date') {
                if ($entity1->get($attributeList1[1])) {
                    $attributeMap[$attributeList1[1]] = $attributeList2[0];
                } else {
                    $attributeMap[$attributeList1[0]] = $attributeList2[0];
                }
            } else {
                $attributeMap[$attributeList1[0]] = $attributeList2[0];
            }
        }

        return $attributeMap;
    }

    private function handlePersonName(string $toAttribute, stdClass $values, string $toField): void
    {
        if (empty($values->$toAttribute)) {
            return;
        }

        $fullNameValue = trim($values->$toAttribute);

        $firstNameAttribute = 'first' . ucfirst($toField);
        $lastNameAttribute = 'last' . ucfirst($toField);

        if (strpos($fullNameValue, ' ') === false) {
            $lastNameValue = $fullNameValue;
            $firstNameValue = null;
        } else {
            $index = strrpos($fullNameValue, ' ');
            $firstNameValue = substr($fullNameValue, 0, $index);
            $lastNameValue = substr($fullNameValue, $index + 1);
        }

        $values->$firstNameAttribute = $firstNameValue;
        $values->$lastNameAttribute = $lastNameValue;
    }

    private function loadLink(Entity $entity, string $field): void
    {
        if (!$entity instanceof CoreEntity) {
            return;
        }

        switch ($entity->getRelationType($field)) { // ORM types
            case Entity::MANY_MANY:
            case Entity::HAS_CHILDREN:
                try {
                    $entity->loadLinkMultipleField($field);
                }
                catch (Exception $e) {
                }

                break;

            case Entity::BELONGS_TO:
            case Entity::HAS_ONE:
                try {
                    $entity->loadLinkField($field);
                }
                catch (Exception $e) {
                }

                break;
        }
    }

    public function getFieldType(Entity $entity, string $field): ?string
    {
        return $this->getMetadata()->get(['entityDefs', $entity->getEntityType(), 'fields', $field, 'type']);
    }

    private function getRelatedEntity(Entity $entity, string $relation): ?Entity
    {
        if (!$entity->hasRelation($relation)) {
            return null;
        }

        $relatedEntity = null;

        if ($entity->hasId()) {
            $relatedEntity = $this->getEntityManager()
                ->getRDBRepository($entity->getEntityType())
                ->getRelation($entity, $relation)
                ->findOne();

            if ($relatedEntity) {
                return $relatedEntity;
            }
        }

        // If the entity is just created and doesn't have relations yet.

        $foreignEntityType = $entity->getRelationParam($relation, 'entity');
        $idAttribute = $this->normalizeRelatedFieldName($entity, $relation);

        if (
            $foreignEntityType &&
            $entity->hasAttribute($idAttribute) &&
            $entity->get($idAttribute)
        ) {
            $relatedEntity = $this->getEntityManager()->getEntityById($foreignEntityType, $entity->get($idAttribute));
        }

        return $relatedEntity;
    }
}
