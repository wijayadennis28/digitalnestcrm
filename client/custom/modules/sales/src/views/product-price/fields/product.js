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

define('sales:views/product-price/fields/product', ['sales:views/fields/product-variant'], function (Dep) {

    return class extends Dep {

        allowProductTemplate = true

        getAttributeList() {
            return [...super.getAttributeList(), 'status'];
        }

        afterRender() {
            super.afterRender();

            if (this.mode === this.MODE_LIST && this.model.get('status') === 'Inactive') {
                this.$el.find('a').css({textDecoration: 'line-through'});
            }
        }
    }
});
