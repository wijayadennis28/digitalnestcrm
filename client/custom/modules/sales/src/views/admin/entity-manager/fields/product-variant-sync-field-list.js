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

define('sales:views/admin/entity-manager/fields/product-variant-sync-field-list', ['views/fields/multi-enum'], function (Dep) {

    return Dep.extend({

        setupOptions: function () {
            this.params.options = this.getFieldManager().getEntityTypeFieldList('Product', {onlyAvailable: true});

            /** @type {string[]} */
            const coreList = this.getMetadata().get('scopes.Product.variantCoreSyncFieldList') || [];

            /** @type {Object.<string, Record>} */
            const fieldDefs = this.getMetadata().get('entityDefs.Product.fields') || {};

            const ignoreList = [
                'name',
                'type',
                'createdAt',
                'createdBy',
                'modifiedAt',
                'modifiedBy',
                'variantOrder',
                'template',
                'attributes',
                'variantAttributeOptions',
            ];

            const ignoreTypeList = [
                'currencyConverted',
            ];

            this.translatedOptions = {};

            this.params.options = this.params.options.filter(item => {
                if (!(item in fieldDefs)) {
                    return false;
                }

                const defs = fieldDefs[item];

                if (defs.notStorable) {
                    return false;
                }

                if (defs.notStorable) {
                    return false;
                }

                if (defs.directAccessDisabled) {
                    return false;
                }

                const type = defs.type;

                if (ignoreTypeList.includes(type)) {
                    return false;
                }

                return !coreList.includes(item) && !ignoreList.includes(item);
            });

            this.params.options.forEach(item => {
                this.translatedOptions[item] = this.translate(item, 'fields', 'Product');
            });
        },
    });
});
