<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Bus\Events\RuleUpdated;
use Appwrite\Event\Event;
use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Message\Mail as MailMessage;
use Appwrite\Event\Publisher\Certificate;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Publisher\Mail as MailPublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Platform\Modules\Proxy\Action;
use Appwrite\Template\Template;
use Appwrite\Utopia\Response\Model\Rule;
use Exception;
use Throwable;
use Utopia\Bus\Bus;
use Utopia\Cdn\Certificates\Provider;
use Utopia\Cdn\Certificates\Status;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Exception\NotFound;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization as ValidatorAuthorization;
use Utopia\Domains\Domain;
use Utopia\Locale\Locale;
use Utopia\Queue\Message;
use Utopia\Span\Span;
use Utopia\System\System;

class Certificates extends Action
{
    public static function getName(): string
    {
        return 'certificates';
    }

    /**
     * @throws Exception
     */
    public function __construct(...$params)
    {
        parent::__construct(...$params);

        $this
            ->desc('Certificates worker')
            ->inject('message')
            ->inject('dbForPlatform')
            ->inject('publisherForMails')
            ->inject('queueForEvents')
            ->inject('queueForWebhooks')
            ->inject('publisherForFunctions')
            ->inject('queueForRealtime')
            ->inject('publisherForCertificates')
            ->inject('certificates')
            ->inject('plan')
            ->inject('authorization')
            ->inject('bus')
            ->callback($this->action(...));
    }

    /**
     * @param Message $message
     * @param Database $dbForPlatform
     * @param MailPublisher $publisherForMails
     * @param Event $queueForEvents
     * @param Webhook $queueForWebhooks
     * @param FunctionPublisher $publisherForFunctions
     * @param Realtime $queueForRealtime
     * @param Certificate $publisherForCertificates
     * @param Provider $certificates
     * @param array $plan
     * @param ValidatorAuthorization $authorization
     * @return void
     * @throws Throwable
     * @throws \Utopia\Database\Exception
     */
    public function action(
        Message $message,
        Database $dbForPlatform,
        MailPublisher $publisherForMails,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Realtime $queueForRealtime,
        Certificate $publisherForCertificates,
        Provider $certificates,
        array $plan,
        ValidatorAuthorization $authorization,
        Bus $bus,
    ): void {
        $payload = $message->getPayload();

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $certificateMessage = \Appwrite\Event\Message\Certificate::fromArray($payload);
        $document = $certificateMessage->domain;
        $domain   = new Domain($document->getAttribute('domain', ''));
        $skipRenewCheck = $certificateMessage->skipRenewCheck;
        $validationDomain = $certificateMessage->validationDomain;
        $action = $certificateMessage->action;

        Span::add('domain', $domain->get());

        switch ($action) {
            case \Appwrite\Event\Certificate::ACTION_DOMAIN_VERIFICATION:
                $this->handleDomainVerificationAction($domain, $dbForPlatform, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $queueForRealtime, $publisherForCertificates, $authorization, $bus, $validationDomain);
                break;

            case \Appwrite\Event\Certificate::ACTION_GENERATION:
                $this->handleCertificateGenerationAction($domain, $certificateMessage->project, $dbForPlatform, $publisherForMails, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $queueForRealtime, $certificates, $bus, $skipRenewCheck, $plan, $validationDomain);
                break;

            default:
                throw new Exception('Invalid action: ' . $action);
        }
    }

    /**
     * @param Domain $domain
     * @param Database $dbForPlatform
     * @param Event $queueForEvents
     * @param Webhook $queueForWebhooks
     * @param FunctionPublisher $publisherForFunctions
     * @param Realtime $queueForRealtime
     * @param Certificate $publisherForCertificates
     * @param ValidatorAuthorization $authorization
     * @param string|null $validationDomain
     * @return void
     * @throws \Utopia\Database\Exception
     * @throws NotFound
     * @throws \Utopia\Database\Exception\Query
     */
    private function handleDomainVerificationAction(
        Domain $domain,
        Database $dbForPlatform,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Realtime $queueForRealtime,
        Certificate $publisherForCertificates,
        ValidatorAuthorization $authorization,
        Bus $bus,
        ?string $validationDomain = null
    ): void {
        // Get rule
        $rule = System::getEnv('_APP_RULES_FORMAT') === 'md5'
            ? $dbForPlatform->getDocument('rules', md5($domain->get()))
            : $dbForPlatform->findOne('rules', [
                Query::equal('domain', [$domain->get()]),
                Query::limit(1),
            ]);

        // Skip if rule is not desired state (created but not verified yet).
        if ($rule->getAttribute('status', '') !== RULE_STATUS_CREATED) {
            Console::warning('Domain verification for ' . $rule->getAttribute('domain', '') . ' is not needed.');
            return;
        }

        Console::info('Domain verification for ' . $rule->getAttribute('domain', '') . ' started.');

        try {
            // Verify DNS records
            $this->validateDomain($rule, $domain, $validationDomain);
            // Reset logs and status for the rule
            $rule->setAttribute('logs', '');
            $rule->setAttribute('status', RULE_STATUS_CERTIFICATE_GENERATING);

            Console::success('Domain verification succeeded.');
        } catch (AppwriteException $err) {
            Console::warning('Domain verification failed: ' . $err->getMessage());
            $date = \date('H:i:s');
            $logs = "\033[90m[{$date}] \033[31mDNS verification failed: \033[0m\n";
            $logs .= \mb_strcut($err->getMessage(), 0, 500000); // Limit to 500kb
            $rule->setAttribute('logs', $logs);
        } finally {
            // Update rule and emit events
            $this->updateRuleAndSendEvents($rule, $dbForPlatform, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $queueForRealtime, $bus);
        }

        // Issue a TLS certificate when domain is verified
        if ($rule->getAttribute('status', '') === RULE_STATUS_CERTIFICATE_GENERATING) {
            $publisherForCertificates->enqueue(new \Appwrite\Event\Message\Certificate(
                project: new Document([
                    '$id' => $rule->getAttribute('projectId', ''),
                    '$sequence' => $rule->getAttribute('projectInternalId', 0),
                ]),
                domain: new Document([
                    'domain' => $rule->getAttribute('domain'),
                    'domainType' => $rule->getAttribute('deploymentResourceType', $rule->getAttribute('type')),
                ]),
                action: \Appwrite\Event\Certificate::ACTION_GENERATION,
            ));

            Console::success('Certificate generation triggered successfully.');
        }
    }

    /**
     * @param Domain $domain
     * @param Document $project
     * @param Database $dbForPlatform
     * @param MailPublisher $publisherForMails
     * @param Event $queueForEvents
     * @param Webhook $queueForWebhooks
     * @param FunctionPublisher $publisherForFunctions
     * @param Realtime $queueForRealtime
     * @param Provider $certificates
     * @param bool $skipRenewCheck
     * @param array $plan
     * @param string|null $validationDomain
     * @return void
     * @throws Authorization
     * @throws Conflict
     * @throws NotFound
     * @throws Structure
     * @throws Throwable
     * @throws \Utopia\Database\Exception
     * @throws \Utopia\Database\Exception\Query
     */
    private function handleCertificateGenerationAction(
        Domain $domain,
        Document $project,
        Database $dbForPlatform,
        MailPublisher $publisherForMails,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Realtime $queueForRealtime,
        Provider $certificates,
        Bus $bus,
        bool $skipRenewCheck = false,
        array $plan = [],
        ?string $validationDomain = null,
    ): void {
        // Resolve the current rule, then lock and re-check it before claiming
        // issuance. A queued message must not act on a recreated domain owner.
        $rule = System::getEnv('_APP_RULES_FORMAT') === 'md5'
            ? $dbForPlatform->getDocument('rules', md5($domain->get()))
            : $dbForPlatform->findOne('rules', [Query::equal('domain', [$domain->get()]), Query::limit(1)]);
        if ($rule->isEmpty()) {
            Console::warning('Certificate generation for ' . $domain->get() . ' is skipped as the associated rule is missing.');
            return;
        }

        // Compare lease tokens in the ISO format returned by database reads.
        $lease = DateTime::formatTz(DateTime::now());
        $date = \date('H:i:s');
        $logs = "\033[90m[{$date}] \033[97mProcessing SSL certificate issuance. \033[0m\n";
        $claimed = $dbForPlatform->withTransaction(function () use ($dbForPlatform, $rule, $project, $domain, $lease, $logs): ?array {
            $current = $dbForPlatform->getDocument('rules', $rule->getId(), forUpdate: true);
            if ($current->isEmpty()
                || $current->getSequence() !== $rule->getSequence()
                || $current->getAttribute('domain') !== $domain->get()
                || $current->getAttribute('projectId') !== $project->getId()
                || ($current->getAttribute('projectId') !== 'console' && (string) $current->getAttribute('projectInternalId') !== (string) $project->getSequence())
                || !\in_array($current->getAttribute('status'), [RULE_STATUS_CERTIFICATE_GENERATING, RULE_STATUS_VERIFIED, RULE_STATUS_CERTIFICATE_GENERATION_FAILED], true)) {
                return null;
            }

            $certificateId = $current->getAttribute('certificateId', '');
            $certificate = empty($certificateId) ? new Document() : $dbForPlatform->getDocument('certificates', $certificateId, forUpdate: true);
            $previous = $certificate->getAttribute('updated');
            if ((!empty($previous) && new \DateTime($previous) > new \DateTime('-' . APP_CERTIFICATE_GENERATION_LEASE . ' seconds'))
                || ($certificate->getAttribute('attempts', 0) >= APP_LIMIT_CERTIFICATE_ATTEMPTS
                    && $current->getAttribute('status') === RULE_STATUS_CERTIFICATE_GENERATION_FAILED)) {
                return null;
            }

            // `updated` holds the lease until completion or expiry after a crash.
            $updates = new Document(['updated' => $lease, 'logs' => $logs]);
            if ($certificate->isEmpty()) {
                $updates->setAttributes(['$id' => ID::unique(), 'domain' => $domain->get(), 'attempts' => 0]);
                $certificate = $dbForPlatform->createDocument('certificates', $updates);
            } else {
                $certificate = $dbForPlatform->updateDocument('certificates', $certificate->getId(), $updates);
            }
            $current = $dbForPlatform->updateDocument('rules', $current->getId(), new Document([
                'certificateId' => $certificate->getId(),
                'status' => $current->getAttribute('status') === RULE_STATUS_CERTIFICATE_GENERATION_FAILED
                    ? RULE_STATUS_CERTIFICATE_GENERATING : $current->getAttribute('status'),
                'logs' => $logs,
            ]));

            return [$current, $certificate];
        });
        if ($claimed === null) {
            return;
        }
        [$rule, $certificate] = $claimed;
        $claimedStatus = $rule->getAttribute('status');
        // The persisted rule is authoritative; stale queue payloads cannot
        // route issuance to a different provider after a domain changes type.
        $domainType = $rule->getAttribute('deploymentResourceType', $rule->getAttribute('type'));
        $error = null;
        $issuanceStarted = false;
        $exhausted = $certificate->getAttribute('attempts', 0) >= APP_LIMIT_CERTIFICATE_ATTEMPTS;

        try {
            // A crash on the final attempt may leave a usable certificate at
            // the provider. Reconcile it even when issuance cannot be retried.
            if (!$skipRenewCheck || $exhausted) {
                $this->validateDomain($rule, $domain, $validationDomain);
                if (!$certificates->isRenewRequired($domain->get(), $domainType)) {
                    $instant = $certificates->isInstantGeneration($domain->get(), $domainType);
                    $status = $instant
                        ? Status::ISSUED
                        : $certificates->getCertificateStatus($domain->get(), $domainType);
                    if ($status === Status::ISSUED) {
                        $rule->setAttribute('status', RULE_STATUS_VERIFIED);
                        $certificate->setAttribute('attempts', 0);
                        $renewDate = $certificate->getAttribute('renewDate');
                        if ($instant && (empty($renewDate) || new \DateTime($renewDate) <= new \DateTime())) {
                            // A crash may lose the renewal date. Schedule another
                            // eligibility check so maintenance keeps renewing it.
                            $certificate->setAttribute('renewDate', DateTime::addSeconds(new \DateTime(), max(1, (int) System::getEnv('_APP_MAINTENANCE_INTERVAL', '86400'))));
                        }
                        $logs .= "\033[90m[{$date}] \033[97mSSL certificate successfully issued. \033[0m\n";
                        return;
                    }
                    if (\in_array($status, [Status::PENDING, Status::PROCESSING, Status::RENEWING], true)) {
                        $rule->setAttribute('status', RULE_STATUS_CERTIFICATE_GENERATING);
                        $logs .= "\033[90m[{$date}] \033[97mSSL certificate is being issued. We'll periodically check and update the status. \033[0m\n";
                        return;
                    }
                    // UNKNOWN is not evidence of a usable certificate. Let the
                    // issuance path repair a missing provider subscription.
                }
            }

            if ($exhausted) {
                $rule->setAttribute('status', RULE_STATUS_CERTIFICATE_GENERATION_FAILED);
                $logs .= "\033[90m[{$date}] \033[31mSSL certificate retry limit reached. Retry manually after resolving the failure. \033[0m\n";
                return;
            }

            // Reserve the attempt before issuance so a crash still counts it.
            $started = $dbForPlatform->withTransaction(function () use ($dbForPlatform, $rule, $certificate, $lease, $claimedStatus): ?Document {
                $current = $dbForPlatform->getDocument('rules', $rule->getId(), forUpdate: true);
                if ($current->isEmpty()
                    || $current->getSequence() !== $rule->getSequence()
                    || $current->getAttribute('certificateId') !== $certificate->getId()
                    || $current->getAttribute('projectId') !== $rule->getAttribute('projectId')
                    || $current->getAttribute('domain') !== $rule->getAttribute('domain')
                    || $current->getAttribute('status') !== $claimedStatus) {
                    return null;
                }
                $latest = $dbForPlatform->getDocument('certificates', $certificate->getId(), forUpdate: true);
                if ($latest->isEmpty()
                    || $latest->getSequence() !== $certificate->getSequence()
                    || $latest->getAttribute('updated') !== $lease
                    || $latest->getAttribute('attempts', 0) >= APP_LIMIT_CERTIFICATE_ATTEMPTS) {
                    return null;
                }
                return $dbForPlatform->updateDocument('certificates', $certificate->getId(), new Document([
                    'attempts' => $latest->getAttribute('attempts', 0) + 1,
                    'issueDate' => DateTime::now(),
                ]));
            });
            if ($started === null) {
                return;
            }
            $certificate = $started;
            $issuanceStarted = true;
            $renewDate = $certificates->issueCertificate(ID::unique(), $domain->get(), $domainType);
            $certificate->setAttribute('renewDate', $renewDate);
            $date = \date('H:i:s');
            if ($certificates->isInstantGeneration($domain->get(), $domainType)) {
                $rule->setAttribute('status', RULE_STATUS_VERIFIED);
                $certificate->setAttribute('attempts', 0);
                $logs .= "\033[90m[{$date}] \033[97mSSL certificate successfully issued. \033[0m\n";
            } else {
                $rule->setAttribute('status', RULE_STATUS_CERTIFICATE_GENERATING);
                $logs .= "\033[90m[{$date}] \033[97mSSL certificate is being issued. This usually takes a few minutes — no action needed on your end. We'll periodically check and update the status. \033[0m\n";
            }
        } catch (Throwable $e) {
            // Failed validation or provider lookups also consume a retry, but
            // an issuance failure must not count the reserved attempt twice.
            if (!$issuanceStarted && !$exhausted) {
                $certificate->setAttribute('attempts', $certificate->getAttribute('attempts', 0) + 1);
            }
            $date = \date('H:i:s');
            $logs .= "\033[90m[{$date}] \033[31mSSL certificate issuance failed: \033[0m\n";
            $logs .= \mb_strcut($e->getMessage(), 0, 500000);
            $certificate->setAttribute('renewDate', DateTime::now());
            $rule->setAttribute('status', RULE_STATUS_CERTIFICATE_GENERATION_FAILED);
            $error = $e;
            throw $e;
        } finally {
            $saved = $dbForPlatform->withTransaction(function () use ($dbForPlatform, $rule, $certificate, $lease, $logs, $claimedStatus): ?Document {
                $current = $dbForPlatform->getDocument('rules', $rule->getId(), forUpdate: true);
                if ($current->isEmpty()
                    || $current->getSequence() !== $rule->getSequence()
                    || $current->getAttribute('certificateId') !== $certificate->getId()
                    || $current->getAttribute('projectId') !== $rule->getAttribute('projectId')
                    || $current->getAttribute('domain') !== $rule->getAttribute('domain')
                    || $current->getAttribute('status') !== $claimedStatus) {
                    return null;
                }
                $latest = $dbForPlatform->getDocument('certificates', $certificate->getId(), forUpdate: true);
                if ($latest->isEmpty() || $latest->getSequence() !== $certificate->getSequence() || $latest->getAttribute('updated') !== $lease) {
                    return null;
                }
                $dbForPlatform->updateDocument('certificates', $certificate->getId(), new Document([
                    'updated' => null,
                    'attempts' => $certificate->getAttribute('attempts', 0),
                    'issueDate' => $certificate->getAttribute('issueDate'),
                    'renewDate' => $certificate->getAttribute('renewDate'),
                    'logs' => $logs,
                ]));
                return $dbForPlatform->updateDocument('rules', $rule->getId(), new Document([
                    'status' => $rule->getAttribute('status'),
                    'certificateId' => $certificate->getId(),
                    'logs' => $logs,
                ]));
            });
            if ($saved !== null && !$saved->isEmpty()) {
                $this->sendEvents($saved, $dbForPlatform, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $queueForRealtime, $bus);
                if ($error !== null) {
                    $this->notifyError($domain->get(), $error->getMessage(), $certificate->getAttribute('attempts', 0), $publisherForMails, $plan);
                }
            }
        }
    }

    /**
     * Save the rule's certificate status and publish the update.
     *
     * @param Document $rule Rule document that is affected by new certificate
     * @param Database $dbForPlatform Database connection for console
     * @param Event $queueForEvents Event publisher for events
     * @param Webhook $queueForWebhooks Webhook publisher for webhooks
     * @param FunctionPublisher $publisherForFunctions Function publisher for functions
     * @param Realtime $queueForRealtime Realtime publisher for realtime events
     *
     * @return void
     */
    protected function updateRuleAndSendEvents(
        Document $rule,
        Database $dbForPlatform,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Realtime $queueForRealtime,
        Bus $bus
    ): void {
        $rule = $dbForPlatform->updateDocument('rules', $rule->getId(), new Document([
            'status' => $rule->getAttribute('status'),
            'certificateId' => $rule->getAttribute('certificateId'),
            'logs' => $rule->getAttribute('logs'),
        ]));
        $this->sendEvents($rule, $dbForPlatform, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $queueForRealtime, $bus);
    }

    protected function sendEvents(
        Document $rule,
        Database $dbForPlatform,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Realtime $queueForRealtime,
        Bus $bus,
    ): void {
        $bus->dispatch(new RuleUpdated($rule->getArrayCopy()));

        $projectId = $rule->getAttribute('projectId');

        // Skip events for console project (triggered by auto-ssl generation for 1 click setups)
        if ($projectId === 'console') {
            return;
        }

        $project = $dbForPlatform->getDocument('projects', $projectId);
        if ($project->isEmpty()) {
            return;
        }

        $ruleModel = new Rule();
        $queueForEvents
            ->setProject($project)
            ->setEvent('rules.[ruleId].update')
            ->setParam('ruleId', $rule->getId())
            ->setPayload($rule->getArrayCopy(array_keys($ruleModel->getRules())));

        /** Trigger Webhook */
        $queueForWebhooks
            ->from($queueForEvents)
            ->trigger();

        /** Trigger Functions */
        $publisherForFunctions->enqueue(FunctionMessage::fromEvent(
            event: $queueForEvents->getEvent(),
            params: $queueForEvents->getParams(),
            project: $queueForEvents->getProject(),
            user: $queueForEvents->getUser(),
            userId: $queueForEvents->getUserId(),
            payload: $queueForEvents->getPayload(),
            platform: $queueForEvents->getPlatform(),
        ));

        /** Trigger Realtime Events */
        $queueForRealtime
            ->setSubscribers(['console', $projectId])
            ->from($queueForEvents)
            ->trigger();
    }

    /**
     * Internal domain validation functionality to prevent unnecessary attempts. We check:
     * - Domain needs to be public and valid (prevents NFT domains that are not supported)
     * - Domain must have proper DNS record
     *
     * @param Document $rule Rule to validate
     * @param Domain $domain Domain to validate
     * @param string|null $validationDomain Override for main domain check
     *
     * @return void
     * @throws Exception
     */
    private function validateDomain(Document $rule, Domain $domain, ?string $validationDomain = null): void
    {
        $mainDomain = $validationDomain ?? $this->getMainDomain();
        $isMainDomain = !isset($mainDomain) || $domain->get() === $mainDomain;
        $isAppwriteOwned = $rule->getAttribute('owner') === 'Appwrite';

        // Skip DNS verification for the main domain and Appwrite-owned subdomains
        // (auto-generated under _APP_DOMAIN_FUNCTIONS / _APP_DOMAIN_SITES). Appwrite
        // created those subdomains itself; they typically rely on a wildcard A/AAAA
        // record rather than a per-subdomain CNAME to _APP_DOMAIN_TARGET_CNAME.
        if ($isMainDomain || $isAppwriteOwned) {
            // TODO: Would be awesome to check A/AAAA record here. Maybe dry run?
            return;
        }

        try {
            $this->verifyRule($rule);
        } catch (AppwriteException $err) {
            $msg = $err->getMessage() . "\n";
            $msg .= "Verify your DNS records are correctly configured and try again.\n";
            $msg .= "If they're correct and it still fails, please retry after sometime. DNS records can take up to 48 hours to propagate.\n";
            throw new AppwriteException($err->getType(), $msg);
        }
    }

    /**
     * Get main domain. Needed as we do different checks for main and non-main domains.
     *
     * @return null|string Returns main domain. If null, there is no main domain yet.
     */
    private function getMainDomain(): ?string
    {
        $envDomain = System::getEnv('_APP_DOMAIN', '');
        if (!empty($envDomain) && $envDomain !== 'localhost') {
            return $envDomain;
        }

        return null;
    }

    /**
     * Method to make sure information about error is delivered to administrator.
     *
     * @param string $domain Domain that caused the error
     * @param string $errorMessage Verbose error message
     * @param int $attempt How many times it failed already
     * @param MailPublisher $publisherForMails
     * @param array $plan
     * @return void
     * @throws Exception
     */
    private function notifyError(string $domain, string $errorMessage, int $attempt, MailPublisher $publisherForMails, array $plan): void
    {
        // Log error into console
        Console::warning('Cannot renew domain (' . $domain . ') on attempt no. ' . $attempt . ' certificate: ' . $errorMessage);

        $locale = new Locale(System::getEnv('_APP_LOCALE', 'en'));
        $locale->setFallback('en');

        // Send mail to administrator mail
        $template = Template::fromFile(__DIR__ . '/../../../../app/config/locale/templates/email-certificate-failed.tpl');
        $template->setParam('{{domain}}', $domain);
        $template->setParam('{{error}}', \nl2br($errorMessage));
        $template->setParam('{{attempts}}', $attempt);

        $body = $template->render();

        $emailVariables = [
            'direction' => $locale->getText('settings.direction'),
            'domain' => $domain,
            'logoUrl' => $plan['logoUrl'] ?? APP_EMAIL_LOGO_URL,
            'accentColor' => $plan['accentColor'] ?? APP_EMAIL_ACCENT_COLOR,
            'twitterUrl' => $plan['twitterUrl'] ?? APP_SOCIAL_TWITTER,
            'discordUrl' => $plan['discordUrl'] ?? APP_SOCIAL_DISCORD,
            'githubUrl' => $plan['githubUrl'] ?? APP_SOCIAL_GITHUB_APPWRITE,
            'termsUrl' => $plan['termsUrl'] ?? APP_EMAIL_TERMS_URL,
            'privacyUrl' => $plan['privacyUrl'] ?? APP_EMAIL_PRIVACY_URL,
        ];

        $subject = $locale->getText("emails.certificate.subject");
        $preview = $locale->getText("emails.certificate.preview");

        $publisherForMails->enqueue(new MailMessage(
            recipient: System::getEnv('_APP_EMAIL_CERTIFICATES', System::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS')),
            name: 'Appwrite Administrator',
            subject: $subject,
            template: MAIL_TEMPLATE_CERTIFICATE_FAILED,
            bodyTemplate: __DIR__ . '/../../../../app/config/locale/templates/email-base-styled.tpl',
            body: $body,
            preview: $preview,
            variables: $emailVariables,
        ));
    }
}
