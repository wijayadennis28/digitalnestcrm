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

namespace Espo\Modules\Sales\Hooks\Product;

use Espo\Core\Utils\FieldUtil;
use Espo\Modules\Sales\Entities\Product;
use Espo\Modules\Sales\Tools\Product\MetadataProvider;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** @noinspection PhpUnused */
class TemplateSync
{
    public function __construct(
        private EntityManager $entityManager,
        private FieldUtil $fieldUtil,
        private MetadataProvider $metadataProvider
    ) {}

    /**
     * @param Product $entity
     * @noinspection PhpUnusedParameterInspection
     */
    public function afterSave(Entity $entity, array $options): void
    {
        if (
            $entity->getType() !== Product::TYPE_TEMPLATE ||
            $entity->isNew()
        ) {
            return;
        }

        $toProcess = false;

        $attributes = $this->getSyncAttributes();

        foreach ($attributes as $attribute) {
            if ($entity->isAttributeChanged($attribute)) {
                $toProcess = true;

                break;
            }
        }

        if (!$toProcess) {
            return;
        }

        $variants = $this->entityManager
            ->getRDBRepositoryByClass(Product::class)
            ->sth()
            ->where(['templateId' => $entity->getId()])
            ->find();

        foreach ($variants as $variant) {
            foreach ($attributes as $attribute) {
                $variant->set($attribute, $entity->get($attribute));
            }

            $this->entityManager->saveEntity($variant);
        }
    }

    /**
     * @param Product $entity
     * @noinspection PhpUnusedParameterInspection
     */
    public function afterRemove(Entity $entity, array $options): void
    {
        if ($entity->getType() !== Product::TYPE_TEMPLATE) {
            return;
        }

        $variants = $this->entityManager
            ->getRDBRepositoryByClass(Product::class)
            ->sth()
            ->where(['templateId' => $entity->getId()])
            ->find();

        foreach ($variants as $variant) {
            $this->entityManager->removeEntity($variant);
        }
    }

    /**
     * @return string[]
     */
    private function getSyncAttributes(): array
    {
        $fields = $this->metadataProvider->getVariantSyncFieldList();

        $attributes = [];

        foreach ($fields as $field) {
            $attributes = array_merge(
                $attributes,
                $this->fieldUtil->getActualAttributeList(Product::ENTITY_TYPE, $field)
            );
        }

        return $attributes;
    }
}
