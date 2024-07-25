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
 * License ID: bcd3361258b6d66fc350488ed9575786
 ************************************************************************************/

use Espo\Core\Container;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Metadata;
use Espo\Entities\Template;
use Espo\ORM\EntityManager;

class AfterInstall
{
    protected Container $container;

    public function run(Container $container, $params = [])
    {
        $this->container = $container;

        $isUpgrade = false;

        if (!empty($params['isUpgrade'])) {
            $isUpgrade = true;
        }

        /** @var EntityManager $entityManager */
        $entityManager = $this->container->get('entityManager');
        /** @var Metadata $metadata */
        $metadata = $this->container->get('metadata');
        /** @var Config $config */
        $config = $this->container->get('config');
        /** @var InjectableFactory $injectableFactory */
        $injectableFactory = $this->container->get('injectableFactory');

        $template = $entityManager->getEntityById(Template::ENTITY_TYPE, $this->prepareId('001'));

        if (!$isUpgrade && !$template) {
            $this->addTemplates($entityManager, $metadata);
        }

        $configWriter = $injectableFactory->create(Config\ConfigWriter::class);

        if (!$isUpgrade) {
            $this->addTabs($config, $configWriter);
        }

        $configWriter->set('adminPanelIframeUrl', $this->getIframeUrl());

        $configWriter->save();
    }

    private function getIframeUrl(): string
    {
        /** @var Config $config */
        $config = $this->container->get('config');

        $iframeUrl = $config->get('adminPanelIframeUrl');

        if (empty($iframeUrl) || trim($iframeUrl) == '/') {
            $iframeUrl = 'https://s.espocrm.com/';
        }

        $iframeUrl = $this->urlFixParam($iframeUrl);

        return $this->urlAddParam($iframeUrl, 'sales-pack', 'bcd3361258b6d66fc350488ed9575786');
    }

    private function urlFixParam(string $url): string
    {
        if (preg_match('/\/&(.+?)=(.+?)\//i', $url, $match)) {
            $fixedUrl = str_replace($match[0], '/', $url);

            if (!empty($match[1])) {
                $url = self::urlAddParam($fixedUrl, $match[1], $match[2]);
            }
        }

        $url = preg_replace('/^(\/\?)+/', 'https://s.espocrm.com/?', $url);
        $url = preg_replace('/\/\?&/', '/?', $url);

        return preg_replace('/\/&/', '/?', $url);
    }

    private static function urlAddParam(string $url, string $paramName, $paramValue): string
    {
        $urlQuery = parse_url($url, PHP_URL_QUERY);

        if (!$urlQuery) {
            $params = [
                $paramName => $paramValue
            ];

            $url = trim($url);
            /** @var string $url */
            $url = preg_replace('/\/\?$/', '', $url);
            /** @var string $url */
            $url = preg_replace('/\/$/', '', $url);

            return $url . '/?' . http_build_query($params);
        }

        parse_str($urlQuery, $params);

        if (!isset($params[$paramName]) || $params[$paramName] != $paramValue) {
            $params[$paramName] = $paramValue;

            return str_replace($urlQuery, http_build_query($params), $url);
        }

        return $url;
    }

    private function prepareId(string $id): string
    {
        /** @var Metadata $metadata */
        $metadata = $this->container->get('metadata');

        $toHash =
            $metadata->get(['app', 'recordId', 'type']) === 'uuid4' ||
            $metadata->get(['app', 'recordId', 'dbType']) === 'uuid';

        if ($toHash) {
            return md5($id);
        }

        return $id;
    }

    private function addTemplates(EntityManager $entityManager, Metadata $metadata): void
    {
        $template = $entityManager->getNewEntity(Template::ENTITY_TYPE);

        $template->set([
            'id' => $this->prepareId('001'),
            'entityType' => 'Quote',
            'name' => 'Quote (example)',
            'header' => $metadata->get(['entityDefs', 'Template', 'defaultTemplates', 'Quote', 'header']),
            'body' => $metadata->get(['entityDefs', 'Template', 'defaultTemplates', 'Quote', 'body']),
            'footer' => $metadata->get(['entityDefs', 'Template', 'defaultTemplates', 'Quote', 'footer']),
        ]);

        try {
            $entityManager->saveEntity($template, ['createdById' => 'system']);
        }
        catch (Exception) {}

        $template = $entityManager->getNewEntity(Template::ENTITY_TYPE);

        $template->set([
            'id' => $this->prepareId('011'),
            'entityType' => 'SalesOrder',
            'name' => 'Sales Order (example)',
            'header' => $metadata->get(['entityDefs', 'Template', 'defaultTemplates', 'SalesOrder', 'header']),
            'body' => $metadata->get(['entityDefs', 'Template', 'defaultTemplates', 'SalesOrder', 'body']),
            'footer' => $metadata->get(['entityDefs', 'Template', 'defaultTemplates', 'SalesOrder', 'footer']),
        ]);

        try {
            $entityManager->saveEntity($template, ['createdById' => 'system']);
        }
        catch (Exception) {}

        $template = $entityManager->getNewEntity(Template::ENTITY_TYPE);

        $template->set([
            'id' => $this->prepareId('021'),
            'entityType' => 'Invoice',
            'name' => 'Invoice (example)',
            'header' => $metadata->get(['entityDefs', 'Template', 'defaultTemplates', 'Invoice', 'header']),
            'body' => $metadata->get(['entityDefs', 'Template', 'defaultTemplates', 'Invoice', 'body']),
            'footer' => $metadata->get(['entityDefs', 'Template', 'defaultTemplates', 'Invoice', 'footer']),
        ]);

        try {
            $entityManager->saveEntity($template, ['createdById' => 'system']);
        }
        catch (Exception) {}

        $template = $entityManager->getNewEntity(Template::ENTITY_TYPE);

        $template->set([
            'id' => $this->prepareId('031'),
            'entityType' => 'PurchaseOrder',
            'name' => 'Purchase Order (example)',
            'header' => $metadata->get(['entityDefs', 'Template', 'defaultTemplates', 'PurchaseOrder', 'header']),
            'body' => $metadata->get(['entityDefs', 'Template', 'defaultTemplates', 'PurchaseOrder', 'body']),
        ]);

        try {
            $entityManager->saveEntity($template, ['createdById' => 'system']);
        }
        catch (Exception) {}

        $template = $entityManager->getNewEntity(Template::ENTITY_TYPE);

        $template->set([
            'id' => $this->prepareId('041'),
            'entityType' => 'DeliveryOrder',
            'name' => 'Delivery Order (example)',
            'header' => $metadata->get(['entityDefs', 'Template', 'defaultTemplates', 'DeliveryOrder', 'header']),
            'body' => $metadata->get(['entityDefs', 'Template', 'defaultTemplates', 'DeliveryOrder', 'body']),
        ]);

        try {
            $entityManager->saveEntity($template, ['createdById' => 'system']);
        }
        catch (Exception) {}

        $template = $entityManager->getNewEntity(Template::ENTITY_TYPE);

        $template->set([
            'id' => $this->prepareId('051'),
            'entityType' => 'ReceiptOrder',
            'name' => 'Receipt Order (example)',
            'header' => $metadata->get(['entityDefs', 'Template', 'defaultTemplates', 'ReceiptOrder', 'header']),
            'body' => $metadata->get(['entityDefs', 'Template', 'defaultTemplates', 'ReceiptOrder', 'body']),
        ]);

        try {
            $entityManager->saveEntity($template, ['createdById' => 'system']);
        }
        catch (Exception) {}

        try {
            $entityManager->saveEntity($template, ['createdById' => 'system']);
        }
        catch (Exception) {}

        $template = $entityManager->getNewEntity(Template::ENTITY_TYPE);

        $template->set([
            'id' => $this->prepareId('061'),
            'entityType' => 'ReturnOrder',
            'name' => 'Return Order (example)',
            'header' => $metadata->get(['entityDefs', 'Template', 'defaultTemplates', 'ReturnOrder', 'header']),
            'body' => $metadata->get(['entityDefs', 'Template', 'defaultTemplates', 'ReturnOrder', 'body']),
        ]);

        try {
            $entityManager->saveEntity($template, ['createdById' => 'system']);
        }
        catch (Exception) {}

        try {
            $entityManager->saveEntity($template, ['createdById' => 'system']);
        }
        catch (Exception) {}

        $template = $entityManager->getNewEntity(Template::ENTITY_TYPE);

        $template->set([
            'id' => $this->prepareId('071'),
            'entityType' => 'TransferOrder',
            'name' => 'Transfer Order (example)',
            'header' => $metadata->get(['entityDefs', 'Template', 'defaultTemplates', 'TransferOrder', 'header']),
            'body' => $metadata->get(['entityDefs', 'Template', 'defaultTemplates', 'TransferOrder', 'body']),
        ]);

        try {
            $entityManager->saveEntity($template, ['createdById' => 'system']);
        }
        catch (Exception) {}
    }

    private function addTabs(Config $config, Config\ConfigWriter $configWriter): void
    {
        $version = $config->get('version');
        $hasDividers = version_compare($version, '8.0.0') >= 0;

        /** @var (string|stdClass)[] $tabList */
        $tabList = $config->get('tabList') ?? [];

        $itemList = [
            'Product',
            false,
            'Quote',
            'SalesOrder',
            'Invoice',
            'DeliveryOrder',
            'ReturnOrder',
            false,
            'PurchaseOrder',
            'ReceiptOrder',
            false,
            'TransferOrder',
            'InventoryAdjustment',
            false,
            'Warehouse',
            'InventoryNumber',
            false,
            'InventoryTransaction',
        ];

        $entityTypeList = array_filter($itemList, fn ($item) => $item !== false);
        $entityTypeList = array_values($entityTypeList);

        foreach ($entityTypeList as $entityType) {
            if ($this->isInTabList($entityType, $tabList)) {
                return;
            }
        }

        if ($hasDividers) {
            $tabList[] = (object) [
                'type' => 'divider',
                'text' => null,
                'id' => $this->generateTabId(),
            ];
        }

        $groupItemList = [];

        foreach ($itemList as $item) {
            if (is_string($item)) {
                $groupItemList[] = $item;

                continue;
            }

            if (!$hasDividers) {
                continue;
            }

            $groupItemList[] = (object) [
                'type' => 'divider',
                'text' => null,
                'id' => $this->generateTabId(),
            ];
        }

        $tabList[] = (object) [
            'type' => 'group',
            'text' => '$SalesPack',
            'iconClass' => 'fas fa-boxes',
            'color' => null,
            'id' => $this->generateTabId(),
            'itemList' => $groupItemList,
        ];

        $configWriter->set('tabList', $tabList);
    }

    /**
     * @param (string|stdClass)[] $tabList
     */
    private function isInTabList(string $entityType, array $tabList): bool
    {
        if (in_array($entityType, $tabList)) {
            return true;
        }

        foreach ($tabList as $tab) {
            if (is_string($tab)) {
                continue;
            }

            /** @var (string|stdClass)[] $itemList */
            $itemList = $tab->itemList ?? [];

            if (in_array($entityType, $itemList)) {
                return true;
            }
        }

        return false;
    }

    private function generateTabId(): string
    {
        return (string) rand(100000, 999999);
    }
}
