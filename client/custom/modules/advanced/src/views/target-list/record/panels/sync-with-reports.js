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

define('advanced:views/target-list/record/panels/sync-with-reports', ['views/record/panels/side'], function (Dep) {

    return Dep.extend({

        fieldList: [
            'syncWithReportsEnabled',
            'syncWithReports',
            'syncWithReportsUnlink',
        ],

        actionList: [
            {
                "name": "syncWithReport",
                "label": "Sync Now",
                "acl": "edit",
                "action": "syncWithReports",
            }
        ],

        setup: function () {
            Dep.prototype.setup.call(this);
        },

        actionSyncWithReports: function () {
            if (!this.model.get('syncWithReportsEnabled')) {
                return;
            }

            Espo.Ui.notify(' ... ');

            Espo.Ajax
                .postRequest('Report/action/syncTargetListWithReports', {targetListId: this.model.id})
                .then(() => {
                    Espo.Ui.success(this.translate('Done'));

                    this.model.trigger('after:relate');
                })
        },
    });
});
