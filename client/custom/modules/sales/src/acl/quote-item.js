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

define('sales:acl/quote-item', ['acl'], function (Dep) {

    return Dep.extend({

        checkIsOwner: function (model) {
            let attribute = 'quoteId';

            if (this.scope === 'SalesOrderItem') {
                attribute = 'salesOrderId';
            }
            else if (this.scope === 'InvoiceItem') {
                attribute = 'invoiceId';
            }

            if (model.has(attribute)) {
                return true;
            }

            return Dep.prototype.checkIsOwner.call(this, model);
        },

        checkInTeam: function (model) {
            let attribute = 'quoteId';

            if (this.scope === 'SalesOrderItem') {
                attribute = 'salesOrderId';
            }
            else if (this.scope === 'InvoiceItem') {
                attribute = 'invoiceId';
            }

            if (model.has(attribute)) {
                return true;
            }

            return Dep.prototype.checkInTeam.call(this, model);
        },
    });
});