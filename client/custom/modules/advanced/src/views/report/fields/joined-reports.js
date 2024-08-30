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

define('advanced:views/report/fields/joined-reports', ['views/fields/link-multiple-with-columns'], function (Dep) {

    return Dep.extend({

        columnList: ['label'],

        selectPrimaryFilterName: 'grid',

        createDisabled: true,

        columnsDefs: {
            'label': {
                type: 'varchar',
                scope: 'Report',
                field: 'joinedReportLabel',
            }
        },

        fetch: function () {
            var data = Dep.prototype.fetch.call(this);

            var dataList = [];

            data[this.idsName].forEach(id => {
                dataList.push({
                    id: id,
                    label: ((data[this.columnsName] || {})[id] || {}).label,
                });
            });

            data.joinedReportDataList = dataList;

            return data;
        },
    });
});
