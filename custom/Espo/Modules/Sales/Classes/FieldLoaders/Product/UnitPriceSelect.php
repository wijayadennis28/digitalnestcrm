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

namespace Espo\Modules\Sales\Classes\FieldLoaders\Product;

use Espo\Core\FieldProcessing\Loader;
use Espo\Core\FieldProcessing\Loader\Params;
use Espo\Modules\Sales\Entities\PriceBook;
use Espo\Modules\Sales\Entities\Product;
use Espo\Modules\Sales\Entities\Supplier;
use Espo\Modules\Sales\Tools\Price\PurchasePriceProvider;
use Espo\Modules\Sales\Tools\Price\PricePair;
use Espo\Modules\Sales\Tools\Price\PriceProvider;
use Espo\Modules\Sales\Tools\Sales\ConfigDataProvider;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * @noinspection PhpUnused
 * @implements Loader<Product>
 */
class UnitPriceSelect implements Loader
{
    public function __construct(
        private PriceProvider $priceProvider,
        private PurchasePriceProvider $purchasePriceProvider,
        private EntityManager $entityManager,
        private ConfigDataProvider $configDataProvider
    ) {}

    public function process(Entity $entity, Params $params): void
    {
        if (!$params->hasInSelect('unitPriceSelect')) {
            return;
        }

        $supplierId = $this->findSupplierId($params);

        if ($supplierId) {
            /** @var ?Supplier $supplier */
            $supplier = $this->entityManager->getEntityById(Supplier::ENTITY_TYPE, $supplierId);

            if (!$supplier) {
                $this->setDefault($entity);

                return;
            }

            $pricePair = $this->purchasePriceProvider->get($entity, 1.0, $supplier);

            $this->setPrice($entity, $pricePair);

            return;
        }

        if (!$this->configDataProvider->isPriceBooksEnabled()) {
            $this->setDefault($entity);

            return;
        }

        $priceBookId = $this->findPriceBookId($params);

        /** @var ?PriceBook $priceBook */
        $priceBook = $priceBookId ?
            $this->entityManager->getEntityById(PriceBook::ENTITY_TYPE, $priceBookId) :
            null;

        $pricePair = $this->priceProvider->getBase($entity, $priceBook);

        $this->setPrice($entity, $pricePair);
    }

    private function findSupplierId(Params $params): ?string
    {
        return $this->findId($params, 'unitPriceSupplier_');
    }

    private function findPriceBookId(Params $params): ?string
    {
        return $this->findId($params, 'unitPricePriceBook_');
    }

    private function findId(Params $params, string $prefix): ?string
    {
        foreach ($params->getSelect() ?? [] as $item) {
            if (str_starts_with($item, $prefix)) {
                return substr($item, strlen($prefix));
            }
        }

        return null;
    }

    private function setDefault(Product $entity): void
    {
        $entity->set('unitPriceSelect', $entity->get('unitPrice'));
        $entity->set('unitPriceSelectCurrency', $entity->get('unitPriceCurrency'));
    }

    private function setPrice(Product $entity, PricePair $pair): void
    {
        if (!$pair->getUnit()) {
            $this->setDefault($entity);

            return;
        }

        $entity->set('unitPriceSelect', $pair->getUnit()->getAmount());
        $entity->set('unitPriceSelectCurrency', $pair->getUnit()->getCode());
    }
}
