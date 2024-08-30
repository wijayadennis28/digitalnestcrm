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

define('advanced:views/workflow/action-fields/shift-days', ['view', 'model'], function (Dep, Model) {

    return Dep.extend({

        template: 'advanced:workflow/action-fields/shift-days',

        data: function () {
            return {
                shiftDaysOperator: this.shiftDaysOperator,
                value: this.value,
                unitValue: this.options.unitValue,
                readOnly: this.readOnly,
            };
        },

        setup: function () {
            this.value = this.options.value;
            this.readOnly = this.options.readOnly;

            const value = this.options.value || 0.0;

            if (this.value < 0) {
                this.shiftDaysOperator = 'minus';
                this.value = (-1) * this.value;
            } else {
                this.shiftDaysOperator = 'plus';
            }

            let unitList = [
                'minutes',
                'hours',
                'days',
                'months',
            ];

            if (this.options.isDate) {
                unitList = [
                    'days',
                    'months',
                ];
            }

            this.formModel = new Model();
            this.formModel.name = 'Dummy';

            this.formModel.set({
                operator: value < 0 ? 'minus' : 'plus',
                value: value < 0 ? ((-1) * value) : value,
                unit: this.options.unitValue || unitList[0],
            });

            if (this.readOnly) {
                return;
            }

            this.createView('operatorField', 'views/fields/enum', {
                name: 'operator',
                selector: '[data-field="operator"]',
                model: this.formModel,
                mode: 'edit',
                params: {
                    options: [
                        'plus',
                        'minus',
                    ],
                },
                translatedOptions: {
                    plus: this.translate('plus', 'labels', 'Workflow'),
                    minus: this.translate('minus', 'labels', 'Workflow'),
                },
            });

            this.createView('valueField', 'views/fields/int', {
                name: 'value',
                selector: '[data-field="value"]',
                model: this.formModel,
                mode: 'edit',
                min: 0.0,
            });

            this.createView('unitField', 'views/fields/enum', {
                name: 'unit',
                selector: '[data-field="unit"]',
                model: this.formModel,
                mode: 'edit',
                params: {
                    options: unitList,
                },
                translatedOptions: {
                    minutes: this.translate('minutes', 'labels', 'Workflow'),
                    hours: this.translate('hours', 'labels', 'Workflow'),
                    days: this.translate('days', 'labels', 'Workflow'),
                    months: this.translate('months', 'labels', 'Workflow'),
                },
            });
        },

        fetch: function () {
            let value = this.formModel.attributes.value;

            if (this.formModel.attributes.operator === 'minus') {
                value *= -1;
            }

            return {
                value: value,
                unit: this.formModel.attributes.unit,
            };
        },
    });
});
