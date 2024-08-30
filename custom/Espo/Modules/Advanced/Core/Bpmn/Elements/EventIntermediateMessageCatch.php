<?php
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

namespace Espo\Modules\Advanced\Core\Bpmn\Elements;

use Espo\Core\Utils\Config;
use Espo\Entities\Email;
use Espo\Modules\Advanced\Entities\BpmnFlowNode;

class EventIntermediateMessageCatch extends Event
{
    public function process(): void
    {
        $flowNode = $this->getFlowNode();
        $flowNode->set(['status' => BpmnFlowNode::STATUS_PENDING]);
        $this->getEntityManager()->saveEntity($flowNode);
    }

    public function proceedPending(): void
    {
        $repliedToAliasId = $this->getAttributeValue('repliedTo');
        $messageType = $this->getAttributeValue('messageType') ?? 'Email';
        $relatedTo = $this->getAttributeValue('relatedTo');

        $conditionsFormula = $this->getAttributeValue('conditionsFormula');
        $conditionsFormula = trim($conditionsFormula, " \t\n\r");

        if (strlen($conditionsFormula) && substr($conditionsFormula, -1) === ';') {
            $conditionsFormula = substr($conditionsFormula, 0, -1);
        }

        $target = $this->getTarget();

        $createdEntitiesData = $this->getCreatedEntitiesData() ?? (object) [];

        $repliedToId = null;

        if ($repliedToAliasId) {
            if (!isset($createdEntitiesData->$repliedToAliasId)) {
                return;
            }

            $repliedToId = $createdEntitiesData->$repliedToAliasId->entityId ?? null;
            $repliedToType = $createdEntitiesData->$repliedToAliasId->entityType ?? null;

            if (!$repliedToId || $messageType !== $repliedToType) {
                $this->fail();

                return;
            }
        }

        $flowNode = $this->getFlowNode();

        if ($messageType === 'Email') {
            $from = $flowNode->getDataItemValue('checkedAt') ?? $flowNode->get('createdAt');

            $whereClause = [
                'createdAt>=' => $from,
                'status' => Email::STATUS_ARCHIVED,
                'dateSent>=' => $flowNode->get('createdAt'),
                [
                    'OR' => [
                        'sentById' => null,
                        'sentBy.type' => 'portal', // @todo Change to const.
                    ]
                ],
            ];

            if ($repliedToId) {
                $whereClause['repliedId'] = $repliedToId;

            } else if ($relatedTo) {
                $relatedTarget = $this->getSpecificTarget($relatedTo);

                if (!$relatedTarget) {
                    $this->updateCheckedAt();

                    return;
                }

                if ($relatedTarget->getEntityType() === 'Account') {
                    $whereClause['accountId'] = $relatedTarget->getId();
                } else {
                    $whereClause['parentId'] = $relatedTarget->getId();
                    $whereClause['parentType'] = $relatedTarget->getEntityType();
                }
            }

            if (!$repliedToId && !$relatedTo) {
                if ($target->getEntityType() === 'Contact' && $target->get('accountId')) {
                    $whereClause[] = [
                        'OR' => [
                            [
                                'parentType' => 'Contact',
                                'parentId' => $target->getId(),
                            ],
                            [
                                'parentType' => 'Account',
                                'parentId' => $target->get('accountId'),
                            ],
                        ]
                    ];
                }
                else if ($target->getEntityType() === 'Account') {
                    $whereClause['accountId'] = $target->getId();
                }
                else {
                    $whereClause['parentId'] = $target->getId();
                    $whereClause['parentType'] = $target->getEntityType();
                }
            }

            /** @var Config $config */
            $config = $this->getContainer()->get('config');

            $limit = $config->get('bpmnMessageCatchLimit', 50);

            $emailList = $this->getEntityManager()
                ->getRDBRepository(Email::ENTITY_TYPE)
                ->leftJoin('sentBy')
                ->where($whereClause)
                ->limit(0, $limit)
                ->find();

            if (is_countable($emailList) && !count($emailList)) {
                $this->updateCheckedAt();

                return;
            }

            if ($conditionsFormula) {
                $isFound = false;

                foreach ($emailList as $email) {
                    $formulaResult = $this->getFormulaManager()
                        ->run($conditionsFormula, $email, $this->getVariablesForFormula());

                    if ($formulaResult) {
                        $isFound = true;

                        break;
                    }
                }

                if (!$isFound) {
                    $this->updateCheckedAt();

                    return;
                }
            }
        }
        else {
            $this->fail();

            return;
        }

        $flowNode = $this->getFlowNode();
        $flowNode->set('status', BpmnFlowNode::STATUS_IN_PROCESS);
        $this->getEntityManager()->saveEntity($flowNode);

        $this->proceedPendingFinal();
    }

    protected function proceedPendingFinal(): void
    {
        $this->rejectConcurrentPendingFlows();
        $this->processNextElement();
    }

    protected function updateCheckedAt(): void
    {
        $flowNode = $this->getFlowNode();
        $flowNode->setDataItemValue('checkedAt', date('Y-m-d H:i:s'));
        $this->getEntityManager()->saveEntity($flowNode);
    }
}
