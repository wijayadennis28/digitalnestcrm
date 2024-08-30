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

use Throwable;

class TaskScript extends Activity
{
    public function process(): void
    {
        $formula = $this->getAttributeValue('formula');

        if (!$formula) {
            $this->processNextElement();

            return;
        }

        if (!is_string($formula)) {
            $GLOBALS['log']->error('Process ' . $this->getProcess()->get('id') . ', formula should be string.');

            $this->setFailed();

            return;
        }

        try {
            $variables = $this->getVariablesForFormula();

            $this->getFormulaManager()->run($formula, $this->getTarget(), $variables);

            $this->getEntityManager()
                ->saveEntity($this->getTarget(), [
                    'skipWorkflow' => true,
                    'skipModifiedBy' => true,
                ]);

            $this->sanitizeVariables($variables);

            $this->getProcess()->set('variables', $variables);
            $this->getEntityManager()->saveEntity($this->getProcess(), ['silent' => true]);
        }
        catch (Throwable $e) {
            $GLOBALS['log']->error('Process ' . $this->getProcess()->get('id') . ' formula error: ' . $e->getMessage());

            $this->setFailedWithException($e);

            return;
        }

        $this->processNextElement();
    }
}
