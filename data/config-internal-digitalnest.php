<?php
return [
  'database' => [
    'host' => 'localhost',
    'port' => '',
    'charset' => NULL,
    'dbname' => 'top_crm',
    'user' => 'root',
    'password' => 'password123',
    'platform' => 'Mysql'
  ],
  'smtpPassword' => '',
  'logger' => [
    'path' => 'data/logs/espo.log',
    'level' => 'WARNING',
    'rotation' => true,
    'maxFileNumber' => 30,
    'printTrace' => false
  ],
  'restrictedMode' => false,
  'cleanupAppLog' => true,
  'cleanupAppLogPeriod' => '30 days',
  'webSocketMessager' => 'ZeroMQ',
  'clientSecurityHeadersDisabled' => false,
  'clientCspDisabled' => false,
  'clientCspScriptSourceList' => [
    0 => 'https://maps.googleapis.com'
  ],
  'adminUpgradeDisabled' => false,
  'isInstalled' => true,
  'microtimeInternal' => 1719337128.980522,
  'passwordSalt' => '60c6828939908a5b',
  'cryptKey' => '4ca63a79070ab9af0884d9b330396fe9',
  'hashSecretKey' => '080e2d7c9d03036e0767f75f306890b5',
  'defaultPermissions' => [
    'user' => 501,
    'group' => 20
  ],
  'actualDatabaseType' => 'mysql',
  'actualDatabaseVersion' => '8.0.29',
  'instanceId' => '91590bcf-1b73-4705-a44e-e225b5588e31'
];
