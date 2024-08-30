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

namespace Espo\Modules\Advanced\Core\Workflow;

use Espo\Core\InjectableFactory;
use Espo\Core\ServiceFactory;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\Core\Container;
use Espo\ORM\EntityManager;
use Espo\Tools\Stream\Service;

class Helper
{
    private Container $container;
    private ?EntityHelper $entityHelper = null;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    private function getEntityManager(): EntityManager
    {
        /** @var EntityManager */
        return $this->container->get('entityManager');
    }

    private function getServiceFactory(): ServiceFactory
    {
        /** @var ServiceFactory */
        return $this->container->get('serviceFactory');
    }

    private function getInjectableFactory(): InjectableFactory
    {
        /** @var InjectableFactory */
        return $this->container->get('injectableFactory');
    }

    public function getEntityHelper(): EntityHelper
    {
        if (!isset($this->entityHelper)) {
            $this->entityHelper = new EntityHelper($this->container);
        }

        return $this->entityHelper;
    }

    /**
     * Get followers users ids.
     *
     * @param Entity $entity
     * @return string[]
     */
    public function getFollowerUserIds(Entity $entity): array
    {
        if (!class_exists("Espo\\Tools\\Stream\\Service")) {
            return $this->getServiceFactory()->create('Stream')->getEntityFolowerIdList($entity);
        }

        /** @var Service $streamService */
        $streamService = $this->getInjectableFactory()->create("Espo\\Tools\\Stream\\Service");

        return $streamService->getEntityFollowerIdList($entity);
    }

    /**
     * Get followers users ids excluding assignedUserId.
     *
     * @param Entity $entity
     * @return string[]
     */
    public function getFollowerUserIdsExcludingAssignedUser(Entity $entity): array
    {
        $userIds = $this->getFollowerUserIds($entity);

        if ($entity->get('assignedUserId')) {
            $assignedUserId = $entity->get('assignedUserId');
            $userIds = array_diff($userIds, [$assignedUserId]);
        }

        return $userIds;
    }

    /**
     * Get user ids for team ids.
     *
     * @param array $teamIds
     * @return string[]
     */
    public function getUserIdsByTeamIds(array $teamIds): array
    {
        if ($teamIds === []) {
            return [];
        }

        $userIds = [];

        $users = $this->getEntityManager()
            ->getRDBRepository(User::ENTITY_TYPE)
            ->select('id')
            ->distinct()
            ->join('teams', 'teams')
            ->where(['teams.id' => $teamIds])
            ->where(['isActive' => true])
            ->find();

        foreach ($users as $user) {
            $userIds[] = $user->getId();
        }

        return $userIds;
    }

    /**
     * Get email addresses for an entity with specified ids.
     *
     * @param string $entityType
     * @param array $entityIds
     * @return string[]
     */
    public function getEmailAddressesForEntity(string $entityType, array $entityIds): array
    {
        $entityList = $this->getEntityManager()
            ->getRDBRepository($entityType)
            ->select(['id', 'emailAddress'])
            ->where(['id' => $entityIds])
            ->find();

        $list = [];

        foreach ($entityList as $entity) {
            $emailAddress = $entity->get('emailAddress');

            if ($emailAddress) {
                $list[] = $emailAddress;
            }
        }

        return $list;
    }

    /**
     * Get primary email addresses for user list.
     *
     * @param string[] $userIds
     * @return string[]
     */
    public function getUsersEmailAddress(array $userIds): array
    {
        return $this->getEmailAddressesForEntity(User::ENTITY_TYPE, $userIds);
    }
}
