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

define('advanced:views/report/filters/node', ['view'], function (Dep) {

    return Dep.extend({

        template: 'advanced:report/filters/node',

        events: {
            'click > .buttons [data-action="addOr"]': function () {
                this.addOrGroup();
            },
            'click > .buttons [data-action="addAnd"]': function () {
                this.addAndGroup();
            },
            'click > .buttons [data-action="addNot"]': function () {
                this.addNotGroup();
            },
            'click > .buttons [data-action="addSubQueryIn"]': function () {
                this.addSubQueryInGroup();
            },
            'click > .buttons [data-action="addField"]': function () {
                this.addField();
            },
            'click > .buttons [data-action="addComplexExpression"]': function () {
                this.addComplexExpression();
            },
            'click > .buttons [data-action="addHavingGroup"]': function () {
                this.addHavingGroup();
            }
        },

        data: function () {
            var operator = this.getOperator();
            return {
                notDisabled: this.notDisabled,
                subQueryInDisabled: this.subQueryInDisabled,
                complexExpressionDisabled: this.complexExpressionDisabled,
                havingDisabled: this.havingDisabled,
                fieldDisabled: this.fieldDisabled,
                orDisabled: this.orDisabled || operator === 'or',
                andDisabled: this.andDisabled || operator === 'and',
                operator: operator,
            };
        },

        setup: function () {
            this.dataList = Espo.Utils.cloneDeep(this.options.dataList);
            this.scope = this.options.scope;

            this.level = this.options.level || 0;

            this.filterData = this.options.filterData || {};

            this.isHaving = this.filterData.type === 'having' || this.options.isHaving;

            if (this.level > 1 || this.filterData.type === 'not' || this.filterData.type === 'subQueryIn') {
                this.notDisabled = true;
                this.subQueryInDisabled = true;
            }

            if (this.level > 0) {
                this.havingDisabled = true;
            }

            if (this.isHaving) {
                this.fieldDisabled = true;
                this.notDisabled = true;
            }

            if (this.filterData.type === 'having') {
                this.andDisabled = true;
            }

            var version = this.getConfig().get('version') || '';

            var arr = version.split('.');
            if (version !== 'dev' && arr.length > 2 && parseInt(arr[0]) * 100 + parseInt(arr[1]) < 407) {
                this.notDisabled = true;
                this.complexExpressionDisabled = true;
            }

            if (version !== 'dev' && arr.length > 2) {
                if (parseInt(arr[0]) < 5 || parseInt(arr[0]) === 5 && parseInt(arr[1]) === 0) {
                    this.havingDisabled = true;
                }
            }

            if (version !== 'dev' && arr.length > 2 && parseInt(arr[0]) * 100 + parseInt(arr[1]) < 506) {
                this.subQueryInDisabled = true;
            }
        },

        afterRender: function () {
            this.$itemList = this.$el.find('> .item-list');

            this.dataList.forEach(function (item) {
                this.createItem(item);
            }, this);
        },

        fetch: function () {
            var newDataList = [];

            this.dataList.forEach(item => {
                var view = this.getView(item.id);

                if (!view) {
                    return;
                }

                var itemData = view.fetch();
                newDataList.push(itemData);
            });

            return newDataList;
        },

        getOperator: function () {
            if (this.filterData.type === 'or') {
                return 'or';
            }

            return 'and';
        },

        createItem: function (item, highlight) {
            var type = item.type;

            if (!item.id) {
                return;
            }

            var $item = $('<div>').attr('data-id', item.id);

            this.$itemList.append($item);

            var $operator = $('<div>');
            $operator.attr('data-item-id', item.id);
            $operator.addClass('node-operator');

            var operator = this.getOperator();
            var $operatorInner = $('<div>').addClass('form-group');

            $operatorInner.html(this.translate(operator, 'filtersGroupTypes', 'Report'));
            $operator.append($operatorInner);

            this.$itemList.append($operator);

            var view = 'advanced:views/report/filters/container';

            if (~['or', 'and', 'not', 'having', 'subQueryIn'].indexOf(type)) {
                view = 'advanced:views/report/filters/container-group';
            }
            else if (type === 'complexExpression') {
                view = 'advanced:views/report/filters/container-complex';
            }
            else {
                if (!item.name) {
                    return;
                }
            }

            this.createView(item.id, view, {
                el: this.getSelector() + ' [data-id="'+item.id+'"]',
                scope: this.scope,
                filterData: item,
                level: this.level + 1,
                isHaving: this.isHaving,
            }, view => {
                if (highlight) {
                    this.listenToOnce(view, 'after:render', () => {
                        if (~['or', 'and', 'not', 'having', 'subQueryIn'].indexOf(type)) {
                            var $label = view.$el.find('> label > span');
                            $label.addClass('text-danger');

                            setTimeout(() => {
                                $label.removeClass('text-danger');
                            }, 1500);
                        }
                        else {
                            var $form = view.$el.find('.form-group');
                            $form.addClass('has-error');

                            setTimeout(() => {
                                $form.removeClass('has-error');
                            }, 1500);
                        }
                    });
                }

                view.render();

                this.listenToOnce(view, 'remove-item', () => {
                    this.removeItem(item.id);
                });
            });
        },

        removeItem: function (id) {
            this.clearView(id);

            this.$el.find('[data-id="'+id+'"]').remove();

            this.$el.find('[data-item-id="'+id+'"]').remove();

            var index = -1;

            this.dataList.forEach((item, i) => {
                if (item.id === id) {
                    index = i;
                }
            });

            if (~index) {
                this.dataList.splice(index, 1);
            }
        },

        addOrGroup: function () {
            var item = {
                id: this.generateId(),
                type: 'or',
                params: {
                    type: 'or',
                    value: []
                }
            };

            this.dataList.push(item);
            this.createItem(item, true);
        },

        addHavingGroup: function () {
            var item = {
                id: this.generateId(),
                type: 'having',
                params: {
                    type: 'having',
                    value: []
                }
            };

            this.dataList.push(item);
            this.createItem(item, true);
        },

        addAndGroup: function () {
            var item = {
                id: this.generateId(),
                type: 'and',
                params: {
                    type: 'and',
                    value: []
                }
            };

            this.dataList.push(item);
            this.createItem(item, true);
        },

        addNotGroup: function () {
            var item = {
                id: this.generateId(),
                type: 'not',
                params: {
                    type: 'not',
                    value: []
                }
            };

            this.dataList.push(item);
            this.createItem(item, true);
        },

        addSubQueryInGroup: function () {
            var item = {
                id: this.generateId(),
                type: 'subQueryIn',
                params: {
                    type: 'subQueryIn',
                    value: []
                }
            };

            this.dataList.push(item);
            this.createItem(item, true);
        },

        addComplexExpression: function () {
            var item = {
                id: this.generateId(),
                type: 'complexExpression',
                params: {
                    function: !this.isHaving ? 'custom' : 'COUNT',
                    attribute: null,
                    operator: 'equals',
                    formula: '',
                }
            };

            this.dataList.push(item);
            this.createItem(item, true);
        },

        addField: function () {
            this.createView('modal', 'advanced:views/report/modals/add-filter-field', {
                scope: this.scope,
                level: this.level
            }, view => {
                view.render();

                this.listenToOnce(view, 'add-field', name => {
                    var item = {
                        id: this.generateId(),
                        name: name,
                        params: {}
                    };

                    this.dataList.push(item);
                    this.createItem(item, 1);

                    this.clearView('modal');
                });
            });
        },

        generateId: function () {
            return Math.random().toString(16).slice(2);
        },
    });
});
