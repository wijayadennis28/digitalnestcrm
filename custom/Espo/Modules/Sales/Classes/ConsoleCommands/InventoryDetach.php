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

namespace Espo\Modules\Sales\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Core\Field\Date;
use Espo\Modules\Sales\Tools\Inventory\Detach\Detach;
use Espo\Modules\Sales\Tools\Inventory\Detach\Params as DetachParams;

/**
 * @noinspection PhpUnused
 */
class InventoryDetach implements Command
{
    public function __construct(
        private Detach $detach
    ) {}

    public function run(Params $params, IO $io): void
    {
        $before = $params->getOption('before') ?
            Date::fromString($params->getOption('before')) :
            null;

        if ($before) {
            $io->writeLine('Before: ' . $before->getString());
        }

        $cleanupParams = new DetachParams($before);

        $io->write('Running...');

        $result = $this->detach->run($cleanupParams);

        $io->writeLine('');
        $io->writeLine("Done. {$result->getCount()} transactions detached from their order record.");
    }
}