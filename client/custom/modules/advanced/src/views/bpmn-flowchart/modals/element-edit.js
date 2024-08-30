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

define('advanced:views/bpmn-flowchart/modals/element-edit',
['views/modal', 'model', 'advanced:bpmn-element-helper'], function (Dep, Model, BpmnElementHelper) {

    return Dep.extend({

        template: 'advanced:bpmn-flowchart/modals/element-detail',

        fitHeight: true,
        cssName: 'detail-modal',
        className: 'dialog dialog-record',

        setup: function () {
            this.elementData = Espo.Utils.cloneDeep(this.options.elementData || {});
            this.targetType = this.options.targetType;

            var model = this.model = new Model;
            model.name = 'BpmnFlowchartElement';
            model.set(this.elementData);

            this.elementType = this.model.get('type');

            model.targetEntityType = this.targetType;
            model.flowchartDataList = this.options.flowchartDataList;
            model.flowchartModel = this.options.flowchartModel;
            model.flowchartCreatedEntitiesData = this.options.flowchartCreatedEntitiesData;
            model.elementType = this.elementType;
            model.elementHelper = new BpmnElementHelper(this.getHelper(), model);
            model.dataHelper = this.options.dataHelper;
            model.isInSubProcess = this.options.isInSubProcess;

            var fields = this.getMetadata()
                .get(['clientDefs', 'BpmnFlowchart', 'elements', this.elementType, 'fields']) || {};

            model.defs = {
                fields: fields
            };

            this.header = this.translate(this.elementType, 'elements', 'BpmnFlowchart');

            this.buttonList = [
                {
                    name: 'apply',
                    label: 'Apply',
                    style: 'danger',
                },
                {
                    name: 'cancel',
                    label: 'Close',
                }
            ];

            var viewName = this.getMetadata()
                    .get(['clientDefs', 'BpmnFlowchart', 'elements', this.elementType, 'recordEditView']) ||
                'advanced:views/bpmn-flowchart-element/record/edit';

            var detailLayout = this.getMetadata()
                .get(['clientDefs', 'BpmnFlowchart', 'elements', this.elementType, 'layout']);

            var dynamicLogicDefs = this.getMetadata()
                .get(['clientDefs', 'BpmnFlowchart', 'elements', this.elementType, 'dynamicLogic']);

            var options = {
                model: this.model,
                el: this.containerSelector + ' .record-container',
                type: 'detailSmall',
                detailLayout: detailLayout,
                columnCount: 2,
                buttonsPosition: false,
                buttonsDisabled: true,
                inlineEditDisabled: true,
                sideDisabled: true,
                bottomDisabled: true,
                isWide: true,
                dynamicLogicDefs: dynamicLogicDefs,
            };

            this.createView('record', viewName, options);
        },

        actionApply: function () {
            var data = Espo.Utils.cloneDeep(this.getView('record').fetch() || {});

            if (this.getView('record').validate()) {
                return;
            }

            this.trigger('apply', data);
            this.close();
        },
    });
});
