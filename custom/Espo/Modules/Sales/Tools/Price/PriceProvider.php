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

namespace Espo\Modules\Sales\Tools\Price;

use Espo\Core\Currency\Converter;
use Espo\Core\Field\Currency;
use Espo\Core\Field\Date;
use Espo\Core\Formula\Exceptions\Error;
use Espo\Core\Formula\Manager as FormulaManager;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Modules\Sales\Entities\PriceBook;
use Espo\Modules\Sales\Entities\PriceRule;
use Espo\Modules\Sales\Entities\PriceRuleCondition;
use Espo\Modules\Sales\Entities\Product;
use Espo\Modules\Sales\Entities\ProductPrice;
use Espo\Modules\Sales\Entities\Supplier;
use Espo\Modules\Sales\Tools\Price\Sales\Data;
use Espo\Modules\Sales\Tools\Sales\ConfigDataProvider;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Condition as Cond;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\Part\Order;
use Espo\ORM\Query\Part\Where\Comparison as Comp;
use Espo\ORM\Query\Part\WhereItem;

use Espo\ORM\Query\SelectBuilder;
use Exception;
use RuntimeException;
use DateTimeZone;

class PriceProvider
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private ConfigDataProvider $configDataProvider,
        private Converter $converter,
        private FormulaManager $formulaManager,
        private Log $log,
        private PurchasePriceProvider $purchasePriceProvider,
        private RuleCalculator $ruleCalculator
    ) {}

    /**
     * Get a price for a unit w/o price rules applied.
     */
    public function getBase(Product $product, ?PriceBook $priceBook = null): PricePair
    {
        return $this->getInternal($product, 1.0, $priceBook, new Data(), true);
    }

    public function get(
        Product $product,
        float $quantity,
        ?PriceBook $priceBook = null,
        ?Data $data = null
    ): PricePair {

        $data ??= new Data();

        $pair = $this->getInternal($product, $quantity, $priceBook, $data);

        return $this->fixPricePair($pair);
    }

    private function getProductById(string $id): ?Product
    {
        return $this->entityManager
            ->getRDBRepositoryByClass(Product::class)
            ->getById($id);
    }

    private function getProductPriceFromPriceBook(
        Product $product,
        float $quantity,
        PriceBook $priceBook
    ): ?PricePair {

        $pair = $this->getFromBook($product, $quantity, $priceBook);

        if ($pair) {
            return $pair;
        }

        $templateProduct = $this->getTemplateProduct($product);

        if (!$templateProduct) {
            return null;
        }

        $pair = $this->getFromBook($templateProduct, $quantity, $priceBook);

        if ($pair) {
            return $pair;
        }

        return null;
    }

    /**
     * @param string[] $ids
     * @param string[] $skipRuleIds
     * @return PricePair
     */
    private function getInternal(
        Product $product,
        float $quantity,
        ?PriceBook $priceBook,
        Data $data,
        bool $skipRules = false,
        array $ids = [],
        array $skipRuleIds = []
    ): PricePair {

        $pair = null;

        if ($priceBook) {
            $pair = $this->getProductPriceFromPriceBook($product, $quantity, $priceBook);

            $ids[] = $priceBook->getId();
        }

        if ($priceBook && !$skipRules) {
            $list = array_merge(
                $pair ? [$pair] : [],
                $this->findRulePricesInBook($product, $quantity, $priceBook, $data, $skipRuleIds)
            );

            $list = $this->sortPriceRules($list);

            if ($list !== []) {
                $pair = $list[0];
            }
        }

        if ($pair && $pair->getUnit()) {
            return $pair;
        }

        $fallbackBook = $this->getFallbackBook($priceBook, $ids);

        if ($fallbackBook) {
            return $this->getInternal(
                $product,
                $quantity,
                $fallbackBook,
                $data,
                $skipRules,
                $ids,
                $skipRuleIds
            );
        }

        $unitPrice = $product->getUnitPrice();
        $listPrice = $product->getListPrice() ?? $unitPrice;

        if ($unitPrice) {
            return new PricePair($unitPrice, $listPrice);
        }

        $templateProduct = $this->getTemplateProduct($product);

        if ($templateProduct) {
            $unitPrice = $templateProduct->getUnitPrice();
            $listPrice = $templateProduct->getListPrice() ?? $unitPrice;
        }

        return new PricePair($unitPrice, $listPrice);
    }

    private function getFromBook(Product $product, float $quantity, PriceBook $priceBook): ?PricePair
    {
        return $this->findProductPriceInBook($product, $quantity, $priceBook);
    }

    private function findProductPriceInBook(Product $product, float $quantity, PriceBook $priceBook): ?PricePair
    {
        /** @var ?ProductPrice $unitPrice */
        $unitPrice = $this->entityManager
            ->getRDBRepository(ProductPrice::ENTITY_TYPE)
            ->where(['minQuantity<=' => $quantity])
            ->where(['minQuantity!=' => null])
            ->where(['priceBookId' => $priceBook->getId()])
            ->where(['productId' => $product->getId()])
            ->where($this->getRangeWhere())
            ->where(['status' => ProductPrice::STATUS_ACTIVE])
            ->order('priceConverted', Order::ASC)
            ->findOne();

        $basePrice = $this->findBasePriceInBook($product, $priceBook);

        if (!$unitPrice && !$basePrice) {
            return null;
        }

        if (!$unitPrice) {
            return new PricePair(
                $basePrice->getPrice(),
                $basePrice->getPrice()
            );
        }

        return new PricePair(
            $unitPrice->getPrice(),
            $basePrice?->getPrice()
        );
    }

    /**
     * @param string[] $skipIds
     * @return PricePair[]
     */
    private function findRulePricesInBook(
        Product $product,
        float $quantity,
        PriceBook $priceBook,
        Data $data,
        array $skipIds
    ): array {

        $rules = $this->findRules($product, $quantity, $priceBook, $data, $skipIds);

        $list = [];

        $priceBookBase = null;
        $supplierBase = null;
        $unitBase = null;

        foreach ($rules as $rule) {
            $base = $this->getRuleBase(
                $product,
                $priceBook,
                $rule,
                $data,
                $skipIds,
                $priceBookBase,
                $supplierBase,
                $unitBase
            );

            if (!$base) {
                continue;
            }

            $newPrice = $this->calculatePrice($base, $rule);

            $list[] = new PricePair($newPrice, $base);
        }

        return $list;
    }

    /**
     * @param string[] $skipIds
     * @return PriceRule[]
     */
    private function findRules(
        Product $product,
        float $quantity,
        PriceBook $priceBook,
        Data $data,
        array $skipIds = []
    ): array {

        $rules = $this->entityManager
            ->getRDBRepositoryByClass(PriceRule::class)
            ->select([
                'id',
                'target',
                'discount',
                'productCategoryId',
                'conditionId',
                'roundingMethod',
                'roundingFactor',
                'surcharge',
                'currency',
                'basedOn',
                'supplierId',
            ])
            ->where(
                Cond::or(
                    Expr::isNull(Expr::column('minQuantity')),
                    Comp::lessOrEqual(Expr::column('minQuantity'), $quantity),
                )
            )
            ->where(['priceBookId' => $priceBook->getId()])
            ->where(['status' => PriceRule::STATUS_ACTIVE])
            ->where(['id!=' => $skipIds])
            ->where($this->getRangeWhere())
            ->where(
                Cond::or(
                    Comp::equal(Expr::column('target'), PriceRule::TARGET_ALL),
                    Cond::and(
                        Comp::equal(Expr::column('target'), PriceRule::TARGET_PRODUCT_CATEGORY),
                        $product->getCategory() ?
                            Comp::in(
                                Expr::column('productCategoryId'),
                                SelectBuilder::create()
                                    ->from('ProductCategoryPath')
                                    ->select('ascendorId')
                                    ->where(['descendorId' => $product->getCategory()->getId()])
                                    ->build()
                            ) :
                            Expr::value(false),
                    ),
                    Comp::equal(Expr::column('target'), PriceRule::TARGET_CONDITIONAL),
                )
            )
            ->find();

        $rules = iterator_to_array($rules);

        return $this->filterConditional($rules, $product, $data);
    }

    private function getProductRootPrice(Product $product): ?Currency
    {
        $listPrice = $product->getListPrice();
        $unitPrice = $product->getUnitPrice() ?? $listPrice;

        if ($unitPrice) {
            return $unitPrice;
        }

        $templateProduct = $this->getTemplateProduct($product);

        if (!$templateProduct) {
            return null;
        }

        $listPrice = $templateProduct->getListPrice();
        $unitPrice = $templateProduct->getUnitPrice() ?? $listPrice;

        if (!$unitPrice) {
            return null;
        }

        return $unitPrice;
    }

    private function getRangeWhere(): WhereItem
    {
        /** @noinspection PhpDeprecationInspection */
        $todayString = method_exists($this->getToday(), 'toString') ?
            $this->getToday()->toString() :
            $this->getToday()->getString();

        return Cond::or(
            Cond::and(
                Expr::isNull(Expr::column('dateStart')),
                Expr::isNull(Expr::column('dateEnd')),
            ),
            Cond::and(
                Comp::lessOrEqual(Expr::column('dateStart'), $todayString),
                Comp::greaterOrEqual(Expr::column('dateEnd'), $todayString),
            ),
            Cond::and(
                Expr::isNull(Expr::column('dateStart')),
                Comp::greaterOrEqual(Expr::column('dateEnd'), $todayString),
            ),
            Cond::and(
                Comp::lessOrEqual(Expr::column('dateStart'), $todayString),
                Expr::isNull(Expr::column('dateEnd')),
            ),
        );
    }

    private function getToday(): ?Date
    {
        /** @var string $timeZone */
        $timeZone = $this->config->get('timeZone') ?? 'UTC';

        try {
            return Date::createToday(new DateTimeZone($timeZone));
        }
        catch (Exception $e) {
            throw new RuntimeException($e->getMessage());
        }
    }

    private function getDefaultBook(): ?PriceBook
    {
        if (!$this->configDataProvider->isPriceBooksEnabled()) {
            return null;
        }

        $id = $this->configDataProvider->getDefaultPriceBookId();

        if (!$id) {
            return null;
        }

        /** @var ?PriceBook $priceBook */
        $priceBook  = $this->entityManager->getEntityById(PriceBook::ENTITY_TYPE, $id);

        if (!$priceBook) {
            return null;
        }

        if ($priceBook->getStatus() !== PriceRule::STATUS_ACTIVE) {
            return null;
        }

        return $priceBook;
    }

    private function getTemplateProduct(Product $product): ?Product
    {
        return $product->getType() === Product::TYPE_VARIANT && $product->getTemplate() ?
            $this->getProductById($product->getTemplate()->getId()) :
            null;
    }

    private function getParentBook(PriceBook $priceBook): ?PriceBook
    {
        $parentPriceBookId = $priceBook->getParentPriceBook()?->getId();

        if (!$parentPriceBookId) {
            return null;
        }

        /** @var ?PriceBook $priceBook */
        $priceBook = $this->entityManager->getEntityById(PriceBook::ENTITY_TYPE, $parentPriceBookId);

        if (!$priceBook) {
            return null;
        }

        if ($priceBook->getStatus() !== PriceRule::STATUS_ACTIVE) {
            return null;
        }

        return $priceBook;
    }

    private function obtainBaseUnit(Product $product, PriceBook $priceBook): ?Currency
    {
        return $this->obtainProductBasePriceWithBook($product, $priceBook) ??
            $this->getProductRootPrice($product);
    }

    /**
     * @param string[] $ids
     */
    private function obtainProductBasePriceWithBook(Product $product, PriceBook $priceBook, array $ids = []): ?Currency
    {
        $price = $this->findBasePriceInBook($product, $priceBook);

        if ($price) {
            return $price->getPrice();
        }

        $ids[] = $priceBook->getId();

        $parentPriceBook = $this->getParentBook($priceBook);

        if ($parentPriceBook && !in_array($parentPriceBook->getId(), $ids)) {
            return $this->obtainProductBasePriceWithBook($product, $parentPriceBook, $ids);
        }

        $defaultPriceBook = $this->getDefaultBook();

        if (!$defaultPriceBook) {
            return null;
        }

        if (in_array($defaultPriceBook->getId(), $ids)) {
            return null;
        }

        return $this->findBasePriceInBook($product, $defaultPriceBook)?->getPrice();
    }

    private function findBasePriceInBook(Product $product, PriceBook $priceBook): ?ProductPrice
    {
        return $this->entityManager
            ->getRDBRepository(ProductPrice::ENTITY_TYPE)
            ->where(
                Expr::isNull(Expr::column('minQuantity')),
            )
            ->where(['priceBookId' => $priceBook->getId()])
            ->where(['productId' => $product->getId()])
            ->where($this->getRangeWhere())
            ->where(['status' => ProductPrice::STATUS_ACTIVE])
            ->order('priceConverted', Order::DESC)
            ->findOne();
    }

    /**
     * @param PriceRule[] $rules
     * @return PriceRule[]
     */
    private function filterConditional(array $rules, Product $product, Data $data): array
    {
        $rules = array_filter($rules, function ($rule) use ($data, $product) {
            if ($rule->getTarget() !== PriceRule::TARGET_CONDITIONAL) {
                return true;
            }

            $conditionId = $rule->getCondition()?->getId();

            if (!$conditionId) {
                return false;
            }

            return $this->checkRuleCondition($conditionId, $product, $data);

        });

        return array_values($rules);
    }

    private function checkRuleCondition(string $conditionId, Product $product, Data $data): bool
    {
        $condition = $this->entityManager->getRDBRepositoryByClass(PriceRuleCondition::class)->getById($conditionId);

        if (!$condition) {
            return false;
        }

        $formula = $condition->getCondition();

        if (!$formula) {
            return false;
        }

        try {
            return $this->formulaManager->run($formula, null, (object) [
                '__product' => $product,
                '__account' => $data->account,
            ]);
        }
        catch (Error $e) {
            $this->log->error("PriceRuleCondition $conditionId, formula error: " . $e->getMessage());

            return false;
        }
    }

    /**
     * @param PriceRule[] $list
     * @return PriceRule[]
     */
    private function sortPriceRules(array $list): array
    {
        usort($list, function (PricePair $a, PricePair $b) {
            if (!$a->getUnit() || !$b->getUnit()) {
                return 0;
            }

            $aUnit = $this->converter->convertToDefault($a->getUnit());
            $bUnit = $this->converter->convertToDefault($b->getUnit());

            return $this->compare($aUnit, $bUnit);
        });

        return $list;
    }

    private function compare(Currency $a, Currency $b): int
    {
        if ($a->getCode() !== $b->getCode()) {
            $b = $this->converter->convert($b, $a->getCode());
        }

        return $a->compare($b);
    }

    /**
     * @param string[] $ids
     */
    private function getFallbackBook(?PriceBook $priceBook, array $ids): ?PriceBook
    {
        $fallbackBook = null;

        if (
            $priceBook &&
            $priceBook->getParentPriceBook() &&
            $priceBook->getParentPriceBook()->getId() !== $priceBook->getId() &&
            !in_array($priceBook->getParentPriceBook()->getId(), $ids)
        ) {
            $fallbackBook = $this->getParentBook($priceBook);
        }

        if ($fallbackBook) {
            return $fallbackBook;
        }

        $defaultPriceBook = $this->getDefaultBook();

        if ($defaultPriceBook && !in_array($defaultPriceBook->getId(), $ids)) {
            $fallbackBook = $defaultPriceBook;
        }

        return $fallbackBook;
    }

    private function fixPricePair(PricePair $pair): PricePair
    {
        if (
            $pair->getList() !== null &&
            $pair->getUnit() !== null &&
            $this->compare($pair->getList(), $pair->getUnit()) < 0
        ) {
            $pair = new PricePair($pair->getUnit(), $pair->getUnit());
        }

        return $pair;
    }

    private function calculatePrice(Currency $base, PriceRule $rule): Currency
    {
        return $this->ruleCalculator->calculate($base, $rule);
    }

    /**
     * @param string[] $skipRuleIds
     */
    private function obtainBasePriceBook(
        Product $product,
        PriceBook $priceBook,
        Data $data,
        PriceRule $rule,
        array $skipRuleIds
    ): ?Currency {

        $skipRuleIds[] = $rule->getId();

        $pair = $this->getInternal($product, 1.0, $priceBook, $data, false, [], $skipRuleIds);

        return $pair->getUnit();
    }

    private function obtainBaseSupplier(Product $product, PriceRule $rule): ?Currency
    {
        if (!$rule->getSupplier()) {
            return null;
        }

        $supplier = $this->entityManager
            ->getRDBRepositoryByClass(Supplier::class)
            ->getById($rule->getSupplier()->getId());

        if (!$supplier) {
            return null;
        }

        $pair = $this->purchasePriceProvider->getSupplerBase($product, $supplier);

        return $pair->getUnit();
    }

    /**
     * @param string[] $skipIds
     */
    private function getRuleBase(
        Product $product,
        PriceBook $priceBook,
        PriceRule $rule,
        Data $data,
        array $skipIds,
        ?Currency &$priceBookBase,
        ?Currency &$supplierBase,
        ?Currency &$unitBase
    ): ?Currency {

        $basedOn = $rule->getBasedOn();

        if ($basedOn === PriceRule::BASED_ON_PRICE_BOOK) {
            $base = $priceBookBase ?? $this->obtainBasePriceBook($product, $priceBook, $data, $rule, $skipIds);
            $priceBookBase = $base;

            return $base;
        }

        if ($basedOn === PriceRule::BASED_ON_SUPPLIER) {
            $base = $supplierBase ?? $this->obtainBaseSupplier($product, $rule);
            $supplierBase = $base;

            return $base;
        }

        if ($basedOn === PriceRule::BASED_ON_COST) {
            return $product->getCostPrice();
        }

        $base = $unitBase ?? $this->obtainBaseUnit($product, $priceBook);
        $unitBase = $base;

        return $base;
    }
}
