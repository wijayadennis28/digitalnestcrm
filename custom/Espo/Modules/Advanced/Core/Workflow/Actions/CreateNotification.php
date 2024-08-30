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

namespace Espo\Modules\Advanced\Core\Workflow\Actions;

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Entities\Notification;
use Espo\Entities\User;
use Espo\ORM\Entity;
use stdClass;

class CreateNotification extends Base
{
    protected function run(Entity $entity, stdClass $actionData): bool
    {
        if (empty($actionData->recipient)) {
            return false;
        }

        if (empty($actionData->messageTemplate)) {
            return false;
        }

        if (!$entity instanceof CoreEntity) {
            return false;
        }

        $userList = [];

        switch ($actionData->recipient) {
            case 'specifiedUsers':
                if (empty($actionData->userIdList) || !is_array($actionData->userIdList)) {
                    return false;
                }

                $userIds = $actionData->userIdList;

                break;

            case 'specifiedTeams':
                $userIds = $this->getHelper()->getUserIdsByTeamIds($actionData->specifiedTeamsIds);

                break;

            case 'teamUsers':
                $entity->loadLinkMultipleField('teams');
                $userIds = $this->getHelper()->getUserIdsByTeamIds($entity->get('teamsIds'));

                break;

            case 'followers':
                $userIds = $this->getHelper()->getFollowerUserIds($entity);

                break;

            case 'followersExcludingAssignedUser':
                $userIds = $this->getHelper()->getFollowerUserIdsExcludingAssignedUser($entity);
                break;

            case 'currentUser':
                $userIds = [$this->getUser()->getId()];

                break;

            default:
                $userIds = $this->getRecipients($this->getEntity(), $actionData->recipient)->getIds();

                break;
        }

        foreach ($userIds as $userId) {
            $user = $this->getEntityManager()->getEntityById(User::ENTITY_TYPE, $userId);

            $userList[] = $user;
        }

        $message = $actionData->messageTemplate;

        $variables = $this->getVariables() ?? (object) [];

        if ($variables) {
            foreach (get_object_vars($variables) as $key => $value) {
                if (is_string($value) || is_int($value) || is_float($value)) {
                    if (is_int($value) || is_float($value)) {
                        $value = strval($value);
                    } else {
                        if (!$value) {
                            continue;
                        }
                    }

                    $message = str_replace('{$$' . $key . '}', $value, $message);
                }
            }
        }

        foreach ($userList as $user) {
            $notification = $this->getEntityManager()->getNewEntity(Notification::ENTITY_TYPE);

            $notification->set([
                'type' => Notification::TYPE_MESSAGE,
                'data' => [
                    'entityId' => $entity->getId(),
                    'entityType' => $entity->getEntityType(),
                    'entityName' => $entity->get('name'),
                    'userId' => $this->getUser()->getId(),
                    'userName' => $this->getUser()->getName(),
                ],
                'userId' => $user->getId(),
                'message' => $message,
                'relatedId' => $entity->getId(),
                'relatedType' => $entity->getEntityType(),
            ]);

            $this->getEntityManager()->saveEntity($notification);
        }

        return true;
    }
}
