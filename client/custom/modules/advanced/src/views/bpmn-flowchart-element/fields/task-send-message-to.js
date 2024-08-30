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

define('advanced:views/bpmn-flowchart-element/fields/task-send-message-to',
['views/fields/enum', 'advanced:views/bpmn-flowchart-element/fields/task-send-message-from'], function (Dep, From) {

    return Dep.extend({

        setupOptions: function () {
            Dep.prototype.setupOptions.call(this);

            this.params.options = Espo.Utils.clone(this.params.options);

            if (
                this.getMetadata()
                    .get(['entityDefs', this.model.targetEntityType, 'fields', 'emailAddress', 'type']) ===
                'email'
            ) {
                this.params.options.push('targetEntity');
            }

            let linkOptionList = From.prototype.getLinkOptionList.call(this);

            if (this.getMetadata().get(['scopes', this.model.targetEntityType, 'stream'])) {
                this.params.options.push('followers');
            }

            linkOptionList.forEach(item => {
                this.params.options.push(item);
            });

            From.prototype.translateOptions.call(this);
        },
    });
});
