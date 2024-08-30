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

define('advanced:views/report/reports/charts/grid1bar-horizontal',
['advanced:views/report/reports/charts/grid1bar-vertical'], function (Dep) {

    return Dep.extend({

        noLegend: true,
        rowHeight: 25,
        zooming: false,

        calculateHeight: function () {
            let number = this.grList.length;

            if (this.columnList && this.columnList.length > 1) {
                number *= this.columnList.length;
            }

            return number * this.rowHeight;
        },

        prepareData: function () {
            const result = this.result;
            const grList = this.grList = Espo.Utils.clone(result.grouping[0]);

            grList.reverse();

            if (this.options.color) {
                this.colorList = Espo.Utils.clone(this.colorList);
                this.colorList[0] = this.options.color;
            }

            const columnList = this.columnList = this.columnList || [this.column];
            let baseShift = 1, middleIndex;

            if (this.columnList) {
                if (this.columnList.length > 1) {
                    this.barWidth = 1 / (this.columnList.length) * 0.65;
                }

                baseShift = 1 / this.columnList.length;
                middleIndex = Math.ceil(this.columnList.length / 2) - 1;

                if (this.columnList.length > 1) {
                    this.noLegend = false;
                }
            }

            let max = 0;
            let max2 = 0;

            let min = 0;
            let min2 = 0;

            const chartData = [];

            columnList.forEach((column, j) => {
                const columnData = {
                    data: [],
                    label: this.reportHelper.formatColumn(column, this.result),
                    column: column,
                };

                let shift = 0;

                if (this.columnList) {
                    const diffIndex = j - middleIndex;

                    shift = baseShift * diffIndex;

                    if (this.columnList.length % 2 === 0) {
                        shift -= baseShift / 2;
                    }

                    shift *= 0.75;

                    if (this.secondColumnList && ~this.secondColumnList.indexOf(column)) {
                        columnData.xaxis = 2;
                    }
                }

                grList.forEach((group, i) => {
                    const value = (this.result.reportData[group] || {})[column] || 0;

                    if (this.secondColumnList && ~this.secondColumnList.indexOf(column)) {
                        if (value > max2) {
                            max2 = value;
                        }

                        if (value < min2) {
                            min2 = value;
                        }
                    } else {
                        if (value > max) {
                            max = value;
                        }

                        if (value < min) {
                            min = value;
                        }
                    }

                    columnData.data.push([value, i - shift]);

                    columnData.value = value;
                });

                if (column in this.colors) {
                    columnData.color = this.colors[column];
                }

                chartData.push(columnData);
            });

            this.max = max;
            this.max2 = max2;

            this.min = min;
            this.min2 = min2;

            this.chartData = chartData;
        },

        getTickNumber: function () {
            const containerHeight = this.$container.height();

            return Math.floor(containerHeight / this.rowHeight);
        },

        draw: function () {
            if (this.$container.height() === 0) {
                this.$container.empty();

                return;
            }

            if (this.isNoData()) {
                this.showNoData();

                return;
            }

            if (this.$container.height() === 0) {
                return;
            }

            const tickNumber = this.getTickNumber();

            this.$graph = this.flotr.draw(this.$container.get(0), this.chartData, {
                shadowSize: false,
                colors: this.colorList,
                bars: {
                    show: true,
                    horizontal: true,
                    shadowSize: 0,
                    lineWidth: 1,
                    fillOpacity: 1,
                    barWidth: this.barWidth,
                },
                grid: {
                    horizontalLines: false,
                    verticalLines: true,
                    outline: 'sw',
                    color: this.gridColor,
                    tickColor: this.tickColor,
                },
                yaxis: {
                    min: 0,
                    color: this.textColor,
                    noTicks: tickNumber,
                    title: '&nbsp;',
                    tickFormatter: value => {
                        if (value % 1 == 0) {
                            let i = parseInt(value);

                            if (i in this.grList) {
                                return this.formatGroup(0, this.grList[i]);
                            }
                        }

                        return '';
                    },
                },
                xaxis: {
                    min: this.min + 0.08 * this.min,
                    showLabels: true,
                    color: this.textColor,
                    max: this.max + 0.08 * this.max,
                    tickFormatter: value => {
                        if (value == 0 && this.min === 0) {
                            return '';
                        }

                        if (value % 1 == 0) {
                            if (value > this.max + 0.05 * this.max) {
                                return '';
                            }

                            return this.formatNumber(Math.floor(value), this.isCurrency, true, true, true).toString();
                        }

                        return '';
                    },
                },
                x2axis: {
                    min: this.min2 + 0.08 * this.min2,
                    showLabels: false,
                    color: this.textColor,
                    max: this.max2 + 0.08 * this.max2,
                    tickFormatter: value => {
                        if (value == 0 && this.min2 === 0) {
                            return '';
                        }

                        if (value % 1 == 0) {
                            if (value > this.max2 + 0.05 * this.max2) {
                                return '';
                            }

                            return this.formatNumber(Math.floor(value), false, true, true).toString();
                        }

                        return '';
                    },
                },
                mouse: {
                    track: true,
                    relative: true,
                    position: 'w',
                    autoPositionHorizontal: true,
                    lineColor: this.hoverColor,
                    cursorPointer: true,
                    trackFormatter: obj => {
                        const i = obj.index;
                        const column = obj.series.column;
                        let string = this.formatGroup(0, this.grList[i]);

                        if (this.columnList) {
                            if (string) {
                                string += '<br>';
                            }

                            string += obj.series.label;
                        }

                        if (string) {
                            string += '<br>';
                        }

                        string += this.formatCellValue(obj.x, column);

                        return string;
                    },
                },
                legend: {
                    show: !this.noLegend,
                    noColumns: this.getLegendColumnNumber(),
                    container: this.$el.find('.legend-container'),
                    labelBoxMargin: 0,
                    labelFormatter: this.labelFormatter.bind(this),
                    labelBoxBorderColor: 'transparent',
                    backgroundOpacity: 0,
                },
            });

            Flotr.EventAdapter.observe(this.$container.get(0), 'flotr:click', position => {
                if (!position.hit) {
                    return;
                }

                if (!('index' in position.hit)) {
                    return;
                }

                let column = null;

                if (this.result.isJoint) {
                    if (this.columnList) {
                        column = this.columnList[position.hit.seriesIndex];
                    } else {
                        column = this.column;
                    }
                }

                this.trigger('click-group', this.grList[position.hit.index], undefined, undefined, column);
            });

            if (!this.noLegend) {
                this.adjustLegend();
            }
        }
    });
});
