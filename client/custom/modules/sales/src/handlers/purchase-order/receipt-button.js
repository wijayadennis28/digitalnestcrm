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

define('sales:handlers/purchase-order/receipt-button', [], function () {

    class Handler {

        /**
         * @param {module:views/detail} view
         */
        constructor(view) {
            /** @type {module:views/detail} */
            this.view = view;
        }

        // noinspection JSUnusedGlobalSymbols
        actionCreateReceipt() {
            if (
                this.view.getRecordView() &&
                this.view.getRecordView().getMode() === this.view.getRecordView().MODE_EDIT
            ) {
                const msg = this.view.getLanguage()
                    .translate('cannotCreateReceiptInEditMode', 'messages', 'PurchaseOrder');

                const dialog = Espo.Ui.dialog({
                    backdrop: true,
                    className: 'dialog-confirm',
                    body: this.view.getHelper().escapeString(msg),
                });

                dialog.show();

                return;
            }

            Espo.Ui.notify(' ... ');

            this.view
                .createView('dialog', 'sales:views/purchase-order/modals/create-receipt', {
                    model: this.view.model,
                })
                .then(view => {
                    view.render();

                    Espo.Ui.notify(false);

                    this.view.listenToOnce(view, 'done', () => {
                        this.view.model.fetch();
                        this.view.model.trigger('update-all');
                    });
                });
        }

        hide() {
            this.view.hideHeaderActionItem('createReceipt');
        }

        show() {
            this.view.showHeaderActionItem('createReceipt');
        }

        init() {
            const model = this.view.model;

            if (
                //!this.view.getConfig().get('receiptOrdersEnabled') ||
                !this.view.getAcl().check(model, 'edit') ||
                !this.view.getAcl().checkScope('ReceiptOrder', 'create')
            ) {
                this.hide();

                return;
            }

            const ignoreStatusList = [
                ...(this.view.getMetadata().get(`scopes.${model.entityType}.canceledStatusList`) || []),
                ...(this.view.getMetadata().get(`scopes.${model.entityType}.doneStatusList`) || []),
            ];

            const control = () => {
                const status = model.get('status');

                if (
                    !model.has('isReceiptFullyCreated') ||
                    model.get('isReceiptFullyCreated') ||
                    ignoreStatusList.includes(status)
                ) {
                    this.hide();

                    return;
                }

                this.show();
            };

            control();
            this.view.listenTo(this.view.model, 'sync', () => control());
        }
    }

    return Handler;
});
