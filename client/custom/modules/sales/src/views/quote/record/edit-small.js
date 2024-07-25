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

define('sales:views/quote/record/edit-small', ['views/record/edit-small'], function (Dep) {

    return Dep.extend({

        setup: function () {
            Dep.prototype.setup.call(this);

            this.handleAmountField();

            this.listenTo(this.model, 'change:itemList', () => {
                this.handleAmountField();
            });
        },

        populateDefaults: function () {
            Dep.prototype.populateDefaults.call(this);

            const taxRate = this.getHelper().getAppParam('defaultTaxRate' + this.entityType);

            if (taxRate !== null && typeof taxRate !== 'undefined') {
                this.model.set('taxRate', taxRate, {silent: true});
            }
        },

        handleAmountField: function () {
            const itemList = this.model.get('itemList') || [];

            if (!itemList.length) {
                this.setFieldNotReadOnly('amount');
            } else {
                this.setFieldReadOnly('amount');
            }
        },

    });
});
