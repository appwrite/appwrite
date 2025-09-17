<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Certificates\Adapter as CertificatesAdapter;
use Appwrite\Event\Certificate;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Mail;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Extend\Exception as ExtendException;
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
        array $plan
    ): void {
        $payload = $message->getPayload() ?? [];
        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $document = new Document($payload['domain'] ?? []);
        $domain = new Domain($document->getAttribute('domain', ''));
        $domainType = $document->getAttribute('domainType');

        $skipRenewCheck = $payload['skipRenewCheck'] ?? false;
        $action = $payload['action'] ?? Certificate::ACTION_GENERATION;
        $verificationDomainFunction = $payload['verificationDomainFunction'] ?? null;
        $verificationDomainAPI = $payload['verificationDomainAPI'] ?? null;

        Console::log('Received ' . $action . ' action for ' . $domain->get() . ' domain');

        $log->addTag('domain', $domain->get());

        switch ($action) {
            case Certificate::ACTION_GENERATION:
                $this->executeGeneration($domain, $domainType, $dbForPlatform, $queueForMails, $queueForEvents, $queueForWebhooks, $queueForFunctions, $queueForRealtime, $log, $certificates, $skipRenewCheck, $plan, $verificationDomainFunction, $verificationDomainAPI);
                break;
            case Certificate::ACTION_VERIFICATION:
                $this->executeVerification($domain, $dbForPlatform, $log, $queueForCertificates, $verificationDomainFunction, $verificationDomainAPI);
                break;
            default:
                throw new Exception('Invalid action: ' . $action);
        }
    }

    private function executeVerification(
        Domain $domain,
        Database $dbForPlatform,
        Log $log,
        Certificate $queueForCertificates,
        ?string $verificationDomainFunction = null,
        ?string $verificationDomainAPI = null,
    ): void {
        // Get rule
        if (System::getEnv('_APP_RULES_FORMAT') === 'md5') {
            $rule = ValidatorAuthorization::skip(fn () => $dbForPlatform->getDocument('rules', md5($domain->get())));
        } else {
            $rule = ValidatorAuthorization::skip(
                fn () => $dbForPlatform->find('rules', [
                    Query::equal('domain', [$domain->get()]),
                    Query::limit(1)
                ])
            )[0] ?? new Document();
        }

        // Skip if verification not needed
        if ($rule->getAttribute('status', '') !== RULE_STATUS_VERIFICATION_FAILED) {
            Console::warning('Verification for ' . $rule->getAttribute('domain', '') . ' is not needed.');
            return;
        }

        Console::info('Verification for domain ' . $rule->getAttribute('domain', '') . ' started.');

        // Prepare for verification
        $mainDomain = $this->getMainDomain();
        $isMainDomain = isset($mainDomain) && $domain->get() === $mainDomain;

        // Verify DNS records
        $updates = new Document();
        $success = false;
        try {
            $this->validateDomain($rule, $isMainDomain, $log, $verificationDomainAPI, $verificationDomainFunction);
            $updates
                ->setAttribute('verificationLogs', '')
                ->setAttribute('status', RULE_STATUS_GENERATING_CERTIFICATE);

            Console::success('Verification succeeded.');
            $success = true;
        } catch (ExtendException $err) {
            Console::warning('Verification failed: ' . $err->getMessage());
            $updates->setAttribute('verificationLogs', $err->getMessage());
        }

        $rule = $dbForPlatform->updateDocument('rules', $rule->getId(), $updates);

        // Issue a TLS certificate when domain is verified
        if ($success) {
            $queueForCertificates
                ->setDomain(new Document([
                    'domain' => $rule->getAttribute('domain'),
                    'domainType' => $rule->getAttribute('deploymentResourceType', $rule->getAttribute('type')),
                ]))
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
     * @param Func $queueForFunctions
     * @param Realtime $queueForRealtime
     * @param CertificatesAdapter $certificates
     * @param bool $skipRenewCheck
     * @param array $plan
     * @param ?string $verificationDomainFunction
     * @param ?string $verificationDomainAPI
     * @return void
     * @throws Throwable
     * @throws \Utopia\Database\Exception
     */
    private function executeGeneration(
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
        bool $skipRenewCheck = false,
        array $plan = [],
        ?string $verificationDomainFunction = null,
        ?string $verificationDomainAPI = null
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

        // Get current certificate
        $certificate = $dbForPlatform->findOne('certificates', [Query::equal('domain', [$domain->get()])]);

        // If we don't have certificate for domain yet, let's create new document. At the end we save it
        if ($certificate->isEmpty()) {
            $certificate = new Document();
            $certificate->setAttribute('domain', $domain->get());
        }

        $status = $certificate->getAttribute('status', RULE_STATUS_GENERATING_CERTIFICATE);

        try {
            // Clean-up logs from previous attempt
            if (!empty($certificate->getAttribute('logs', ''))) {
                $certificate->setAttribute('logs', '');
            }

            $date = \date('H:i:s');
            $certificate->setAttribute('logs', "\033[90m[{$date}] \033[97mCertificate generation started. \033[0m\n");

            $certificate = $this->upsertCertificate($domain->get(), $certificate, $dbForPlatform);

            // Validate domain and DNS records. Skip if job is forced
            if (!$skipRenewCheck) {
                $mainDomain = $this->getMainDomain();
                $isMainDomain = isset($mainDomain) && $domain->get() === $mainDomain;

                // TODO: @christyjacob remove once we migrate the rules in 1.7.x
                if (System::getEnv('_APP_RULES_FORMAT') === 'md5') {
                    $rule = ValidatorAuthorization::skip(fn () => $dbForPlatform->getDocument('rules', md5($domain->get())));
                } else {
                    $rule = ValidatorAuthorization::skip(
                        fn () => $dbForPlatform->find('rules', [
                            Query::equal('domain', [$domain->get()]),
                            Query::limit(1)
                        ])
                    )[0] ?? new Document();
                }

                if ($rule->isEmpty()) {
                    $rule = new Document([
                        'domain' => $domain->get(),
                        'type' => 'api'
                    ]);
                }

                $this->validateDomain($rule, $isMainDomain, $log, $verificationDomainAPI, $verificationDomainFunction);

                // If certificate exists already, double-check expiry date. Skip if job is forced
                if (!$certificates->isRenewRequired($domain->get(), $domainType, $log)) {
                    Console::info("Skipping, renew isn't required");
                    return;
                }
            }

            // Prepare unique cert name. Using this helps prevent miss-match in configuration when renewing certificates.
            $certName = ID::unique();
            $renewDate = $certificates->issueCertificate($certName, $domain->get(), $domainType);

            // This is useful when cert provider does extra work in background
            // For example, verification, or example certificate distribution to all edges
            if ($certificates->isIssueInstant($domain->get(), $domainType)) {
                $status = RULE_STATUS_SUCCESSFUL;

                // Command succeeded, store all data into document
                $certificate->setAttribute('logs', 'Certificate successfully generated.');
            }

            // Update certificate info stored in database
            $certificate->setAttribute('renewDate', $renewDate);
            $certificate->setAttribute('attempts', 0);
            $certificate->setAttribute('issueDate', DateTime::now());
        } catch (Throwable $e) {
            $status = RULE_STATUS_GENERATION_FAILED;

            $logs = $e->getMessage();
            $currentLogs = $certificate->getAttribute('logs', '');
            $date = \date('H:i:s');
            $errorMessage = "\033[90m[{$date}] \033[31mCertificate generation failed: \033[0m\n";

            $certificate->setAttribute('logs', $currentLogs . $errorMessage . \mb_strcut($logs, 0, 500000));// Limit to 500kb

            // Increase attempts count
            $attempts = $certificate->getAttribute('attempts', 0) + 1;
            $certificate->setAttribute('attempts', $attempts);

            // Store current time as renew date to ensure another attempt in next maintenance cycle.
            $certificate->setAttribute('renewDate', DateTime::now());

            // Send email to security email
            $this->notifyError($domain->get(), $e->getMessage(), $attempts, $queueForMails, $plan);

            throw $e;
        } finally {
            // All actions result in new updatedAt date
            $certificate->setAttribute('updated', DateTime::now());

            // Save all changes we made to certificate document into database
            $certificate = $this->upsertCertificate($domain->get(), $certificate, $dbForPlatform);

            // Synchronize new status to all rules
            $this->updateDomainDocuments($certificate->getId(), $domain->get(), $status, $dbForPlatform, $queueForEvents, $queueForWebhooks, $queueForFunctions, $queueForRealtime);
        }
    }

    /**
     * Save certificate data into database.
     *
     * @param string $domain Domain name that certificate is for
     * @param Document $certificate Certificate document that we need to save
     * @param Database $dbForPlatform Database connection for console
     * @return Document
     * @throws \Utopia\Database\Exception
     * @throws Authorization
     * @throws Conflict
     * @throws Structure
     */
    private function upsertCertificate(
        string $domain,
        Document $certificate,
        Database $dbForPlatform,
    ): Document {
        // Check if update or insert required
        $certificateDocument = $dbForPlatform->findOne('certificates', [Query::equal('domain', [$domain])]);
        if (!$certificateDocument->isEmpty()) {
            // Merge new data with current data
            $certificate = new Document(\array_merge($certificateDocument->getArrayCopy(), $certificate->getArrayCopy()));
            $certificate = $dbForPlatform->updateDocument('certificates', $certificate->getId(), $certificate);
        } else {
            $certificate->removeAttribute('$sequence');
            $certificate = $dbForPlatform->createDocument('certificates', $certificate);
        }

        return $certificate;
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
     * Internal domain validation functionality to prevent unnecessary attempts. We check:
     * - Domain needs to be public and valid (prevents NFT domains that are not supported)
     * - Domain must have proper DNS record
     *
     * @param Document $rule Rule which we validate
     * @param bool $isMainDomain In case of master domain, we look for different DNS configurations
     * @param string|null $verificationDomainAPI Function to verify domain
     * @param string|null $verificationDomainFunction Function to verify domain
     *
     * @return void
     * @throws Exception
     */
    private function validateDomain(Document $rule, bool $isMainDomain, Log $log, ?string $verificationDomainAPI = null, ?string $verificationDomainFunction = null): void
    {
        if (!$isMainDomain) {
            $this->verifyRule($rule, $log, $verificationDomainAPI, $verificationDomainFunction);
        } else {
            // Main domain validation
            // TODO: Would be awesome to check A/AAAA record here. Maybe dry run?
        }
    }

    /**
     * Method to make sure information about error is delivered to admnistrator.
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

    /**
     * Update all existing domain documents so they have relation to correct certificate document.
     * This solved issues:
     * - when adding a domain for which there is already a certificate
     * - when renew creates new document? It might?
     * - overall makes it more reliable
     *
     * @param string $certificateId ID of a new or updated certificate document
     * @param string $domain Domain that is affected by new certificate
     * @param string $status Status of the certificate generation, can be 'verifying', 'verified' 'unverified'
     *
     * @return void
     */
    private function updateDomainDocuments(
        string $certificateId,
        string $domain,
        string $status,
        Database $dbForPlatform,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        Func $queueForFunctions,
        Realtime $queueForRealtime
    ): void {
        // TODO: @christyjacob remove once we migrate the rules in 1.7.x
        if (System::getEnv('_APP_RULES_FORMAT') === 'md5') {
            $rule = $dbForPlatform->getDocument('rules', md5($domain));
        } else {
            $rule = $dbForPlatform->findOne('rules', [
                Query::equal('domain', [$domain]),
            ]);
        }

        if (!$rule->isEmpty()) {
            $updates = new Document();

            $updates
                ->setAttribute('certificateId', $certificateId)
                ->setAttribute('status', $status);

            $rule = $dbForPlatform->updateDocument('rules', $rule->getId(), $updates);

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
                ->from($queueForEvents)
                ->setSubscribers(['console', $projectId])
                ->trigger();
        }
    }
}
