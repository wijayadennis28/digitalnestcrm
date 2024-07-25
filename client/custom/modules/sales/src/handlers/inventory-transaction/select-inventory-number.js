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

define('sales:handlers/inventory-transaction/select-inventory-number', [], function () {

    class Handler {

        getFilters(model) {
            const productId = model.get('productId');

            if (!productId) {
                return Promise.resolve({});
            }

            const advanced = {
                product: {
                    type: 'equals',
                    attribute: 'productId',
                    value: productId,
                    data: {
                        type: 'is',
                        idValue: productId,
                        nameValue: model.get('productName'),
                    },
                },
            };

            return Promise.resolve({
                advanced: advanced,
            });
        }
    }

    return Handler;
});
