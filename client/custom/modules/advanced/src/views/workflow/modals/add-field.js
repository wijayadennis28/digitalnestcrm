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

define('advanced:views/workflow/modals/add-field', ['views/modal'], function (Dep) {

    return Dep.extend({

        templateContent: `<div class="field" data-name="field">{{{field}}}</div>`,

        backdrop: true,

        setup: function () {
            this.headerText = this.translate('Add Field', 'labels', 'Workflow');

            const scope = this.scope = this.options.scope;
            const fieldList = /** @type {string[]} */this.options.fieldList;

            this.wait(true);

            this.getModelFactory().create('Workflow', model => {
                model.targetEntityType = scope;

                this.createView('field', 'advanced:views/workflow/fields/action-field', {
                    selector: '.field[data-name="field"]',
                    model: model,
                    mode: 'edit',
                    name: 'field',
                    params: {
                        options: ['', ...this.options.fieldList],
                        isSorted: true,
                    },
                    translatedOptions: fieldList.reduce((p, it) => {
                        return {...p, [it]: this.translate(it, 'fields', scope)};
                    }, {}),
                }, view => {
                    this.listenTo(view, 'change', () => {
                        this.trigger('add', model.get('field'));

                        this.close();
                    });
                });

                this.wait(false);
            });
        },
    });
});
