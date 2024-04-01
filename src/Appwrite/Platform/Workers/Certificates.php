<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Mail;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Network\Validator\CNAME;
use Appwrite\Template\Template;
use Appwrite\Utopia\Response\Model\Rule;
use Exception;
use Throwable;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Domains\Domain;
use Utopia\Locale\Locale;
use Utopia\Logger\Log;
use Utopia\Platform\Action;
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
    public function __construct()
    {
        $this
            ->desc('Certificates worker')
            ->inject('message')
            ->inject('dbForConsole')
            ->inject('queueForMails')
            ->inject('queueForEvents')
            ->inject('queueForFunctions')
            ->inject('log')
            ->callback(fn (Message $message, Database $dbForConsole, Mail $queueForMails, Event $queueForEvents, Func $queueForFunctions, Log $log) => $this->action($message, $dbForConsole, $queueForMails, $queueForEvents, $queueForFunctions, $log));
    }

    /**
     * @param Message $message
     * @param Database $dbForConsole
     * @param Mail $queueForMails
     * @param Event $queueForEvents
     * @param Func $queueForFunctions
     * @param Log $log
     * @return void
     * @throws Throwable
     * @throws \Utopia\Database\Exception
     */
    public function action(Message $message, Database $dbForConsole, Mail $queueForMails, Event $queueForEvents, Func $queueForFunctions, Log $log): void
    {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $document = new Document($payload['domain'] ?? []);
        $domain   = new Domain($document->getAttribute('domain', ''));
        $skipRenewCheck = $payload['skipRenewCheck'] ?? false;

        $log->addTag('domain', $domain->get());

        $this->execute($domain, $dbForConsole, $queueForMails, $queueForEvents, $queueForFunctions, $log, $skipRenewCheck);
    }

    /**
     * @param Domain $domain
     * @param Database $dbForConsole
     * @param Mail $queueForMails
     * @param Event $queueForEvents
     * @param Func $queueForFunctions
     * @param bool $skipRenewCheck
     * @return void
     * @throws Throwable
     * @throws \Utopia\Database\Exception
     */
    private function execute(Domain $domain, Database $dbForConsole, Mail $queueForMails, Event $queueForEvents, Func $queueForFunctions, Log $log, bool $skipRenewCheck = false): void
    {
        /**
         * 1. Read arguments and validate domain
         * 2. Get main domain
         * 3. Validate CNAME DNS if parameter is not main domain (meaning it's custom domain)
         * 4. Validate security email. Cannot be empty, required by LetsEncrypt
         * 5. Validate renew date with certificate file, unless requested to skip by parameter
         * 6. Issue a certificate using certbot CLI
         * 7. Update 'log' attribute on certificate document with Certbot message
         * 8. Create storage folder for certificate, if not ready already
         * 9. Move certificates from Certbot location to our Storage
         * 10. Create/Update our Storage with new Traefik config with new certificate paths
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
         * Note: Renewals are checked and scheduled from maintenence worker
         */

        // Get current certificate
        $certificate = $dbForConsole->findOne('certificates', [Query::equal('domain', [$domain->get()])]);

        // If we don't have certificate for domain yet, let's create new document. At the end we save it
        if (!$certificate) {
            $certificate = new Document();
            $certificate->setAttribute('domain', $domain->get());
        }

        $success = false;

        try {
            // Email for alerts is required by LetsEncrypt
            $email = System::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS');
            if (empty($email)) {
                throw new Exception('You must set a valid security email address (_APP_SYSTEM_SECURITY_EMAIL_ADDRESS) to issue an SSL certificate.');
            }

            // Validate domain and DNS records. Skip if job is forced
            if (!$skipRenewCheck) {
                $mainDomain = $this->getMainDomain();
                $isMainDomain = !isset($mainDomain) || $domain->get() === $mainDomain;
                $this->validateDomain($domain, $isMainDomain, $log);
            }

            // If certificate exists already, double-check expiry date. Skip if job is forced
            if (!$skipRenewCheck && !$this->isRenewRequired($domain->get(), $log)) {
                throw new Exception('Renew isn\'t required.');
            }

            // Prepare folder name for certbot. Using this helps prevent miss-match in LetsEncrypt configuration when renewing certificate
            $folder = ID::unique();

            // Generate certificate files using Let's Encrypt
            $letsEncryptData = $this->issueCertificate($folder, $domain->get(), $email);

            // Command succeeded, store all data into document
            $logs = 'Certificate successfully generated.';
            $certificate->setAttribute('logs', \mb_strcut($logs, 0, 1000000));// Limit to 1MB


            // Give certificates to Traefik
            $this->applyCertificateFiles($folder, $domain->get(), $letsEncryptData);

            // Update certificate info stored in database
            $certificate->setAttribute('renewDate', $this->getRenewDate($domain->get()));
            $certificate->setAttribute('attempts', 0);
            $certificate->setAttribute('issueDate', DateTime::now());
            $success = true;
        } catch (Throwable $e) {
            $logs = $e->getMessage();

            // Set exception as log in certificate document
            $certificate->setAttribute('logs', \mb_strcut($logs, 0, 1000000));// Limit to 1MB

            // Increase attempts count
            $attempts = $certificate->getAttribute('attempts', 0) + 1;
            $certificate->setAttribute('attempts', $attempts);

            // Store cuttent time as renew date to ensure another attempt in next maintenance cycle
            $certificate->setAttribute('renewDate', DateTime::now());

            // Send email to security email
            $this->notifyError($domain->get(), $e->getMessage(), $attempts, $queueForMails);

            throw $e;
        } finally {
            // All actions result in new updatedAt date
            $certificate->setAttribute('updated', DateTime::now());

            // Save all changes we made to certificate document into database
            $this->saveCertificateDocument($domain->get(), $certificate, $success, $dbForConsole, $queueForEvents, $queueForFunctions);
        }
    }

    /**
     * Save certificate data into database.
     *
     * @param string $domain Domain name that certificate is for
     * @param Document $certificate Certificate document that we need to save
     * @param bool $success
     * @param Database $dbForConsole Database connection for console
     * @param Event $queueForEvents
     * @param Func $queueForFunctions
     * @return void
     * @throws \Utopia\Database\Exception
     * @throws Authorization
     * @throws Conflict
     * @throws Structure
     */
    private function saveCertificateDocument(string $domain, Document $certificate, bool $success, Database $dbForConsole, Event $queueForEvents, Func $queueForFunctions): void
    {
        // Check if update or insert required
        $certificateDocument = $dbForConsole->findOne('certificates', [Query::equal('domain', [$domain])]);
        if (!empty($certificateDocument) && !$certificateDocument->isEmpty()) {
            // Merge new data with current data
            $certificate = new Document(\array_merge($certificateDocument->getArrayCopy(), $certificate->getArrayCopy()));
            $certificate = $dbForConsole->updateDocument('certificates', $certificate->getId(), $certificate);
        } else {
            $certificate->removeAttribute('$internalId');
            $certificate = $dbForConsole->createDocument('certificates', $certificate);
        }

        $certificateId = $certificate->getId();
        $this->updateDomainDocuments($certificateId, $domain, $success, $dbForConsole, $queueForEvents, $queueForFunctions);
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
     * Internal domain validation functionality to prevent unnecessary attempts failed from Let's Encrypt side. We check:
     * - Domain needs to be public and valid (prevents NFT domains that are not supported by Let's Encrypt)
     * - Domain must have proper DNS record
     *
     * @param Domain $domain Domain which we validate
     * @param bool $isMainDomain In case of master domain, we look for different DNS configurations
     *
     * @return void
     * @throws Exception
     */
    private function validateDomain(Domain $domain, bool $isMainDomain, Log $log): void
    {
        if (empty($domain->get())) {
            throw new Exception('Missing certificate domain.');
        }

        if (!$domain->isKnown() || $domain->isTest()) {
            throw new Exception('Unknown public suffix for domain.');
        }

        if (!$isMainDomain) {
            // TODO: Would be awesome to also support A/AAAA records here. Maybe dry run?
            // Validate if domain target is properly configured
            $target = new Domain(System::getEnv('_APP_DOMAIN_TARGET', ''));

            if (!$target->isKnown() || $target->isTest()) {
                throw new Exception('Unreachable CNAME target (' . $target->get() . '), please use a domain with a public suffix.');
            }

            // Verify domain with DNS records
            $validationStart = \microtime(true);
            $validator = new CNAME($target->get());
            if (!$validator->isValid($domain->get())) {
                $log->addExtra('dnsTiming', \strval(\microtime(true) - $validationStart));
                $log->addTag('dnsDomain', $domain->get());

                $error = $validator->getLogs();
                $log->addExtra('dnsResponse', \is_array($error) ? \json_encode($error) : \strval($error));

                throw new Exception('Failed to verify domain DNS records.');
            }
        } else {
            // Main domain validation
            // TODO: Would be awesome to check A/AAAA record here. Maybe dry run?
        }
    }

    /**
     * Reads expiry date of certificate from file and decides if renewal is required or not.
     *
     * @param string $domain Domain for which we check certificate file
     * @return bool True, if certificate needs to be renewed
     * @throws Exception
     */
    private function isRenewRequired(string $domain, Log $log): bool
    {
        $certPath = APP_STORAGE_CERTIFICATES . '/' . $domain . '/cert.pem';
        if (\file_exists($certPath)) {
            $validTo = null;

            $certData = openssl_x509_parse(file_get_contents($certPath));
            $validTo = $certData['validTo_time_t'] ?? 0;

            if (empty($validTo)) {
                $log->addTag('certificateDomain', $domain);
                throw new Exception('Unable to read certificate file (cert.pem).');
            }

            // LetsEncrypt allows renewal 30 days before expiry
            $expiryInAdvance = (60 * 60 * 24 * 30);
            if ($validTo - $expiryInAdvance > \time()) {
                $log->addTag('certificateDomain', $domain);
                $log->addExtra('certificateData', \is_array($certData) ? \json_encode($certData) : \strval($certData));
                return false;
            }
        }

        return true;
    }

    /**
     * LetsEncrypt communication to issue certificate (using certbot CLI)
     *
     * @param string $folder Folder into which certificates should be generated
     * @param string $domain Domain to generate certificate for
     * @return array Named array with keys 'stdout' and 'stderr', both string
     * @throws Exception
     */
    private function issueCertificate(string $folder, string $domain, string $email): array
    {
        $stdout = '';
        $stderr = '';

        $staging = (App::isProduction()) ? '' : ' --dry-run';
        $exit = Console::execute("certbot certonly -v --webroot --noninteractive --agree-tos{$staging}"
            . " --email " . $email
            . " --cert-name " . $folder
            . " -w " . APP_STORAGE_CERTIFICATES
            . " -d {$domain}", '', $stdout, $stderr);

        // Unexpected error, usually 5XX, API limits, ...
        if ($exit !== 0) {
            throw new Exception('Failed to issue a certificate with message: ' . $stderr);
        }

        return [
            'stdout' => $stdout,
            'stderr' => $stderr
        ];
    }

    /**
     * Read new renew date from certificate file generated by Let's Encrypt
     *
     * @param string $domain Domain which certificate was generated for
     * @return string
     * @throws \Utopia\Database\Exception
     */
    private function getRenewDate(string $domain): string
    {
        $certPath = APP_STORAGE_CERTIFICATES . '/' . $domain . '/cert.pem';
        $certData = openssl_x509_parse(file_get_contents($certPath));
        $validTo = $certData['validTo_time_t'] ?? null;
        $dt = (new \DateTime())->setTimestamp($validTo);
        return DateTime::addSeconds($dt, -60 * 60 * 24 * 30); // -30 days
    }

    /**
     * Method to take files from Let's Encrypt, and put it into Traefik.
     *
     * @param string $domain Domain which certificate was generated for
     * @param string $folder Folder in which certificates were generated
     * @param array $letsEncryptData Let's Encrypt logs to use for additional info when throwing error
     * @return void
     * @throws Exception
     */
    private function applyCertificateFiles(string $folder, string $domain, array $letsEncryptData): void
    {

        // Prepare folder in storage for domain
        $path = APP_STORAGE_CERTIFICATES . '/' . $domain;
        if (!\is_readable($path)) {
            if (!\mkdir($path, 0755, true)) {
                throw new Exception('Failed to create path for certificate.');
            }
        }

        // Move generated files
        if (!@\rename('/etc/letsencrypt/live/' . $folder . '/cert.pem', APP_STORAGE_CERTIFICATES . '/' . $domain . '/cert.pem')) {
            throw new Exception('Failed to rename certificate cert.pem. Let\'s Encrypt log: ' . $letsEncryptData['stderr'] . ' ; ' . $letsEncryptData['stdout']);
        }

        if (!@\rename('/etc/letsencrypt/live/' . $folder . '/chain.pem', APP_STORAGE_CERTIFICATES . '/' . $domain . '/chain.pem')) {
            throw new Exception('Failed to rename certificate chain.pem. Let\'s Encrypt log: ' . $letsEncryptData['stderr'] . ' ; ' . $letsEncryptData['stdout']);
        }

        if (!@\rename('/etc/letsencrypt/live/' . $folder . '/fullchain.pem', APP_STORAGE_CERTIFICATES . '/' . $domain . '/fullchain.pem')) {
            throw new Exception('Failed to rename certificate fullchain.pem. Let\'s Encrypt log: ' . $letsEncryptData['stderr'] . ' ; ' . $letsEncryptData['stdout']);
        }

        if (!@\rename('/etc/letsencrypt/live/' . $folder . '/privkey.pem', APP_STORAGE_CERTIFICATES . '/' . $domain . '/privkey.pem')) {
            throw new Exception('Failed to rename certificate privkey.pem. Let\'s Encrypt log: ' . $letsEncryptData['stderr'] . ' ; ' . $letsEncryptData['stdout']);
        }

        $config = \implode(PHP_EOL, [
            "tls:",
            "  certificates:",
            "    - certFile: /storage/certificates/{$domain}/fullchain.pem",
            "      keyFile: /storage/certificates/{$domain}/privkey.pem"
        ]);

        // Save configuration into Traefik using our new cert files
        if (!\file_put_contents(APP_STORAGE_CONFIG . '/' . $domain . '.yml', $config)) {
            throw new Exception('Failed to save Traefik configuration.');
        }
    }

    /**
     * Method to make sure information about error is delivered to admnistrator.
     *
     * @param string $domain Domain that caused the error
     * @param string $errorMessage Verbose error message
     * @param int $attempt How many times it failed already
     * @param Mail $queueForMails
     * @return void
     * @throws Exception
     */
    private function notifyError(string $domain, string $errorMessage, int $attempt, Mail $queueForMails): void
    {
        // Log error into console
        Console::warning('Cannot renew domain (' . $domain . ') on attempt no. ' . $attempt . ' certificate: ' . $errorMessage);

        $locale = new Locale(System::getEnv('_APP_LOCALE', 'en'));

        // Send mail to administratore mail
        $template = Template::fromFile(__DIR__ . '/../../../../app/config/locale/templates/email-certificate-failed.tpl');
        $template->setParam('{{domain}}', $domain);
        $template->setParam('{{error}}', \nl2br($errorMessage));
        $template->setParam('{{attempts}}', $attempt);

        // TODO: Use setbodyTemplate once #7307 is merged
        $subject = 'Certificate failed to generate';
        $body = Template::fromFile(__DIR__ . '/../../../../app/config/locale/templates/email-base-styled.tpl');

        $subject = \sprintf($locale->getText("emails.certificate.subject"), $domain);

        $message = Template::fromFile(__DIR__ . '/../../../../app/config/locale/templates/email-inner-base.tpl');
        $message
            ->setParam('{{body}}', $locale->getText("emails.certificate.body"), escapeHtml: false)
            ->setParam('{{hello}}', $locale->getText("emails.certificate.hello"))
            ->setParam('{{footer}}', $locale->getText("emails.certificate.footer"))
            ->setParam('{{thanks}}', $locale->getText("emails.certificate.thanks"))
            ->setParam('{{signature}}', $locale->getText("emails.certificate.signature"));
        $body = $message->render();

        $emailVariables = [
            'direction' => $locale->getText('settings.direction'),
            'domain' => $domain,
            'error' => '<br><pre>' . $errorMessage . '</pre>',
            'attempt' => $attempt,
            'project' => 'Console',
            'redirect' => 'https://' . $domain,
        ];

        $queueForMails
            ->setSubject($subject)
            ->setBody($body)
            ->setName('Appwrite Administrator')
            ->setVariables($emailVariables)
            ->setRecipient(System::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS'))
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
     * @param bool $success Was certificate generation successful?
     *
     * @return void
     */
    private function updateDomainDocuments(string $certificateId, string $domain, bool $success, Database $dbForConsole, Event $queueForEvents, Func $queueForFunctions): void
    {

        $rule = $dbForConsole->findOne('rules', [
            Query::equal('domain', [$domain]),
        ]);

        if ($rule !== false && !$rule->isEmpty()) {
            $rule->setAttribute('certificateId', $certificateId);
            $rule->setAttribute('status', $success ? 'verified' : 'unverified');
            $dbForConsole->updateDocument('rules', $rule->getId(), $rule);

            $projectId = $rule->getAttribute('projectId');

            // Skip events for console project (triggered by auto-ssl generation for 1 click setups)
            if ($projectId === 'console') {
                return;
            }

            $project = $dbForConsole->getDocument('projects', $projectId);

            /** Trigger Webhook */
            $ruleModel = new Rule();
            $queueForEvents
                ->setProject($project)
                ->setEvent('rules.[ruleId].update')
                ->setParam('ruleId', $rule->getId())
                ->setPayload($rule->getArrayCopy(array_keys($ruleModel->getRules())))
                ->trigger();


            /** Trigger Functions */
            $queueForFunctions
                ->setProject($project)
                ->setEvent('rules.[ruleId].update')
                ->setParam('ruleId', $rule->getId())
                ->setPayload($rule->getArrayCopy(array_keys($ruleModel->getRules())))
                ->trigger();

            /** Trigger realtime event */
            $allEvents = Event::generateEvents('rules.[ruleId].update', [
                'ruleId' => $rule->getId(),
            ]);
            $target = Realtime::fromPayload(
                // Pass first, most verbose event pattern
                event: $allEvents[0],
                payload: $rule,
                project: $project
            );
            Realtime::send(
                projectId: 'console',
                payload: $rule->getArrayCopy(),
                events: $allEvents,
                channels: $target['channels'],
                roles: $target['roles']
            );
            Realtime::send(
                projectId: $project->getId(),
                payload: $rule->getArrayCopy(),
                events: $allEvents,
                channels: $target['channels'],
                roles: $target['roles']
            );
        }
    }
}
