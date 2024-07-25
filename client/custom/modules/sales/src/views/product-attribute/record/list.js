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

define('sales:views/product-attribute/record/list', ['views/record/list'], function (Dep) {

    return class extends Dep {

        rowActionsView = 'sales:views/product-attribute/record/row-actions/default'

        // noinspection JSUnusedGlobalSymbols
        actionMove(data) {
            const type = data.type;
            const id = data.id;

            Espo.Ui.notify(' ... ');

            Espo.Ajax
                .postRequest(`ProductAttribute/${id}/move/${type}`, {
                    where: this.collection.getWhere(),
                })
                .then(() => {
                    Espo.Ui.notify(false);

                    this.collection.fetch()
                        .then(() => {
                            Espo.Ui.success(this.translate('Done'));
                        });
                });
        }
    };
});
