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

define('sales:views/opportunity/fields/item-list', ['sales:views/quote/fields/item-list'], function (Dep) {

    return Dep.extend({

        detailTemplate: 'sales:opportunity/fields/item-list/detail',
        listTemplate: 'sales:opportunity/fields/item-list/detail',
        editTemplate: 'sales:opportunity/fields/item-list/edit',

        handlerClass: 'sales:opportunity-calculation-handler',

        loadTotalLayout: function () {
            this.totalLayout = [];

            return Promise.resolve();
        },

        showAdditionalFields: function () {
            this.$el.find('.field-currency').removeClass('hidden');
        },

        hideAdditionalFields: function () {
            this.$el.find('.field-currency').addClass('hidden');
        },

        getEmptyItem: function () {
            const data = Dep.prototype.getEmptyItem.call(this);

            delete data.listPriceCurrency;
            delete data.taxRate;
            delete data.isTaxable;

            return data;
        },
    });
});
