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

define('sales:views/sales-order/fields/opportunity', ['views/fields/link'], function (Dep) {

    return Dep.extend({

        // @todo Use handler.
        getSelectFilters: function () {
            if (this.model.get('accountId')) {
                return {
                    'account': {
                        type: 'equals',
                        attribute: 'accountId',
                        value: this.model.get('accountId'),
                        data: {
                            type: 'is',
                            nameValue: this.model.get('accountName'),
                        },
                    },
                };
            }
        },

        select: function (model) {
            Dep.prototype.select.call(this, model);

            if (!this.model.isNew()) {
                return;
            }

            Espo.Ajax.getRequest('SalesOrder/action/getAttributesFromOpportunity', {
                opportunityId: model.id,
            }).then(attributes => {
                const attributesToSet = {};

                for (const item in attributes) {
                    if (~['amountCurrency'].indexOf(item)) {
                        continue;
                    }

                    if (~['name'].indexOf(item)) {
                        if (this.model.get(item)) {
                            continue;
                        }
                    }

                    attributesToSet[item] = attributes[item];
                }

                this.model.set(attributesToSet);
                this.model.set('amountCurrency', attributes.amountCurrency, {ui: true});
            });
        },
    });
});
