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

namespace Espo\Modules\Sales\AppParams;

use Espo\Core\Acl;
use Espo\Core\Utils\Metadata;
use Espo\Modules\Sales\Entities\Quote;
use Espo\Modules\Sales\Entities\Tax;
use Espo\ORM\EntityManager;
//use Espo\Tools\App\AppParam;

/**
 * @todo Implement interface when v7.3 is the min supported version.
 */
class DefaultTaxRateQuote// implements AppParam
{
    protected $entityType = Quote::ENTITY_TYPE;

    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private Metadata $metadata
    ) {}

    public function get(): ?float
    {
        if (!$this->acl->checkScope($this->entityType)) {
            return null;
        }

        $taxId = $this->metadata
            ->get(['entityDefs', $this->entityType, 'fields', 'tax', 'defaultAttributes', 'taxId']);

        if (!$taxId) {
            return null;
        }

        /** @var ?Tax $tax */
        $tax = $this->entityManager->getEntityById(Tax::ENTITY_TYPE, $taxId);

        if (!$tax) {
            return null;
        }

        return $tax->getRate();
    }
}
