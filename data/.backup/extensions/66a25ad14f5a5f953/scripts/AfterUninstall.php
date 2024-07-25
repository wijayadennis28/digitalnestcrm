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

class AfterUninstall
{
    protected Container $container;

    public function run(Container $container)
    {
        $this->container = $container;

        /** @var InjectableFactory $injectableFactory */
        $injectableFactory = $this->container->get('injectableFactory');

        $configWriter = $injectableFactory->create(Config\ConfigWriter::class);

        $configWriter->set('adminPanelIframeUrl', $this->getIframeUrl('sales-pack'));
        $configWriter->save();
    }

    protected function getIframeUrl(string $name): string
    {
        /** @var Config $config */
        $config = $this->container->get('config');

        $iframeUrl = $config->get('adminPanelIframeUrl');

        return self::urlRemoveParam($iframeUrl, $name, '/');
    }

    private static function urlRemoveParam(string $url, string $paramName, string $suffix = ''): string
    {
        $urlQuery = parse_url($url, \PHP_URL_QUERY);

        if ($urlQuery) {
            parse_str($urlQuery, $params);

            if (isset($params[$paramName])) {
                unset($params[$paramName]);

                $newUrl = str_replace($urlQuery, http_build_query($params), $url);

                if (empty($params)) {
                    /** @var string $newUrl */
                    $newUrl = preg_replace('/\/\?$/', '', $newUrl);
                    /** @var string $newUrl */
                    $newUrl = preg_replace('/\/$/', '', $newUrl);

                    $newUrl .= $suffix;
                }

                return $newUrl;
            }
        }

        return $url;
    }
}
