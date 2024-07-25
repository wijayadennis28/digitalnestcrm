<?php

namespace Espo\Modules\Sales\Classes\EntityCurrencyConverters;

use Espo\Core\Currency\Rates;
use Espo\Modules\Sales\Tools\Sales\OrderEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Tools\Currency\Conversion\DefaultEntityConverter;
use Espo\Tools\Currency\Conversion\EntityConverter;

/**
 * @noinspection PhpUnused
 * @implements EntityConverter<OrderEntity>
 */
class QuoteConverter implements EntityConverter
{
    public function __construct(
        private DefaultEntityConverter $defaultEntityConverter,
        private EntityManager $entityManager
    ) {}

    public function convert(Entity $entity, string $targetCurrency, Rates $rates): void
    {
        $this->defaultEntityConverter->convert($entity, $targetCurrency, $rates);

        $items = $this->entityManager
            ->getRDBRepository($entity->getEntityType())
            ->getRelation($entity, 'items')
            ->find();

        $itemList = [];

        foreach ($items as $item) {
            $this->defaultEntityConverter->convert($item, $targetCurrency, $rates);

            $itemList[] = $item->getValueMap();
        }

        $entity->set('itemList', $itemList);
    }
}
