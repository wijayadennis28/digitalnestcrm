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

define('advanced:views/report/reports/tables/grid2', ['view'], function (Dep) {

    return Dep.extend({

        template: 'advanced:report/reports/tables/table',

        columnWidthPx: 110,
        columnWidth2Px: 140,
        firstColumnWidthPx: 170,
        nonSummaryColumnWidthPx: 150,

        setup: function () {
            this.column = this.options.column;
            this.result = this.options.result;
            this.reportHelper = this.options.reportHelper;

            let formatData = this.reportHelper.getFormatData(this.getConfig(), this.getPreferences());

            this.decimalMark = formatData.decimalMark;
            this.thousandSeparator = formatData.thousandSeparator;
            this.currencyDecimalPlaces = formatData.currencyDecimalPlaces;
            this.currencySymbol = formatData.currencySymbol;
            this.currency = formatData.currency;
        },

        events: {
            'click [data-action="showSubReport"]': function (e) {
                let $target = $(e.currentTarget);

                let value = $target.attr('data-group-value');
                let index = parseInt($target.attr('data-group-index') || 0);

                this.trigger(
                    'click-group',
                    value,
                    index
                );
            },
        },

        formatGroup: function (i, value) {
            let gr = this.result.groupByList[i];

            return this.reportHelper.formatGroup(gr, value, this.result);
        },

        formatCellValue: function (value, column, isTotal) {
            if (!this.options.reportHelper.isColumnNumeric(column, this.result)) {
                if (this.result.cellValueMaps && this.result.cellValueMaps[column]) {
                    value = this.result.cellValueMaps[column][value] || value || '';
                }

                if (Array.isArray(value)) {
                    return value.join(', ');
                }

                return value;
            }

            value = value || 0;

            let isCurrency = false;

            let arr = column.split(':');

            if (arr.length === 1) {
                arr = ['', column];
            }

            if (arr.length > 1 && !column.includes(':(')) {
                let data = this.reportHelper.getGroupFieldData(column, this.result);

                if (data) {
                    let entityType = data.entityType;
                    let field = data.field;
                    let fieldType = data.fieldType;

                    isCurrency = ['currency', 'currencyConverted'].includes(fieldType);

                    if (!isCurrency && entityType === 'Opportunity' && field === 'amountWeightedConverted') {
                        isCurrency = true;
                    }
                }
            }

            if (!isTotal && value == 0) {
                if (~column.indexOf('COUNT:')) {
                    return '<span class="text-muted">' + 0 + '</span>';
                }

                return '<span class="text-muted">' + this.formatNumber(0) + '</span>';
            }

            if (~column.indexOf('COUNT:')) {
                return this.formatNumber(value);
            }

            let columnDecimalPlacesMap = this.result.columnDecimalPlacesMap || {};
            let decimalPlaces = columnDecimalPlacesMap[column];

            return this.reportHelper.formatNumber(value, isCurrency, null, null, null, decimalPlaces);
        },

        formatNumber: function (value, isCurrency) {
            return this.reportHelper.formatNumber(value, isCurrency);
        },

        formatNumber1: function (value, isCurrency) {
            if (!this.decimalMark) {
                if (this.getPreferences().has('decimalMark')) {
                    this.decimalMark = this.getPreferences().get('decimalMark');
                } else {
                    if (this.getConfig().has('decimalMark')) {
                        this.decimalMark = this.getConfig().get('decimalMark');
                    }
                }

                if (this.getPreferences().has('thousandSeparator')) {
                    this.thousandSeparator = this.getPreferences().get('thousandSeparator');
                } else {
                    if (this.getConfig().has('thousandSeparator')) {
                        this.thousandSeparator = this.getConfig().get('thousandSeparator');
                    }
                }
            }

            if (value !== null) {
                let maxDecimalPlaces = 2;
                let currencyDecimalPlaces = this.getConfig().get('currencyDecimalPlaces');

                if (isCurrency) {
                    if (currencyDecimalPlaces === 0) {
                        value = Math.round(value);
                    } else if (currencyDecimalPlaces) {
                        value = Math.round(value *
                            Math.pow(10, currencyDecimalPlaces)) / (Math.pow(10, currencyDecimalPlaces));
                    } else {
                        value = Math.round(value * Math.pow(10, maxDecimalPlaces)) / (Math.pow(10, maxDecimalPlaces));
                    }
                } else {
                    let maxDecimalPlaces = 4;

                    value = Math.round(value * Math.pow(10, maxDecimalPlaces)) / (Math.pow(10, maxDecimalPlaces));
                }

                let parts = value.toString().split(".");
                parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, this.thousandSeparator);

                if (isCurrency) {
                    if (currencyDecimalPlaces === 0) {
                        delete parts[1];
                    } else if (currencyDecimalPlaces) {
                        let decimalPartLength = 0;

                        if (parts.length > 1) {
                            decimalPartLength = parts[1].length;
                        } else {
                            parts[1] = '';
                        }

                        if (currencyDecimalPlaces && decimalPartLength < currencyDecimalPlaces) {
                            let limit = currencyDecimalPlaces - decimalPartLength;

                            for (let i = 0; i < limit; i++) {
                                parts[1] += '0';
                            }
                        }
                    }
                }

                return parts.join(this.decimalMark);
            }

            return '';
        },

        afterRender: function () {
            let result = this.result;

            let group1NonSummaryColumnList = [];
            let group2NonSummaryColumnList = [];

            if (this.result.nonSummaryColumnList) {
                this.result.nonSummaryColumnList.forEach(column => {
                    let group = this.result.nonSummaryColumnGroupMap[column];

                    if (group === this.result.groupByList[0]) {
                        group1NonSummaryColumnList.push(column);
                    }

                    if (group === this.result.groupByList[1]) {
                        group2NonSummaryColumnList.push(column);
                    }
                });
            }

            let columnCount = (this.result.grouping[0].length + 1) + group2NonSummaryColumnList.length;

            let summaryColumnCount = this.result.grouping[0].length;

            if (this.result.group2Sums) {
                summaryColumnCount++;
            }

            let nonSummaryColumnCount = group2NonSummaryColumnList.length;

            let columnWidthPx = this.columnWidthPx;

            let columnData = this.reportHelper.getGroupFieldData(this.column, result);

            if (columnData && columnData.fieldType !== 'int' && columnData.function !== 'COUNT') {
                columnWidthPx = this.columnWidth2Px;
            }

            if (group1NonSummaryColumnList.length) {
                columnWidthPx = this.nonSummaryColumnWidthPx;
            }

            let ratio1 = this.firstColumnWidthPx / columnWidthPx;
            let ratio2 = this.nonSummaryColumnWidthPx / columnWidthPx;

            let summaryColumnWidth = 100 / (ratio1 + ratio2 * nonSummaryColumnCount + summaryColumnCount);

            let nonSummaryColumnWidth = summaryColumnWidth * ratio2;

            let firstColumnWidth = 100 - nonSummaryColumnWidth * nonSummaryColumnCount -
                summaryColumnWidth * summaryColumnCount;

            //let firstColumnWidthPx = summaryColumnWidth * ratio1;

            let $table = $('<table style="table-layout: fixed;">')
                .addClass('table table-no-overflow')
                .addClass('table-bordered');

            let $tbody = $('<tbody>');

            $table.append($tbody);

            let summaryColumnWidthPx = columnWidthPx;

            if (columnCount > 7) {
                let tableWidthPx =
                    summaryColumnWidthPx * summaryColumnCount +
                    this.nonSummaryColumnWidthPx * nonSummaryColumnCount + this.firstColumnWidthPx;

                $table.css('min-width', tableWidthPx  + 'px');
            }

            if (!this.options.hasChart || this.options.isLargeMode) {
                $table.addClass('no-margin');
            }

            if (!this.options.hasChart || this.options.showChartFirst) {
                //this.$el.addClass('no-bottom-margin');
            }

            let $tr = $('<tr class="accented">');

            let $th = $('<th width="'+ firstColumnWidth.toString() +'%">');

            $th.css({'word-wrap': 'break-word'});

            $th.html('&nbsp;');
            $tr.append($th);

            group2NonSummaryColumnList.forEach(column => {
                let columnTitle = this.reportHelper.formatColumn(column, this.result);
                let $th = $('<th width="'+nonSummaryColumnWidth+'%">').html(columnTitle)

                $th.addClass('text-soft');
                $th.css({'word-wrap': 'break-word'});
                $th.css({'font-weight': '600'});

                $tr.append($th);
            });

            this.result.grouping[0].forEach(gr1 => {
                let $a = $(
                    '<a role="button" tabindex="0" data-action="showSubReport" data-group-value="'+
                    Handlebars.Utils.escapeExpression(gr1)+'">' + this.formatGroup(0, gr1) + '</a>'
                );

                let $th = $('<th width="'+summaryColumnWidth+'%">').html($a)

                $th.css({'word-wrap': 'break-word'});

                $tr.append($th);
            });

            if (this.result.group2Sums) {
                let totalText = this.translate('Total', 'labels', 'Report');
                let $th = $('<th class="text-soft">').css({'font-weight': '600'}).html(totalText);

                $tr.append($th);
            }

            $tbody.append($tr);

            //var reportData = this.options.reportData;

            if (group1NonSummaryColumnList.length) {
                group1NonSummaryColumnList.forEach(column => {
                    let $tr = $('<tr class="accented">');
                    let columnTitle = this.reportHelper.formatColumn(column, this.result);

                    let $td = $('<td>').html(columnTitle);

                    $td.addClass('text-soft');
                    $td.css({'font-weight': '600'});
                    $tr.append($td);
                    $td.addClass('accented');

                    group2NonSummaryColumnList.forEach((column) => {
                        $tr.append('<td class="accented">');
                    });

                    this.result.grouping[0].forEach(gr1 => {
                        let group1Title = this.formatGroup(0, gr1);
                        let value = null;
                        let dataMap = result.nonSummaryData[result.groupByList[0]];

                        if ((gr1 in dataMap) && (column in dataMap[gr1])) {
                            value = dataMap[gr1][column];
                        }

                        let align = this.reportHelper.isColumnNumeric(column, result) ? 'right' : '';
                        let $td = $('<td align="'+align+'">').html(this.formatCellValue(value, column));
                        let title = this.unescapeString(group1Title) + '\n' + this.unescapeString(columnTitle);

                        $td.attr('title', title);
                        $td.css({'word-wrap': 'break-word'});

                        $tr.append($td);
                    });

                    if (this.result.group2Sums) {
                        $tr.append('<td class="accented">');
                    }

                    $tbody.append($tr);
                });
            }

            this.result.grouping[1].forEach(gr2 => {
                let $tr = $('<tr>');
                let group2Title = this.formatGroup(1, gr2);

                let $a =  $(
                    '<a role="button" tabindex="0" data-action="showSubReport" data-group-index="1" data-group-value="'+
                    Handlebars.Utils.escapeExpression(gr2)+'">' + group2Title + '</a>');

                let $td = $('<td>').html($a);

                $td.addClass('accented');
                $td.css({'word-wrap': 'break-word'});
                $tr.append($td);

                group2NonSummaryColumnList.forEach(column => {
                    let value = null;
                    let columnTitle = this.reportHelper.formatColumn(column, this.result);
                    let dataMap = result.nonSummaryData[result.groupByList[1]];

                    if ((gr2 in dataMap) && (column in dataMap[gr2])) {
                        value = dataMap[gr2][column];
                    }

                    let align = this.reportHelper.isColumnNumeric(column, result) ? 'right' : '';

                    let $td = $('<td class="accented" align="'+align+'" width="'+nonSummaryColumnWidth+'%">')
                        .html(this.formatCellValue(value, column));

                    let title = this.unescapeString(group2Title) + '\n' + this.unescapeString(columnTitle);

                    $td.attr('title', title);
                    $td.css({'word-wrap': 'break-word'});

                    $tr.append($td);
                });

                this.result.grouping[0].forEach(gr1 => {
                    let group1Title = this.formatGroup(0, gr1);
                    let value = 0;

                    if ((gr1 in result.reportData) && (gr2 in result.reportData[gr1])) {
                        value = result.reportData[gr1][gr2][this.column];
                    }

                    let title = this.unescapeString(group1Title) + '\n' + this.unescapeString(group2Title);

                    let $td = $('<td align="right" width="'+summaryColumnWidthPx+'%">')
                        .html(this.formatCellValue(value, this.column));

                    $td.attr('title', title);
                    $td.css({'word-wrap': 'break-word'});

                    $tr.append($td);
                });

                if (this.result.group2Sums) {
                    let value = 0;

                    if (gr2 in result.group2Sums) {
                        value = result.group2Sums[gr2][this.column];
                    }

                    let $td = $('<td class="accented" align="right">').css('font-weight', '600');
                    let text = this.formatCellValue(value, this.column, true);

                    $td.html(text);

                    let title = this.unescapeString(group2Title);

                    $td.attr('title', title);
                    $td.addClass('text-soft');
                    $tr.append($td);
                }

                $tbody.append($tr);
            });

            $tr = $('<tr class="accented">');

            let $totalText = $(
                '<strong class="text-soft">' + this.translate('Total', 'labels', 'Report') + '</strong>');

            $tr.append($('<td>').html($totalText));

            group2NonSummaryColumnList.forEach(() => {
                $tr.append('<td>');
            });

            this.result.grouping[0].forEach((gr1) => {
                let group1Title = this.formatGroup(0, gr1);
                let value = 0;

                if (gr1 in result.group1Sums) {
                    value = result.group1Sums[gr1][this.column];
                }

                let title = this.unescapeString(group1Title);
                let $text = $('<strong>' + this.formatCellValue(value, this.column, true) + '</strong>');
                let $td = $('<td align="right">').html($text);

                $td.css({'word-wrap': 'break-word'});
                $td.addClass('text-soft');
                $td.attr('title', title);
                $tr.append($td);
            });

            if (this.result.group2Sums) {
                let $td = $('<td class="accented" align="right">').css('font-weight', '600');
                let value = 0;

                if (this.column in result.sums) {
                    value = result.sums[this.column];
                }

                let text = this.formatCellValue(value, this.column, true);

                $td.html(text);
                $tr.append($td);
            }

            $tbody.append($tr);

            this.$tableContainer = this.$el.find('.table-container');

            this.$tableContainer.append($table);

            if (columnCount > 7) {
                this.$tableContainer.css('overflow-y', 'auto');
            }
        },

        unescapeString: function (value) {
            return $('<div>').html(value).text();
        },
    });
});
