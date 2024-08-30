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

define('advanced:views/bpmn-flowchart-element/record/task-send-message-detail',
['advanced:views/bpmn-flowchart-element/record/detail'], function (Dep) {

    return Dep.extend({

        setup: function () {
            Dep.prototype.setup.call(this);

            this.controlFieldsVisibility();
        },

        controlFieldsVisibility: function () {
            this.hideField('fromEmailAddress');
            this.hideField('toEmailAddress');
            this.hideField('replyToEmailAddress');
            this.hideField('toSpecifiedTeams');
            this.hideField('toSpecifiedUsers');
            this.hideField('toSpecifiedContacts');

            if (this.model.get('from') === 'specifiedEmailAddress') {
                this.showField('fromEmailAddress');
            }
            if (this.model.get('to') === 'specifiedEmailAddress') {
                this.showField('toEmailAddress');
            }
            if (this.model.get('replyTo') === 'specifiedEmailAddress') {
                this.showField('replyToEmailAddress');
            }
            if (this.model.get('to') === 'specifiedUsers') {
                this.showField('toSpecifiedUsers');
            }
            if (this.model.get('to') === 'specifiedTeams') {
                this.showField('toSpecifiedTeams');
            }
            if (this.model.get('to') === 'specifiedContacts') {
                this.showField('toSpecifiedContacts');
            }
        },
    });
});
