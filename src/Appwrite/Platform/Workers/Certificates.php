<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Certificates\Adapter as CertificatesAdapter;
use Appwrite\Event\Certificate;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Mail;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Platform\Modules\Proxy\Action;
use Appwrite\Template\Template;
use Appwrite\Utopia\Response\Model\Rule;
use Exception;
use Throwable;
use Utopia\CLI\Console;
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
use Utopia\Logger\Log;
use Utopia\Queue\Message;
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
            ->inject('queueForMails')
            ->inject('queueForEvents')
            ->inject('queueForWebhooks')
            ->inject('queueForFunctions')
            ->inject('queueForRealtime')
            ->inject('queueForCertificates')
            ->inject('log')
            ->inject('certificates')
            ->inject('plan')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    /**
     * @param Message $message
     * @param Database $dbForPlatform
     * @param Mail $queueForMails
     * @param Event $queueForEvents
     * @param Webhook $queueForWebhooks
     * @param Func $queueForFunctions
     * @param Realtime $queueForRealtime
     * @param Certificate $queueForCertificates
     * @param Log $log
     * @param CertificatesAdapter $certificates
     * @param array $plan
     * @param ValidatorAuthorization $authorization
     * @return void
     * @throws Throwable
     * @throws \Utopia\Database\Exception
     */
    public function action(
        Message $message,
        Database $dbForPlatform,
        Mail $queueForMails,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        Func $queueForFunctions,
        Realtime $queueForRealtime,
        Certificate $queueForCertificates,
        Log $log,
        CertificatesAdapter $certificates,
        array $plan,
        ValidatorAuthorization $authorization,
    ): void {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $document = new Document($payload['domain'] ?? []);
        $domain   = new Domain($document->getAttribute('domain', ''));
        $domainType = $document->getAttribute('domainType');
        $skipRenewCheck = $payload['skipRenewCheck'] ?? false;
        $validationDomain = $payload['validationDomain'] ?? null;
        $action = $payload['action'] ?? Certificate::ACTION_GENERATION;

        $log->addTag('domain', $domain->get());

        switch ($action) {
            case Certificate::ACTION_DOMAIN_VERIFICATION:
                $this->handleDomainVerificationAction($domain, $dbForPlatform, $queueForEvents, $queueForWebhooks, $queueForFunctions, $queueForRealtime, $queueForCertificates, $log, $authorization, $validationDomain);
                break;

            case Certificate::ACTION_GENERATION:
                $this->handleCertificateGenerationAction($domain, $domainType, $dbForPlatform, $queueForMails, $queueForEvents, $queueForWebhooks, $queueForFunctions, $queueForRealtime, $log, $certificates, $authorization, $skipRenewCheck, $plan, $validationDomain);
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
     * @param Func $queueForFunctions
     * @param Realtime $queueForRealtime
     * @param Certificate $queueForCertificates
     * @param Log $log
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
        Func $queueForFunctions,
        Realtime $queueForRealtime,
        Certificate $queueForCertificates,
        Log $log,
        ValidatorAuthorization $authorization,
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
            $this->validateDomain($rule, $domain, $log, $validationDomain);
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
            $this->updateRuleAndSendEvents($rule, $dbForPlatform, $queueForEvents, $queueForWebhooks, $queueForFunctions, $queueForRealtime);
        }

        // Issue a TLS certificate when domain is verified
        if ($rule->getAttribute('status', '') === RULE_STATUS_CERTIFICATE_GENERATING) {
            $queueForCertificates
                ->setDomain(new Document([
                    'domain' => $rule->getAttribute('domain'),
                    'domainType' => $rule->getAttribute('deploymentResourceType', $rule->getAttribute('type')),
                ]))
                ->setAction(Certificate::ACTION_GENERATION)
                ->trigger();

            Console::success('Certificate generation triggered successfully.');
        }
    }

    /**
     * @param Domain $domain
     * @param ?string $domainType
     * @param Database $dbForPlatform
     * @param Mail $queueForMails
     * @param Event $queueForEvents
     * @param Webhook $queueForWebhooks
     * @param Func $queueForFunctions
     * @param Realtime $queueForRealtime
     * @param Log $log
     * @param CertificatesAdapter $certificates
     * @param ValidatorAuthorization $authorization
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
        ?string $domainType,
        Database $dbForPlatform,
        Mail $queueForMails,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        Func $queueForFunctions,
        Realtime $queueForRealtime,
        Log $log,
        CertificatesAdapter $certificates,
        ValidatorAuthorization $authorization,
        bool $skipRenewCheck = false,
        array $plan = [],
        ?string $validationDomain = null
    ): void {
        /**
         * 1. Read arguments and validate domain
         * 2. Get main domain
         * 3. Validate CNAME DNS if parameter is not main domain (meaning it's custom domain)
         * 4. Validate renew date with certificate file, unless requested to skip by parameter
         * 5. Issue a certificate using certbot CLI
         * 6. Update 'log' attribute on certificate document with Certbot message
         * 7. Create storage folder for certificate, if not ready already
         * 8. Move certificates from Certbot location to our Storage
         * 9. Create/Update our Storage with new Traefik config with new certificate paths
         * 11. Read certificate file and update 'renewDate' on certificate document
         * 12. Update 'issueDate' and 'attempts' on certificate
         *
         * If at any point unexpected error occurs, program stops without applying changes to document, and error is thrown into worker
         *
         * If code stops with expected error:
         * 1. 'log' attribute on document is updated with error message
         * 2. 'attempts' amount is increased
         * 3. Console log is shown
         * 4. Email is sent to security email
         *
         * Unless unexpected error occurs, at the end, we:
         * 1. Update 'updated' attribute on document
         * 2. Save document to database
         * 3. Update all domains documents with current certificate ID
         *
         * Note: Renewals are checked and scheduled from maintenance worker
         */

        // Get rule document for domain
        // TODO: (@Meldiron) Remove after 1.7.x migration
        $rule = System::getEnv('_APP_RULES_FORMAT') === 'md5'
            ? $dbForPlatform->getDocument('rules', md5($domain->get()))
            : $dbForPlatform->findOne('rules', [
                Query::equal('domain', [$domain->get()]),
                Query::limit(1),
            ]);

        // Rule not found (or) not in the expected state
        if ($rule->isEmpty() || !\in_array($rule->getAttribute('status'), [RULE_STATUS_CERTIFICATE_GENERATING, RULE_STATUS_VERIFIED])) {
            Console::warning('Certificate generation for ' . $domain->get() . ' is skipped as the associated rule is either empty or not in the expected state.');
            return;
        }

        // Get associated certificate for the rule
        $certificate = $dbForPlatform->getDocument('certificates', $rule->getAttribute('certificateId') ?? '');

        // If we don't have certificate for the rule yet, let's create one.
        if ($certificate->isEmpty()) {
            $certificate = new Document();
            $certificate->setAttribute('domain', $domain->get());
        }

        $date = \date('H:i:s');
        $logs = "\033[90m[{$date}] \033[97mProcessing SSL certificate issuance. \033[0m\n";

        try {
            $certificate->setAttribute('logs', $logs);

            // Persist ASAP so that logs are reset in retry flow and user can see the latest logs on Console.
            $certificate = $this->upsertCertificate($rule, $certificate, $dbForPlatform);
            // Ensure certificate is associated with the rule
            $rule->setAttribute('certificateId', $certificate->getId());

            // Validate domain and DNS records. Skip if job is forced
            if (!$skipRenewCheck) {
                $this->validateDomain($rule, $domain, $log, $validationDomain);

                // If certificate exists already, double-check expiry date. Skip if job is forced
                if (!$certificates->isRenewRequired($domain->get(), $domainType, $log)) {
                    Console::info("Skipping, renew isn't required");
                    return;
                }
            }

            // Prepare unique cert name. Using this helps prevent mismatch in configuration when renewing certificates.
            $certName = ID::unique();
            $renewDate = $certificates->issueCertificate($certName, $domain->get(), $domainType);

            $date = \date('H:i:s');
            // If certificate is generated instantly, we can mark the rule as 'verified'.
            if ($certificates->isInstantGeneration($domain->get(), $domainType)) {
                $rule->setAttribute('status', RULE_STATUS_VERIFIED);
                $logs .= "\033[90m[{$date}] \033[97mSSL certificate successfully issued. \033[0m\n";
                $certificate->setAttribute('logs', $logs);
            } else {
                // Delayed generation: third-party handles certificate issuance asynchronously
                $logs .= "\033[90m[{$date}] \033[97mSSL certificate is being issued. This usually takes a few minutes — no action needed on your end. We'll periodically check and update the status. \033[0m\n";
                $certificate->setAttribute('logs', $logs);
            }

            $certificate->setAttributes([
                'attempts' => 0, // Reset attempts count
                'issueDate' => DateTime::now(), // Store current time as issue date
                'renewDate' => $renewDate,
            ]);
        } catch (Throwable $e) {
            $date = \date('H:i:s');
            $logs .= "\033[90m[{$date}] \033[31mSSL certificate issuance failed: \033[0m\n";
            $logs .= \mb_strcut($e->getMessage(), 0, 500000); // Limit to 500kb

            $attempts = $certificate->getAttribute('attempts', 0) + 1; // Increase attempts count

            // Update attributes on certificate document
            $certificate->setAttributes([
                'attempts' => $attempts,
                'renewDate' => DateTime::now(), // Store current time as renew date to ensure another attempt in next maintenance cycle.
            ]);

            // Mark rule as 'unverified'
            $rule->setAttribute('status', RULE_STATUS_CERTIFICATE_GENERATION_FAILED);

            // Send email to security email
            $this->notifyError($domain->get(), $e->getMessage(), $attempts, $queueForMails, $plan);

            throw $e;
        } finally {
            // Update certificate document with logs
            $certificate->setAttribute('logs', $logs);
            $this->upsertCertificate($rule, $certificate, $dbForPlatform);

            // Update rule and emit events
            $rule->setAttribute('certificateId', $certificate->getId());
            $rule->setAttribute('logs', $logs);
            $this->updateRuleAndSendEvents($rule, $dbForPlatform, $queueForEvents, $queueForWebhooks, $queueForFunctions, $queueForRealtime);
        }
    }

    /**
     * Save certificate data to database.
     *
     * @param Document $rule Rule associated with the domain
     * @param Document $certificate Certificate document that we need to save
     * @param Database $dbForPlatform Database connection for console
     * @return Document
     * @throws \Utopia\Database\Exception
     * @throws Authorization
     * @throws Conflict
     * @throws Structure
     */
    private function upsertCertificate(
        Document $rule,
        Document $certificate,
        Database $dbForPlatform,
    ): Document {
        // Decide whether update (or) insert is needed
        $existingCertificate = $dbForPlatform->getDocument('certificates', $rule->getAttribute('certificateId') ?? '');

        if ($existingCertificate->isEmpty()) {
            $certificate->removeAttribute('$sequence');
            $certificate = $dbForPlatform->createDocument('certificates', $certificate);
        } else {
            $certificate = new Document(\array_merge($existingCertificate->getArrayCopy(), $certificate->getArrayCopy()));
            $certificate = $dbForPlatform->updateDocument('certificates', $certificate->getId(), $certificate);
        }

        return $certificate;
    }

    /**
     * Update all existing domain documents so they have relation to correct certificate document.
     * This solves issues:
     * - when adding a domain for which there is already a certificate
     * - when renew creates new document? It might?
     * - overall makes it more reliable
     *
     * @param Document $rule Rule document that is affected by new certificate
     * @param Database $dbForPlatform Database connection for console
     * @param Event $queueForEvents Event publisher for events
     * @param Webhook $queueForWebhooks Webhook publisher for webhooks
     * @param Func $queueForFunctions Function publisher for functions
     * @param Realtime $queueForRealtime Realtime publisher for realtime events
     *
     * @return void
     */
    protected function updateRuleAndSendEvents(
        Document $rule,
        Database $dbForPlatform,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        Func $queueForFunctions,
        Realtime $queueForRealtime
    ): void {
        $rule = $dbForPlatform->updateDocument('rules', $rule->getId(), $rule);
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
        $queueForFunctions
            ->from($queueForEvents)
            ->trigger();

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
     * @param Log $log Logger for adding metrics
     * @param string|null $validationDomain Override for main domain check
     *
     * @return void
     * @throws Exception
     */
    private function validateDomain(Document $rule, Domain $domain, Log $log, ?string $validationDomain = null): void
    {
        $mainDomain = $validationDomain ?? $this->getMainDomain();
        $isMainDomain = !isset($mainDomain) || $domain->get() === $mainDomain;

        if ($isMainDomain) {
            // Main domain validation
            // TODO: Would be awesome to check A/AAAA record here. Maybe dry run?
            return;
        }

        try {
            $this->verifyRule($rule, $log);
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
     * @param Mail $queueForMails
     * @param array $plan
     * @return void
     * @throws Exception
     */
    private function notifyError(string $domain, string $errorMessage, int $attempt, Mail $queueForMails, array $plan): void
    {
        // Log error into console
        Console::warning('Cannot renew domain (' . $domain . ') on attempt no. ' . $attempt . ' certificate: ' . $errorMessage);

        $locale = new Locale(System::getEnv('_APP_LOCALE', 'en'));
        $locale->setFallback(System::getEnv('_APP_LOCALE', 'en'));

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

        $queueForMails
            ->setSubject($subject)
            ->setPreview($preview)
            ->setBody($body)
            ->setName('Appwrite Administrator')
            ->setBodyTemplate(__DIR__ . '/../../../../app/config/locale/templates/email-base-styled.tpl')
            ->setVariables($emailVariables)
            ->setRecipient(System::getEnv('_APP_EMAIL_CERTIFICATES', System::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS')))
            ->trigger();
    }
}
