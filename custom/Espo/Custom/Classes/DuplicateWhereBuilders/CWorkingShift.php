<?php
namespace Espo\Custom\Classes\DuplicateWhereBuilders;

use Espo\Core\Duplicate\WhereBuilder;

use Espo\ORM\Query\Part\Condition as Cond;
use Espo\ORM\Query\Part\WhereItem;
use Espo\ORM\Query\Part\Where\OrGroup;
use Espo\ORM\Entity;

class CWorkingShift implements WhereBuilder
{
    public function build(Entity $entity): ?WhereItem
    {
        $orBuilder = OrGroup::createBuilder();

        $toCheck = false;

        if ($entity->get('workingDate') && $entity->get('shift')) {
            $orBuilder->add(
                Cond::and(
                    Cond::equal(
                        Cond::column('workingDate'),
                        $entity->get('workingDate')
                    ),
                    Cond::equal(
                        Cond::column('shift'),
                        $entity->get('shift')
                    )
                )
            );

            $toCheck = true;
        }

        // Here you can add more conditions.

        if (!$toCheck) {
            return null;
        }

        return $orBuilder->build();
    }
}