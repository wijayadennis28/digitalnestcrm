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

define('sales:views/quote-item/fields/union-status', ['views/fields/varchar'], function (Dep) {

    return class extends Dep {

        // noinspection JSUnusedGlobalSymbols
        listTemplateContent = `<span class="text-{{style}}" title="{{value}}">{{value}}</span>`

        data() {
            const entityType = this.model.get('_entityType') ||
                this.model.get('_scope') || 'DummyItem';

            const parentType = entityType.slice(0, -4);
            const status = this.model.get(Espo.Utils.lowerCaseFirst(parentType) + 'Status');

            const style = this.getMetadata()
                .get(`entityDefs.${parentType}.fields.status.style.${status}`) || 'default';

            return {
                style: style,
                value: this.getLanguage().translateOption(status, 'status', parentType),
            };
        }
    }
});
