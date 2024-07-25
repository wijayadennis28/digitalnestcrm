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

namespace Espo\Modules\Sales\Tools\Quote;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Htmlizer\TemplateRendererFactory;
use Espo\Core\Utils\TemplateFileManager;
use Espo\Core\Utils\Util;
use Espo\Entities\Attachment;
use Espo\Entities\Template;
use Espo\Modules\Crm\Entities\Account;
use Espo\Modules\Crm\Entities\Contact;
use Espo\Modules\Crm\Entities\Opportunity as OpportunityEntity;
use Espo\Modules\Sales\Tools\Quote\Email\GetAttributesParams;
use Espo\ORM\EntityManager;
use Espo\Tools\Pdf\Service as PdfService;

class EmailService
{
    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private TemplateFileManager $templateFileManager,
        private TemplateRendererFactory $templateRendererFactory,
        private PdfService $pdfService
    ) {}

    /**
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     * @return array<string, mixed>
     */
    public function getAttributes(
        string $sourceType,
        string $sourceId,
        string $templateId,
        ?GetAttributesParams $params = null
    ): array {

        $params = $params ?? new GetAttributesParams();

        $quote = $this->entityManager->getEntityById($sourceType, $sourceId);

        /** @var ?Template $template */
        $template = $this->entityManager->getEntityById(Template::ENTITY_TYPE, $templateId);

        if (!$quote || !$template) {
            throw new NotFound();
        }

        if (
            !$this->acl->checkEntityRead($quote) ||
            !$this->acl->checkEntityRead($template)
        ) {
            throw new Forbidden();
        }

        $data = [];

        $data['templateName'] = $template->get('name');

        $subjectTpl = $this->templateFileManager
            ->getTemplate('salesEmailPdf', 'subject', $quote->getEntityType(), 'Sales');

        $bodyTpl = $this->templateFileManager
            ->getTemplate('salesEmailPdf', 'body', $quote->getEntityType(), 'Sales');

        $renderer = $this->templateRendererFactory->create();

        $renderer
            ->setApplyAcl()
            ->setEntity($quote)
            ->setData($data);

        $subject = $renderer->renderTemplate($subjectTpl);
        $body = $renderer->renderTemplate($bodyTpl);

        $attributes = [];

        $attributes['name'] = $subject;
        $attributes['body'] = $body;
        $attributes['nameHash'] = (object) [];

        $toList = [];

        $billingContactId = $quote->get('billingContactId');
        $opportunityId = $quote->get('opportunityId');
        $accountId = $quote->get('accountId');

        if ($billingContactId) {
            /** @var ?Contact $contact */
            $contact = $this->entityManager->getEntityById(Contact::ENTITY_TYPE, $billingContactId);

            if ($contact && $contact->get('emailAddress')) {
                $emailAddress = $contact->get('emailAddress');

                $toList[] = $emailAddress;
                $attributes['nameHash']->$emailAddress = $contact->getName();
            }
        }

        if ($opportunityId && !$params->skipOtherRecipients()) {
            $attributes['parentId'] = $opportunityId;
            $attributes['parentType'] = OpportunityEntity::ENTITY_TYPE;
            $attributes['parentName'] = $quote->get('opportunityName');

            if ($toList === []) {
                $opportunity = $this->entityManager->getEntityById(OpportunityEntity::ENTITY_TYPE, $opportunityId);

                if ($opportunity) {
                    $contacts = $this->entityManager
                        ->getRDBRepository(OpportunityEntity::ENTITY_TYPE)
                        ->getRelation($opportunity, 'contacts')
                        ->find();

                    foreach ($contacts as $contact) {
                        $emailAddress = $contact->get('emailAddress');

                        if (!$emailAddress) {
                            continue;
                        }

                        $toList[] = $emailAddress;
                        $attributes['nameHash']->$emailAddress = $contact->get('name');
                    }
                }
            }
        }

        if ($accountId) {
            if (empty($attributes['parentId'])) {
                $attributes['parentId'] = $accountId;
                $attributes['parentType'] = Account::ENTITY_TYPE;
                $attributes['parentName'] = $quote->get('accountName');
            }

            if ($toList === []) {
                /** @var ?Account $account */
                $account = $this->entityManager->getEntityById(Account::ENTITY_TYPE, $accountId);

                if ($account && $account->get('emailAddress')) {
                    $emailAddress = $account->get('emailAddress');

                    $toList[] = $emailAddress;
                    $attributes['nameHash']->$emailAddress = $account->getName();
                }
            }
        }

        $attributes['to'] = implode(';', $toList);

        $contents = $this->pdfService
            ->generate(
                $quote->getEntityType(),
                $quote->getId(),
                $template->getId(),
            )
            ->getString();

        $attachment = $this->entityManager->getNewEntity(Attachment::ENTITY_TYPE);

        $attachment->set([
            'name' => Util::sanitizeFileName($template->get('name') . ' ' . $quote->get('name')) . '.pdf',
            'type' => 'application/pdf',
            'role' => 'Attachment',
            'contents' => $contents,
            'relatedId' => $quote->getId(),
            'relatedType' => $quote->getEntityType(),
        ]);

        $this->entityManager->saveEntity($attachment);

        $attributes['attachmentsIds'] = [$attachment->getId()];
        $attributes['attachmentsNames'] = (object) [$attachment->getId() => $attachment->get('name')];
        $attributes['relatedId'] = $sourceId;
        $attributes['relatedType'] = $sourceType;

        return $attributes;
    }
}
