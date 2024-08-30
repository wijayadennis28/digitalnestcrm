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

namespace Espo\Modules\Advanced\Tools\Workflow;

use Espo\Core\ORM\Entity as CoreEntity;
use Laminas\Mail\Message;

use Espo\Core\Mail\Account\GroupAccount\AccountFactory as GroupAccountFactory;
use Espo\Core\Mail\Account\PersonalAccount\AccountFactory as PersonalAccountFactory;
use Espo\Core\InjectableFactory;
use Espo\Core\Exceptions\Error;
use Espo\Core\Mail\EmailSender;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\ServiceFactory;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Crypt;
use Espo\Core\Utils\Hasher;
use Espo\Core\Utils\Language;
use Espo\Entities\Attachment;
use Espo\Entities\Email;
use Espo\Entities\EmailAccount;
use Espo\Entities\EmailTemplate;
use Espo\Entities\InboundEmail;
use Espo\Entities\User;
use Espo\Modules\Advanced\Core\Workflow\Helper;
use Espo\Modules\Advanced\Entities\BpmnProcess as BpmnProcessEntity;
use Espo\Modules\Advanced\Entities\Workflow as WorkflowEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Services\EmailAccount as EmailAccountService;
use Espo\Services\InboundEmail as InboundEmailService;
use Espo\Tools\EmailTemplate\Processor as EmailTemplateProcessor;
use Espo\Tools\EmailTemplate\Data as EmailTemplateData;
use Espo\Tools\EmailTemplate\Params as EmailTemplateParams;

use RuntimeException;
use Exception;
use stdClass;

class SendEmailService
{
    public function __construct(
        private EntityManager $entityManager,
        private ServiceContainer $recordServiceContainer,
        private Config $config,
        private ServiceFactory $serviceFactory,
        private Helper $workflowHelper,
        private EmailSender $emailSender,
        private Hasher $hasher,
        private Language $defaultLanguage,
        private Crypt $crypt,
        private EmailTemplateProcessor $emailTemplateProcessor,
        private InjectableFactory $injectableFactory
    ) {}

    /**
     * Send email for a workflow.
     * @todo Introduce SendEmailData class.
     *
     * @return bool|string
     */
    public function send(stdClass $data)
    {
        $workflowId = $data->workflowId;

        if (!$this->validateSendEmailData($data)) {
            throw new Error("Workflow[$workflowId][sendEmail]: Email data is invalid.");
        }

        if ($workflowId) {
            /** @var ?WorkflowEntity $workflow */
            $workflow = $this->entityManager->getEntityById(WorkflowEntity::ENTITY_TYPE, $workflowId);

            if (!$workflow) {
                return false;
            }

            if (!$workflow->isActive()) {
                return false;
            }
        }

        $entity = null;

        if (!empty($data->entityType) && !empty($data->entityId)) {
            $entity = $this->entityManager->getEntityById($data->entityType, $data->entityId);
        }

        if (!$entity) {
            throw new Error("Workflow[$workflowId][sendEmail]: Target Entity is not found.");
        }

        $entityService = $this->recordServiceContainer->get($entity->getEntityType());

        $entityService->loadAdditionalFields($entity);

        $toEmailAddress = $this->getEmailAddress($data->to);
        $fromEmailAddress = $this->getEmailAddress($data->from);

        $replyToEmail = null;

        if (!empty($data->replyTo)) {
            $replyToEmail = $this->getEmailAddress($data->replyTo);
        }

        if (empty($toEmailAddress)) {
            throw new Error("Workflow[$workflowId][sendEmail]: To email address is empty.");
        }

        if (empty($fromEmailAddress)) {
            throw new Error("Workflow[$workflowId][sendEmail]: From email address is empty or could not be obtained.");
        }

        $entityHash = [$data->entityType => $entity];

        if (
            isset($data->to->entityType) &&
            isset($data->to->entityId) &&
            $data->to->entityType !== $data->entityType
        ) {
            $toEntityType = $data->to->entityType;

            $entityHash[$toEntityType] = $this->entityManager->getEntityById($toEntityType, $data->to->entityId);
        }

        if (
            isset($data->from->entityType) &&
            $data->from->entityType === User::ENTITY_TYPE
        ) {
            $entityHash[User::ENTITY_TYPE] =
                $this->entityManager->getEntityById(User::ENTITY_TYPE, $data->from->entityId);

            $fromName = $entityHash[User::ENTITY_TYPE]->get('name');
        }

        $emailTemplateId = $data->emailTemplateId;

        /** @var ?EmailTemplate $emailTemplate */
        $emailTemplate = $this->entityManager->getEntityById(EmailTemplate::ENTITY_TYPE, $emailTemplateId);

        if (!$emailTemplate) {
            throw new RuntimeException("Email template $emailTemplateId not found.");
        }

        $emailTemplateData = EmailTemplateData::create()
            ->withEntityHash($entityHash)
            ->withEmailAddress($toEmailAddress)
            ->withParentId($entity->getId())
            ->withParentType($entity->getEntityType());

        if (
            $entity->hasAttribute('parentId') &&
            $entity->hasAttribute('parentType')
        ) {
            $emailTemplateData = $emailTemplateData
                ->withRelatedId($entity->get('parentId'))
                ->withRelatedType($entity->get('parentType'));
        }

        $templateResult = $this->emailTemplateProcessor->process(
            $emailTemplate,
            EmailTemplateParams::create()->withCopyAttachments(),
            $emailTemplateData
        );

        $subject = $templateResult->getSubject();
        $body = $templateResult->getBody();

        if (isset($data->variables)) {
            foreach (get_object_vars($data->variables) as $key => $value) {
                if (is_string($value) || is_int($value) || is_float($value)) {
                    if (is_int($value) || is_float($value)) {
                        $value = strval($value);
                    }
                    else {
                        if (!$value) {
                            continue;
                        }
                    }

                    $subject = str_replace('{$$' . $key . '}', $value, $subject);
                    $body = str_replace('{$$' . $key . '}', $value, $body);
                }
            }
        }

        $siteUrl = $this->config->get('siteUrl');

        $body = $this->applyTrackingUrlsToEmailBody($body, $toEmailAddress);

        $hasOptOutLink = $data->optOutLink ?? false;

        $message = new Message();

        if ($hasOptOutLink) {
            $hash = $this->hasher->hash($toEmailAddress);

            $optOutUrl = $siteUrl .
                '?entryPoint=unsubscribe&emailAddress=' . $toEmailAddress . '&hash=' . $hash;

            $optOutLink = '<a href="'.$optOutUrl.'">' .
                $this->defaultLanguage->translate('Unsubscribe', 'labels', 'Campaign').'</a>';

            $body = str_replace('{optOutUrl}', $optOutUrl, $body);
            $body = str_replace('{optOutLink}', $optOutLink, $body);

            if (stripos($body, '?entryPoint=unsubscribe') === false) {
                if ($templateResult->isHtml()) {
                    $body .= "<br><br>" . $optOutLink;
                } else {
                    $body .= "\n\n" . $optOutUrl;
                }
            }

            $message->getHeaders()->addHeaderLine('List-Unsubscribe', '<' . $optOutUrl . '>');
        }

        $emailData = [
            'from' => $fromEmailAddress,
            'to' => $toEmailAddress,
            'subject' => $subject,
            'body' => $body,
            'isHtml' => $templateResult->isHtml(),
            'parentId' => $entity->getId(),
            'parentType' => $entity->getEntityType(),
        ];

        if ($replyToEmail) {
            $emailData['replyTo'] = $replyToEmail;
        }

        if (isset($fromName)) {
            $emailData['fromName'] = $fromName;
        }

        /** @var Email $email */
        $email = $this->entityManager->getNewEntity(Email::ENTITY_TYPE);

        $email->set($emailData);

        $attachmentList = [];

        foreach ($templateResult->getAttachmentIdList() as $attachmentId) {
            /** @var ?Attachment $attachment */
            $attachment = $this->entityManager->getEntityById(Attachment::ENTITY_TYPE, $attachmentId);

            if (isset($attachment)) {
                $attachmentList[] = $attachment;
            }
        }

        if (!$data->doNotStore) {
            $email->set('attachmentsIds', $templateResult->getAttachmentIdList());
        }

        $sender = $this->emailSender->create();

        $smtpParams = null;

        if (
            isset($data->from->entityType) &&
            $data->from->entityType == User::ENTITY_TYPE &&
            isset($data->from->entityId)
        ) {
            $smtpParams = $this->getUserSmtpParams($fromEmailAddress, $data->from->entityId);
        } else {
            if (isset($data->from->email)) {
                $smtpParams = $this->getGroupSmtpParams($fromEmailAddress);
            }
        }

        if ($smtpParams) {
            $sender->withSmtpParams($smtpParams);
        }

        $sender->withAttachments($attachmentList);
        $sender->withMessage($message);

        $sendExceptionMessage = null;

        try {
            $sender->send($email);
        }
        catch (Exception $e) {
            $sendExceptionMessage = $e->getMessage();
        }

        if (isset($sendExceptionMessage)) {
            throw new Error("Workflow[$workflowId][sendEmail]: $sendExceptionMessage.");
        }

        if (!$data->doNotStore) {
            $processId = $data->processId ?? null;

            $teamsIds = [];

            if ($processId) {
                /** @var ?BpmnProcessEntity $process */
                $process = $this->entityManager->getEntityById(BpmnProcessEntity::ENTITY_TYPE, $processId);

                if ($process) {
                    $teamsIds = $process->getLinkMultipleIdList('teams');
                }
            } else {
                /** @var ?EmailTemplate $emailTemplate */
                $emailTemplate = $this->entityManager
                    ->getEntityById(EmailTemplate::ENTITY_TYPE, $data->emailTemplateId);

                if ($emailTemplate) {
                    $teamsIds = $emailTemplate->getLinkMultipleIdList('teams');
                }
            }

            if (count($teamsIds)) {
                $email->set('teamsIds', $teamsIds);
            }

            $this->entityManager->saveEntity($email, ['createdById' => 'system']);

            if (!empty($data->returnEmailId)) {
                return $email->getId();
            }
        }

        return true;
    }

    private function validateSendEmailData(stdClass $data): bool
    {
        if (
            !isset($data->entityId) ||
            !(isset($data->entityType)) ||
            !isset($data->emailTemplateId) ||
            !isset($data->from) ||
            !isset($data->to)
        ) {
            return false;
        }

        return true;
    }

    private function getEmailAddress(stdClass $data): ?string
    {
        if (isset($data->email)) {
            return $data->email;
        }

        $entityType = $data->entityType ?? $data->entityName ?? null;

        $entity = null;

        if (isset($entityType) && isset($data->entityId)) {
            $entity = $this->entityManager->getEntityById($entityType, $data->entityId);
        }

        $workflowHelper = $this->workflowHelper;

        if (isset($data->type)) {
            switch ($data->type) {
                case 'specifiedTeams':
                    $userIds = $workflowHelper->getUserIdsByTeamIds($data->entityIds);

                    return implode('; ', $workflowHelper->getUsersEmailAddress($userIds));

                case 'teamUsers':
                    if (!$entity instanceof CoreEntity) {
                        return null;
                    }

                    $entity->loadLinkMultipleField('teams');
                    $userIds = $workflowHelper->getUserIdsByTeamIds($entity->get('teamsIds'));

                    return implode('; ', $workflowHelper->getUsersEmailAddress($userIds));

                case 'followers':
                    if (!$entity) {
                        return null;
                    }

                    $userIds = $workflowHelper->getFollowerUserIds($entity);

                    return implode('; ', $workflowHelper->getUsersEmailAddress($userIds));

                case 'followersExcludingAssignedUser':
                    if (!$entity) {
                        return null;
                    }

                    $userIds = $workflowHelper->getFollowerUserIdsExcludingAssignedUser($entity);

                    return implode('; ', $workflowHelper->getUsersEmailAddress($userIds));

                case 'system':
                    return $this->config->get('outboundEmailFromAddress');

                case 'specifiedUsers':
                    return implode('; ', $workflowHelper->getUsersEmailAddress($data->entityIds));

                case 'specifiedContacts':
                    return implode('; ', $workflowHelper->getEmailAddressesForEntity('Contact', $data->entityIds));
            }
        }

        if ($entity instanceof Entity && $entity->hasAttribute('emailAddress')) {
            return $entity->get('emailAddress');
        }

        if (
            isset($data->type) &&
            isset($entityType) &&
            isset($data->entityIds) &&
            is_array($data->entityIds)
        ) {
            return implode('; ', $workflowHelper->getEmailAddressesForEntity($entityType, $data->entityIds));
        }

        return null;
    }

    private function applyTrackingUrlsToEmailBody(string $body, string $toEmailAddress): string
    {
        $siteUrl = $this->config->get('siteUrl');

        if (!str_contains($body, '{trackingUrl:')) {
            return $body;
        }

        $hash = $this->hasher->hash($toEmailAddress);

        preg_match_all('/\{trackingUrl:(.*?)}/', $body, $matches);

        if (!$matches || !count($matches)) {
            return $body;
        }

        foreach ($matches[0] as $item) {
            $id = explode(':', trim($item, '{}'), 2)[1] ?? null;

            if (!$id) {
                continue;
            }

            if (strpos($id, '.')) {
                [$id, $uid] = explode('.', $id);

                $uidHash = $this->hasher->hash($uid);

                $url = "$siteUrl?entryPoint=campaignUrl&id=$id&uid=$uid&hash=$uidHash";
            } else {
                $url = "$siteUrl?entryPoint=campaignUrl&id=$id&emailAddress=$toEmailAddress&hash=$hash";
            }

            $body = str_replace($item, $url, $body);
        }

        return $body;
    }

    private function getUserSmtpParams(string $emailAddress, string $userId): ?array
    {
        $rawSmtpParams = null;

        $user = $this->entityManager->getEntityById(User::ENTITY_TYPE, $userId);

        if (!$user || !$user->get('isActive')) {
            return null;
        }

        $emailAccount = $this->entityManager
            ->getRDBRepository(EmailAccount::ENTITY_TYPE)
            ->where([
                'emailAddress' => $emailAddress,
                'assignedUserId' => $userId,
                'useSmtp' => true,
                'status' => EmailAccount::STATUS_ACTIVE,
            ])
            ->findOne();

        if ($emailAccount) {
            if (class_exists("Espo\\Core\\Mail\\Account\\PersonalAccount\\AccountFactory")) {
                $factory = $this->injectableFactory->create(PersonalAccountFactory::class);

                $params = $factory->create($emailAccount->getId())
                    ->getSmtpParams();

                if ($params) {
                    $rawSmtpParams = $params->toArray();
                }
            } else {
                /** @var EmailAccountService $service */
                $service = $this->serviceFactory->create(EmailAccount::ENTITY_TYPE);

                $rawSmtpParams = $service->getSmtpParamsFromAccount($emailAccount);
            }
        }

        if ($rawSmtpParams) {
            $rawSmtpParams['fromName'] = $user->get('name');
        }

        return $rawSmtpParams ?? null;
    }

    private function getGroupSmtpParams(string $emailAddress): ?array
    {
        $inboundEmail = $this->entityManager
            ->getRDBRepository(InboundEmail::ENTITY_TYPE)
            ->where([
                'status' => InboundEmail::STATUS_ACTIVE,
                'useSmtp' => true,
                'smtpHost!=' => null,
                'emailAddress' => $emailAddress,
            ])
            ->findOne();

        if (!$inboundEmail) {
            return null;
        }

        $rawSmtpParams = null;

        if (class_exists("Espo\\Core\\Mail\\Account\\GroupAccount\\AccountFactory")) {
            $params = $this->injectableFactory
                ->create(GroupAccountFactory::class)
                ->create($inboundEmail->getId())
                ->getSmtpParams();

            if ($params) {
                $rawSmtpParams = $params->toArray();
            }

            return $rawSmtpParams;
        }

        /** @var InboundEmailService $service */
        $service = $this->serviceFactory->create(InboundEmail::ENTITY_TYPE);

        return $service->getSmtpParamsFromAccount($inboundEmail);
    }
}
