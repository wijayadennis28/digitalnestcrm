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

namespace Espo\Modules\Sales\Tools\Inventory\Availability;

class ProductData
{
    private array $warehouseDataList = [];

    public function __construct(
        private string $id,
        private float $quantity,
        private ?string $name,
        private ?string $productId = null,
        private ?string $inventoryNumberId = null,
        private ?float $quantityOnHand = null
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    public function withWarehouseAdded(
        string $id,
        float $quantity,
        ?string $name = null,
        ?float $quantityOnHand = null
    ): self {

        $obj = clone $this;
        $obj->warehouseDataList[] = new WarehouseData(
            id: $id,
            quantity: $quantity,
            name: $name,
            quantityOnHand: $quantityOnHand,
        );

        return $obj;
    }

    /**
     * @return WarehouseData[]
     */
    public function getWarehouseDataList(): array
    {
        return $this->warehouseDataList;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /** @noinspection PhpUnused */
    public function getInventoryNumberId(): ?string
    {
        return $this->inventoryNumberId;
    }

    /** @noinspection PhpUnused */
    public function getProductId(): string
    {
        return $this->productId ?? $this->id;
    }

    public function getQuantityOnHand(): ?float
    {
        return $this->quantityOnHand;
    }
}
