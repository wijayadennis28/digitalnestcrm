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
 * License ID: 02847865974db42443189e5f30908f60
 ************************************************************************************/

define('advanced:views/report-panel/fields/dynamic-logic-visible',
['views/admin/field-manager/fields/dynamic-logic-conditions'], function (Dep) {

    return Dep.extend({

        data: function () {
            return {
                value: this.getValueForDisplay()
            };
        },

        getValueForDisplay: function () {
            if (!this.model.get(this.name)) {
                return this.translate('None');
            }
        },

        setupEntityType: function () {
            this.options.scope = this.scope = this.model.get('entityType');

            this.listenTo(this.model, 'change:entityType', () => {
                this.options.scope = this.scope = this.model.get('entityType');

                if (this.scope) {
                    this.createStringView();
                }
            });
        },

        setup: function () {
            this.setupEntityType();
            this.conditionGroup = Espo.Utils.cloneDeep((this.model.get(this.name) || {}).conditionGroup || []);
            this.createStringView();
        },
    });
});
