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

namespace Espo\Modules\Sales\Classes\ServiceActions\Quote;

use Espo\Core\InjectableFactory;
use Espo\Core\Mail\EmailSender;
use Espo\Core\Mail\Exceptions\SendingError;
use Espo\Entities\Attachment;
use Espo\Entities\Email;
use Espo\Entities\Template;
use Espo\Modules\Advanced\Tools\Workflow\Action\RunAction\ServiceAction;
use Espo\Modules\Sales\Tools\Quote\Email\GetAttributesParams;
use Espo\Modules\Sales\Tools\Quote\EmailService;
use Espo\Modules\Sales\Tools\Sales\OrderEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Tools\EmailTemplate\Data as EmailTemplateData;
use Espo\Tools\EmailTemplate\Params as EmailTemplateParams;
use Espo\Tools\EmailTemplate\Service as EmailTemplateService;
use Laminas\Mail\Message;
use RuntimeException;
use stdClass;

/**
 * @implements ServiceAction<OrderEntity>
 */
class SendInEmail implements ServiceAction
{
    public function __construct(
        private EntityManager $entityManager,
        private InjectableFactory $injectableFactory,
        private EmailTemplateService $emailTemplateService,
        private EmailSender $emailSender
    ) {}

    /**
     * @inheritDoc
     */
    public function run(Entity $entity, mixed $data): mixed
    {
        if (!$data instanceof stdClass) {
            throw new RuntimeException('Bad data provided to sendInEmail.');
        }

        $templateId = $data->templateId ?? null;
        $emailTemplateId = $data->emailTemplateId ?? null;

        if (!$templateId) {
            throw new RuntimeException("QuoteWorkflow sendInEmail: No templateId");
        }

        $template = $this->entityManager->getEntityById(Template::ENTITY_TYPE, $templateId);

        if (!$template) {
            throw new RuntimeException("QuoteWorkflow sendInEmail: Template doesn't exist");
        }

        $attributes = $this->injectableFactory
            ->create(EmailService::class)
            ->getAttributes($entity->getEntityType(), $entity->getId(), $templateId, new GetAttributesParams(true));

        if ($emailTemplateId) {
            $emailTemplateData = EmailTemplateData::create()
                ->withEntityHash([$entity->getEntityType() => $entity]);

            $emailTemplateParams = EmailTemplateParams::create()
                ->withCopyAttachments();

            $emailTemplateResult = $this->emailTemplateService
                ->process($emailTemplateId, $emailTemplateData, $emailTemplateParams);

            $attributes['name'] = $emailTemplateResult->getSubject();
            $attributes['body'] = $emailTemplateResult->getBody();
            $attributes['isHtml'] = $emailTemplateResult->isHtml();

            foreach ($emailTemplateResult->getAttachmentIdList() as $attachmentId) {
                $attributes['attachmentsIds'][] = $attachmentId;
            }
        }

        $to = $data->to ?? null;

        if ($to && str_starts_with($to, 'link:')) {
            $linkPath = substr($to, 5);
            $arr = explode('.', $linkPath);
            $target = $entity;

            foreach ($arr as $link) {
                $linkType = $target->getRelationType($link);

                if (
                    $linkType !== Entity::BELONGS_TO &&
                    $linkType !== Entity::BELONGS_TO_PARENT &&
                    $linkType !== Entity::HAS_ONE
                ) {
                    throw new RuntimeException("QuoteWorkflow sendInEmail: Bad TO link");
                }

                $target = $target->get($link);

                if (!$target) {
                    throw new RuntimeException("QuoteWorkflow sendInEmail: Could not find TO recipient");
                }
            }

            $emailAddress = $target->get('emailAddress');

            if (!$emailAddress) {
                throw new RuntimeException("QuoteWorkflow sendInEmail: Recipient doesn't have email address");
            }

            $attributes['to'] = $emailAddress;
        }

        if (empty($attributes['to'])) {
            throw new RuntimeException("QuoteWorkflow sendInEmail: Not recipient found");
        }

        /** @var Email $email */
        $email = $this->entityManager->getNewEntity(Email::ENTITY_TYPE);

        $email->set($attributes);

        $attachmentList = [];

        foreach ($attributes['attachmentsIds'] as $attachmentId) {
            $attachment = $this->entityManager->getEntityById(Attachment::ENTITY_TYPE, $attachmentId);

            if ($attachment) {
                $attachmentList[] = $attachment;
            }
        }

        $message = new Message();

        try {
            $this->emailSender
                ->withMessage($message)
                ->withAttachments($attachmentList)
                ->send($email);
        }
        catch (SendingError $e) {
            throw new RuntimeException($e->getMessage());
        }

        $this->entityManager->saveEntity($email);

        return null;
    }
}
