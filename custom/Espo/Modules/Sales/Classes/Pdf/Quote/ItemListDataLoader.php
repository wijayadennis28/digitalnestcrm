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

namespace Espo\Modules\Sales\Classes\Pdf\Quote;

use Espo\Modules\Sales\Entities\QuoteItem;
use Espo\ORM\Collection;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;
use Espo\Tools\Pdf\Data\DataLoader;
use Espo\Tools\Pdf\Params;

use stdClass;

/** @noinspection PhpUnused */
class ItemListDataLoader implements DataLoader
{
    public function __construct(private EntityManager $entityManager)
    {}

    public function load(Entity $entity, Params $params): stdClass
    {
        $itemEntityType = $entity->getEntityType() . 'Item';
        $itemParentIdAttribute = lcfirst($entity->getEntityType()) . 'Id';

        /** @var Collection<QuoteItem> $itemList */
        $itemList = $this->entityManager
            ->getRDBRepository($itemEntityType)
            ->where([$itemParentIdAttribute => $entity->getId()])
            ->order('order')
            ->find();

        foreach ($itemList as $item) {
            $item->loadAllLinkMultipleFields();
        }

        return (object) [
            'itemList' => $itemList,
        ];
    }
}
