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

namespace Espo\Modules\Advanced\Core\Workflow\Actions;

use DateTimeImmutable;
use Espo\Core\Exceptions\Error;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\DateTime;
use Espo\Entities\Attachment;
use Espo\Modules\Advanced\Core\Workflow\Utils;
use Espo\ORM\Entity;
use Espo\Repositories\Attachment as AttachmentRepository;
use stdClass;

abstract class BaseEntity extends Base
{
    /**
     * Get value of a field by a field name.
     *
     * @throws Error
     */
    protected function getValue(string $fieldName, Entity $filledEntity): mixed
    {
        $actionData = $this->getActionData();
        $entity = $this->getEntity();

        if (!isset($actionData->fields->$fieldName)) {
            return null;
        }

        $fieldParams = $actionData->fields->$fieldName;

        $fieldValue = null;

        switch ($fieldParams->subjectType) {
            case 'value':
                if (isset($fieldParams->attributes) && is_object($fieldParams->attributes)) {
                    $fieldValue = $fieldParams->attributes;
                }

                break;

            case 'field':
                $fieldValue = $this->getEntityHelper()->getFieldValues(
                    $entity,
                    $filledEntity,
                    $fieldParams->field,
                    $fieldName
                );

                $toShift = isset($fieldParams->shiftDays) || isset($fieldParams->shiftUnit);

                if ($toShift) {
                    $shiftDays = $fieldParams->shiftDays ?? 0;
                    $shiftUnit = $fieldParams->shiftUnit ?? null;
                    $timezone = $this->getConfig()->get('timeZone');

                    foreach (get_object_vars($fieldValue) as $attribute => $value) {
                        $attributeType = $filledEntity->getAttributeType($attribute);

                        $fieldValue->$attribute = Utils::shiftDays(
                            $shiftDays,
                            $value,
                            $attributeType,
                            $shiftUnit,
                            $timezone
                        );
                    }
                }

                break;

            case 'today':
                $attributeType = Utils::getAttributeType($filledEntity, $fieldName);
                $shiftUnit = $fieldParams->shiftUnit ?? 'days';
                $timezone = $this->getConfig()->get('timeZone');

                return Utils::shiftDays(
                    $fieldParams->shiftDays,
                    null,
                    $attributeType,
                    $shiftUnit,
                    $timezone
                );

            default:
                throw new Error( "Workflow[{$this->getWorkflowId()}]: Unknown fieldName for a field '$fieldName'.");
        }

        return $fieldValue;
    }

    /**
     * Get data to fill.
     *
     * @param array<string, mixed>|stdClass|null $fields
     * @return array<string, mixed>
     */
    protected function getDataToFill(Entity $entity, $fields): array
    {
        $data = [];

        if (empty($fields)) {
            return $data;
        }

        if (!$entity instanceof CoreEntity) {
            return $data;
        }

        $metadataFields = $this->getMetadata()->get(['entityDefs', $entity->getEntityType(), 'fields']);
        $metadataFieldList = array_keys($metadataFields);

        foreach ($fields as $field => $fieldParams) {
            $fieldType = $this->getEntityHelper()->getFieldType($entity, $field);

            if ($fieldType === 'attachmentMultiple') {
                $data = $this->getDataToFillAttachmentMultiple($field, $entity, $data);

                continue;
            }

            if (
                $entity->hasRelation($field) ||
                $entity->hasAttribute($field) ||
                in_array($field, $metadataFieldList)
            ) {
                $fieldValue = $this->getValue($field, $entity);

                if (is_object($fieldValue)) {
                    $data = array_merge($data, get_object_vars($fieldValue));
                }
                else {
                    $data[$field] = $fieldValue;
                }
            }
        }

        foreach ($fields as $field => $fieldParams) {
            $fieldType = $this->getEntityHelper()->getFieldType($entity, $field);

            if ($fieldType === 'duration') {
                $this->fillDataDuration($field, $entity, $data);
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function getDataToFillAttachmentMultiple(string $field, CoreEntity $entity, array $data): array
    {
        if (!$entity->hasLinkMultipleField($field)) {
            return $data;
        }

        $copiedIdList = [];
        $idListFieldName = $field . 'Ids';

        $attachmentData = $this->getValue($field, $entity);

        /** @var AttachmentRepository $repository */
        $repository = $this->getEntityManager()->getRepository(Attachment::ENTITY_TYPE);

        if (!empty($attachmentData) && is_array($attachmentData->$idListFieldName)) {
            foreach ($attachmentData->$idListFieldName as $attachmentId) {
                /** @var ?Attachment $attachment */
                $attachment = $this->getEntityManager()->getEntityById(Attachment::ENTITY_TYPE, $attachmentId);

                if (!$attachment) {
                    continue;
                }

                $attachment = $repository->getCopiedAttachment($attachment);
                $attachment->set('field', $field);

                $this->getEntityManager()->saveEntity($attachment);

                $copiedIdList[] = $attachment->getId();
            }
        }

        $attachmentData->$idListFieldName = $copiedIdList;

        return array_merge($data, get_object_vars($attachmentData));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fillDataDuration(string $field, CoreEntity $entity, array &$data): void
    {
        $entityType = $entity->getEntityType();

        $duration = $data[$field];

        if (!is_int($duration)) {
            return;
        }

        $startField = $this->getMetadata()->get("entityDefs.$entityType.fields.$field.start");
        $endField = $this->getMetadata()->get("entityDefs.$entityType.fields.$field.end");

        $startDateAttribute = $startField . 'Date';
        $endDateAttribute = $endField . 'Date';

        $start = $data[$startField] ?? null;
        $startDate =$data[$startDateAttribute] ?? null;

        if ($start) {
            $dateEnd = (new DateTimeImmutable($start))
                ->modify("+$duration seconds")
                ->format(DateTime::SYSTEM_DATE_TIME_FORMAT);

            $data[$endField] = $dateEnd;
        }

        if ($startDate) {
            $days = floor($duration / (3600 * 24));

            $dateEndDate = (new DateTimeImmutable($startDate))
                ->modify("+$days days")
                ->format(DateTime::SYSTEM_DATE_FORMAT);

            $data[$endDateAttribute] = $dateEndDate;
        }
    }
}
