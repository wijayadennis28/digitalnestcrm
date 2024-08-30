<?php

namespace Espo\Modules\Advanced\Tools\Report\ListType;

use Espo\Core\Select\SearchParams;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Entities\User;
use Espo\Modules\Advanced\Tools\Report\GridType\Data as Data;
use Espo\Modules\Advanced\Tools\Report\SelectHelper;
use Espo\ORM\Query\Select;

class SubListQueryPreparator
{
    private SubReportQueryPreparator $subReportQueryPreparator;
    private SelectHelper $selectHelper;

    public function __construct(
        SubReportQueryPreparator $subReportQueryPreparator,
        SelectHelper $selectHelper
    ) {
        $this->subReportQueryPreparator = $subReportQueryPreparator;
        $this->selectHelper = $selectHelper;
    }

    /**
     * @param ?scalar $groupValue
     * @param string[] $columnList
     * @param string[] $realColumnList
     */
    public function prepare(
        Data $data,
        $groupValue,
        array $columnList,
        array $realColumnList,
        ?WhereItem $where,
        ?User $user
    ): Select {

        $searchParams = SearchParams::create()->withSelect(['id']);

        if ($where) {
            $searchParams = $searchParams->withWhere($where);
        }

        $queryBuilder = $this->subReportQueryPreparator->prepare(
            $data,
            $searchParams,
            new SubReportParams(0, $groupValue),
            $user
        );

        $this->selectHelper->handleColumns($realColumnList, $queryBuilder);

        $newOrderBy = [];

        foreach ($data->getOrderBy() ?? [] as $orderByItem) {
            $orderByColumn = explode(':', $orderByItem)[1] ?? null;

            if (in_array($orderByColumn, $columnList)) {
                $newOrderBy[] = $orderByItem;
            }
        }

        if ($newOrderBy !== []) {
            $queryBuilder->order([]);
        }

        $this->selectHelper->handleOrderBy($newOrderBy, $queryBuilder);

        return $queryBuilder->build();
    }
}