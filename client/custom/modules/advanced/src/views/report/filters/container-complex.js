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

define('advanced:views/report/filters/container-complex', ['views/record/base', 'model'], function (Dep, Model) {

    return Dep.extend({

        template: 'advanced:report/filters/container-complex',

        events: {
            'click > div > a[data-action="removeGroup"]': function () {
                this.trigger('remove-item');
            }
        },

        setup: function () {
            var model = this.model = new Model;
            model.name = 'Report';

            Dep.prototype.setup.call(this);

            this.scope = this.options.scope;
            this.filterData = this.options.filterData || {};

            var params = this.filterData.params || {};

            var functionList;

            if (!this.options.isHaving) {
                functionList = Espo.Utils.clone(
                    this.getMetadata().get(['entityDefs', 'Report', 'complexExpressionFunctionList']) || []);


                functionList.unshift('customWithOperator');
                functionList.unshift('custom');
                functionList.unshift('');
            } else {
                functionList = Espo.Utils.clone(
                    this.getMetadata().get(['entityDefs', 'Report', 'complexExpressionHavingFunctionList']) || []);

                functionList.unshift('customWithOperator');
                functionList.unshift('custom');
            }

            var operatorList;

            if (!this.options.isHaving) {
                operatorList = Espo.Utils.clone(
                    this.getMetadata().get(['entityDefs', 'Report', 'complexExpressionOperatorList']) || []);

            } else {
                operatorList = Espo.Utils.clone(
                    this.getMetadata().get(['entityDefs', 'Report', 'complexExpressionHavingOperatorList']) || []);
            }

            model.set({
                'function': params.function,
                attribute: params.attribute,
                operator: params.operator,
                expression: params.expression,
                value: params.value,
            });

            this.createView('function', 'views/fields/enum', {
                el: this.getSelector() + ' .function-container',
                params: {
                    options: functionList
                },
                name: 'function',
                model: model,
                mode: 'edit'
            }, function (view) {
                this.listenTo(view, 'after:render', function () {
                    view.$el.find('.form-control').addClass('input-sm');
                }, this);
            });

            this.createView('operator', 'views/fields/enum', {
                el: this.getSelector() + ' .operator-container',
                params: {
                    options: operatorList
                },
                name: 'operator',
                model: model,
                mode: 'edit',
            }, function (view) {
                this.listenTo(view, 'after:render', function () {
                    view.$el.find('.form-control').addClass('input-sm');
                }, this);
            });

            this.setupAttributes();

            this.createView('attribute', 'views/fields/enum', {
                el: this.getSelector() + ' .attribute-container',
                params: {
                    options: this.attributeList,
                    translatedOptions: this.translatedOptions
                },
                name: 'attribute',
                model: model,
                mode: 'edit',
            }, function (view) {
                this.listenTo(view, 'after:render', function () {
                    view.$el.find('.form-control').addClass('input-sm');
                }, this);
            });

            this.createView('value', 'views/fields/formula', {
                el: this.getSelector() + ' .value-container',
                params: {
                    height: 50
                },
                name: 'value',
                model: model,
                mode: 'edit',
                allowedFunctionList: [
                    'datetime\\',
                    'string\\',
                    'env\\userAttribute',
                ],
            });

            let expressionFieldViewName = this.complexExpressionFieldIsAvailable() ?
                'views/fields/complex-expression' :
                'views/fields/varchar';

            this.createView('expression', expressionFieldViewName, {
                el: this.getSelector() + ' .expression-container',
                name: 'expression',
                model: model,
                mode: 'edit',
                targetEntityType: this.scope,
            }, view => {
                this.listenTo(view, 'after:render', () => {
                    view.$el.find('.form-control').addClass('input-sm');
                });
            });

            this.controlVisibility();

            this.listenTo(this.model, 'change:operator', function () {
                this.controlVisibility();
            }, this);

            this.listenTo(this.model, 'change:function', function () {
                this.controlVisibility();
            }, this);
        },

        controlVisibility: function () {
            var func = this.model.get('function');

            if (func === 'custom') {
                this.hideField('attribute');
                this.hideField('operator');
                this.hideField('value');
                this.showField('expression');
            } else if (func === 'customWithOperator') {
                this.hideField('attribute');
                this.showField('operator');
                this.showField('value');
                this.showField('expression');
            } else {
                this.hideField('expression');
                this.showField('attribute');
                this.showField('value');
                this.showField('operator');
            }

            if (func !== 'custom') {
                if (~['isNull', 'isNotNull', 'isTrue', 'isFalse'].indexOf(this.model.get('operator'))) {
                    this.hideField('value');
                } else {
                    this.showField('value');

                    if (this.getFieldView('value') && this.getFieldView('value').isRendered()) {
                        this.getFieldView('value').reRender();
                    }
                }
            }
        },

        getAttributeListForScope: function (entityType) {
            let fieldList = this.getFieldManager().getEntityTypeFieldList(entityType).filter(item => {
                var defs = this.getMetadata().get(['entityDefs', entityType, 'fields', item]) || {};

                if (defs.notStorable) {
                    return;
                }

                if (!defs.type) {
                    return;
                }

                var type = defs.type;

                if (defs.directAccessDisabled) {
                    return;
                }

                if (defs.reportDisabled) {
                    return;
                }

                if (defs.disabled || defs.utility) {
                    return;
                }

                if (~['linkMultiple', 'email', 'phone'].indexOf(type)) {
                    return;
                }


                if (this.options.isHaving) {
                    if (!~['int', 'float', 'currency', 'currencyConverted'].indexOf(type)) {
                        return;
                    }
                }

                if (!this.getFieldManager().isEntityTypeFieldAvailable(entityType, item)) {
                    return;
                }

                return true;
            });

            let attributeList = [];

            fieldList.forEach(item => {
                var defs = this.getMetadata().get(['entityDefs', entityType, 'fields', item]) || {};

                if (this.options.isHaving) {
                    if (defs.type === 'currency') {
                        attributeList.push(item);

                        return;
                    }
                }

                this.getFieldManager().getAttributeList(defs.type, item).forEach(attr => {
                    if (~attributeList.indexOf(attr)) {
                        return;
                    }

                    attributeList.push(attr);
                });
            });

            if (this.options.isHaving) {
                attributeList.push('id');
            }

            attributeList.sort();

            return attributeList;
        },

        setupAttributes: function () {
            let entityType = this.scope;

            let attributeList = this.getAttributeListForScope(entityType);

            let links = this.getMetadata().get(['entityDefs', this.options.scope, 'links']);
            let linkList = [];

            Object.keys(links).forEach(link => {
                var type = links[link].type;

                if (!type) {
                    return;
                }

                if (links[link].disabled || links[link].utility) {
                    return;
                }

                if (~['belongsToParent', 'hasOne', 'belongsTo'].indexOf(type)) {
                    linkList.push(link);
                }

                if (this.options.isHaving) {
                    if (type === 'hasMany') {
                        linkList.push(link);
                    }
                }
            });

            linkList.sort();

            linkList.forEach(link => {
                var scope = links[link].entity;

                if (!scope) {
                    return;
                }

                var linkAttributeList = this.getAttributeListForScope(scope, true);

                linkAttributeList.forEach(item => {
                    attributeList.push(link + '.' + item);
                });
            });

            this.attributeList = attributeList;

            this.setupTranslatedOptions();
        },

        setupTranslatedOptions: function () {
            this.translatedOptions = {};

            var entityType = this.scope;

            this.attributeList.forEach(item => {
                var field = item;
                var scope = entityType;
                var isForeign = false;

                if (~item.indexOf('.')) {
                    isForeign = true;
                    field = item.split('.')[1];
                    var link = item.split('.')[0];

                    scope = this.getMetadata().get('entityDefs.' + entityType + '.links.' + link + '.entity');
                }

                this.translatedOptions[item] = this.translate(field, 'fields', scope);

                if (field.indexOf('Id') === field.length - 2) {
                    let baseField = field.substr(0, field.length - 2);

                    if (this.getMetadata().get(['entityDefs', scope, 'fields', baseField])) {
                        this.translatedOptions[item] = this.translate(baseField, 'fields', scope) +
                            ' (' + this.translate('id', 'fields') + ')';
                    }
                } else if (field.indexOf('Name') === field.length - 4) {
                    let baseField = field.substr(0, field.length - 4);

                    if (this.getMetadata().get(['entityDefs', scope, 'fields', baseField])) {
                        this.translatedOptions[item] = this.translate(baseField, 'fields', scope) +
                            ' (' + this.translate('name', 'fields') + ')';
                    }
                } else if (field.indexOf('Type') === field.length - 4) {
                    let baseField = field.substr(0, field.length - 4);

                    if (this.getMetadata().get(['entityDefs', scope, 'fields', baseField])) {
                        this.translatedOptions[item] = this.translate(baseField, 'fields', scope) +
                            ' (' + this.translate('type', 'fields') + ')';
                    }
                }

                if (field.indexOf('Ids') === field.length - 3) {
                    let baseField = field.substr(0, field.length - 3);

                    if (this.getMetadata().get(['entityDefs', scope, 'fields', baseField])) {
                        this.translatedOptions[item] = this.translate(baseField, 'fields', scope) +
                            ' (' + this.translate('ids', 'fields') + ')';
                    }
                } else if (field.indexOf('Names') === field.length - 5) {
                    let baseField = field.substr(0, field.length - 5);

                    if (this.getMetadata().get(['entityDefs', scope, 'fields', baseField])) {
                        this.translatedOptions[item] = this.translate(baseField, 'fields', scope) +
                            ' (' + this.translate('names', 'fields') + ')';
                    }
                } else if (field.indexOf('Types') === field.length - 5) {
                    let baseField = field.substr(0, field.length - 5);

                    if (this.getMetadata().get(['entityDefs', scope, 'fields', baseField])) {
                        this.translatedOptions[item] = this.translate(baseField, 'fields', scope) +
                            ' (' + this.translate('types', 'fields') + ')';
                    }
                }

                if (isForeign) {
                    this.translatedOptions[item] = this.translate(link, 'links', entityType) + '.' +
                        this.translatedOptions[item];
                }
            });
        },

        fetch: function () {
            this.getView('function').fetchToModel();
            this.getView('attribute').fetchToModel();
            this.getView('operator').fetchToModel();
            this.getView('value').fetchToModel();
            this.getView('expression').fetchToModel();

            var expression = this.model.get('expression');
            var func = this.model.get('function') || null;
            var attribute = this.model.get('attribute');
            var operator = this.model.get('operator') || null;
            var value = this.model.get('value');

            if (func === 'custom') {
                attribute = null;
                operator = null;
                value = null;
            } else if (func === 'customWithOperator') {
                attribute = null;
            } else {
                expression = null;
            }

            return {
                id: this.filterData.id,
                type: 'complexExpression',
                params: {
                    'function': func,
                    'attribute': attribute,
                    'operator': operator,
                    'value': value,
                    'expression': expression,
                }
            };
        },

         complexExpressionFieldIsAvailable: function () {
            let version = this.getConfig().get('version');

            if (version === '@@version' || this._isVersionGraterThanOrEqual('7.0.9', version)) {
                return true;
            }

            return false;
        },

        _isVersionGraterThanOrEqual: function (version1, version2) {
            if (version1 === version2) {
                return true;
            }

            let parts1 = version1.split('.');
            let parts2 = version2.split('.');

            let length = parts2.length;

            if (length > 3) {
                length = 3;
            }

            for (let i = 0; i < length; i++) {
                let a = ~~parts2[i];
                let b = ~~parts1[i];

                if (a > b) {
                    return true;
                }

                if (a < b) {
                    return false;
                }
            }

            return false;
        },
    });
});
