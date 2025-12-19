<?php

namespace Appwrite\Platform\Workers;

use Ahc\Jwt\JWT;
use Appwrite\Event\Mail;
use Appwrite\Event\Realtime;
use Appwrite\Extend\Exception;
use Appwrite\Template\Template;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Exception\Restricted;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Locale\Locale;
use Utopia\Migration\Destination;
use Utopia\Migration\Destinations\Appwrite as DestinationAppwrite;
use Utopia\Migration\Destinations\CSV as DestinationCSV;
use Utopia\Migration\Exception as MigrationException;
use Utopia\Migration\Source;
use Utopia\Migration\Sources\Appwrite;
use Utopia\Migration\Sources\Appwrite as SourceAppwrite;
use Utopia\Migration\Sources\CSV;
use Utopia\Migration\Sources\Firebase;
use Utopia\Migration\Sources\NHost;
use Utopia\Migration\Sources\Supabase;
use Utopia\Migration\Transfer;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Storage\Compression\Compression;
use Utopia\Storage\Device;
use Utopia\System\System;

class Migrations extends Action
{
    protected ?Database $dbForProject;
    protected ?Database $dbForPlatform;
    protected ?Device $deviceForMigrations;
    protected ?Device $deviceForFiles;
    protected ?Document $project;
    protected array $plan = [];

    /**
     * @var array<string, int>
     */
    protected array $sourceReport = [];

    /**
     * @var callable|null
     */
    protected $logError = null;

    public static function getName(): string
    {
        return 'migrations';
    }

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this
            ->desc('Migrations worker')
            ->inject('message')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('logError')
            ->inject('queueForRealtime')
            ->inject('deviceForMigrations')
            ->inject('deviceForFiles')
            ->inject('queueForMails')
            ->inject('plan')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    /**
     * @throws Exception
     */
    public function action(
        Message $message,
        Document $project,
        Database $dbForProject,
        Database $dbForPlatform,
        callable $logError,
        Realtime $queueForRealtime,
        Device $deviceForMigrations,
        Device $deviceForFiles,
        Mail $queueForMails,
        array $plan,
        Authorization $authorization,
    ): void {
        $payload = $message->getPayload() ?? [];
        $this->deviceForMigrations = $deviceForMigrations;
        $this->deviceForFiles = $deviceForFiles;
        $this->plan = $plan;

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $events = $payload['events'] ?? [];
        $migration = new Document($payload['migration'] ?? []);

        if ($project->getId() === 'console') {
            return;
        }

        $this->dbForProject = $dbForProject;
        $this->dbForPlatform = $dbForPlatform;
        $this->project = $project;
        $this->logError = $logError;

        $platform = $payload['platform'] ?? Config::getParam('platform', []);

        if (!empty($events)) {
            return;
        }

        try {
            $this->processMigration(
                $migration,
                $queueForRealtime,
                $queueForMails,
                $platform,
                $authorization
            );
        } finally {
            $this->dbForProject = null;
            $this->dbForPlatform = null;
            $this->project = null;
            $this->logError = null;
            $this->deviceForMigrations = null;
            $this->deviceForFiles = null;
            $this->plan = [];
            $this->sourceReport = [];

            \gc_collect_cycles();
        }
    }

    /**
     * @throws Exception
     */
    protected function processSource(Document $migration, array $platform): Source
    {
        $source = $migration->getAttribute('source');
        $destination = $migration->getAttribute('destination');
        $resourceId = $migration->getAttribute('resourceId');
        $credentials = $migration->getAttribute('credentials');
        $migrationOptions = $migration->getAttribute('options');
        $dataSource = Appwrite::SOURCE_API;
        $database = null;
        $queries = [];

        if ($source === Appwrite::getName() && $destination === DestinationCSV::getName()) {
            $dataSource = Appwrite::SOURCE_DATABASE;
            $database = $this->dbForProject;
            $queries = Query::parseQueries($migrationOptions['queries']);
        }

        $migrationSource = match ($source) {
            Firebase::getName() => new Firebase(
                json_decode($credentials['serviceAccount'], true),
            ),
            Supabase::getName() => new Supabase(
                $credentials['endpoint'],
                $credentials['apiKey'],
                $credentials['databaseHost'],
                'postgres',
                $credentials['username'],
                $credentials['password'],
                $credentials['port'],
            ),
            NHost::getName() => new NHost(
                $credentials['subdomain'],
                $credentials['region'],
                $credentials['adminSecret'],
                $credentials['database'],
                $credentials['username'],
                $credentials['password'],
                $credentials['port'],
            ),
            SourceAppwrite::getName() => new SourceAppwrite(
                $credentials['projectId'],
                $credentials['endpoint'],
                $credentials['apiKey'],
                $dataSource,
                $database,
                $queries,
            ),
            CSV::getName() => new CSV(
                $resourceId,
                $migrationOptions['path'],
                $this->deviceForMigrations,
                $this->dbForProject
            ),
            default => throw new \Exception('Invalid source type'),
        };

        $this->sourceReport = $migrationSource->report();

        return $migrationSource;
    }

    /**
     * @throws Exception
     */
    protected function processDestination(Document $migration, string $apiKey, array $platform): Destination
    {
        $destination = $migration->getAttribute('destination');
        $options = $migration->getAttribute('options', []);

        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';

        return match ($destination) {
            DestinationAppwrite::getName() => new DestinationAppwrite(
                $this->project->getId(),
                $protocol . '://' . $platform['apiHostname'] . '/v1',
                $apiKey,
                $this->dbForProject,
                Config::getParam('collections', [])['databases']['collections'],
            ),
            DestinationCSV::getName() => new DestinationCSV(
                $this->deviceForFiles,
                $migration->getAttribute('resourceId'),
                $options['bucketId'],
                $options['filename'],
                $options['columns'],
                $options['delimiter'],
                $options['enclosure'],
                $options['escape'],
                $options['header'],
            ),
            default => throw new \Exception('Invalid destination type'),
        };
    }

    /**
     * @throws AuthorizationException
     * @throws Structure
     * @throws Conflict
     * @throws \Utopia\Database\Exception
     * @throws Exception
     */
    protected function updateMigrationDocument(Document $migration, Document $project, Realtime $queueForRealtime): Document
    {
        $queueForRealtime
            ->setProject($project)
            ->setSubscribers(['console', $project->getId()])
            ->setEvent('migrations.[migrationId].update')
            ->setParam('migrationId', $migration->getId())
            ->setPayload($migration->getArrayCopy(), sensitive: ['credentials'])
            ->trigger();

        return $this->dbForProject->updateDocument(
            'migrations',
            $migration->getId(),
            $migration
        );
    }

    /**
     * @throws Exception
     */
    protected function generateAPIKey(Document $project): string
    {
        $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 86400, 0);

        $apiKey = $jwt->encode([
            'projectId' => $project->getId(),
            'disabledMetrics' => [
                METRIC_DATABASES_OPERATIONS_READS,
                METRIC_DATABASES_OPERATIONS_WRITES,
                METRIC_NETWORK_REQUESTS,
                METRIC_NETWORK_INBOUND,
                METRIC_NETWORK_OUTBOUND,
            ],
            'scopes' => [
                'users.read',
                'users.write',
                'teams.read',
                'teams.write',
                'buckets.read',
                'buckets.write',
                'files.read',
                'files.write',
                'functions.read',
                'functions.write',
                'tokens.read',
                'tokens.write',
            ]
        ]);

        return API_KEY_DYNAMIC . '_' . $apiKey;
    }

    /**
     * @throws AuthorizationException
     * @throws Conflict
     * @throws Restricted
     * @throws Structure
     * @throws \Utopia\Database\Exception
     * @throws Exception
     */
    protected function processMigration(
        Document $migration,
        Realtime $queueForRealtime,
        Mail $queueForMails,
        array $platform,
        Authorization $authorization,
    ): void {
        $project = $this->dbForPlatform->getDocument('projects', $this->project->getId());
        $tempAPIKey = $this->generateAPIKey($project);

        $transfer = $source = $destination = null;

        try {
            if (
                $migration->getAttribute('source') === SourceAppwrite::getName() &&
                empty($migration->getAttribute('credentials', []))
            ) {
                $credentials = $migration->getAttribute('credentials', []);
                $credentials['projectId'] = $credentials['projectId'] ?? $project->getId();
                $credentials['apiKey'] = $credentials['apiKey'] ?? $tempAPIKey;

                /**
                 * endpoint set
                 */
                if (empty($credentials['endpoint'])) {
                    $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
                    $credentials['endpoint'] = $protocol . '://' . $platform['apiHostname'] . '/v1';
                }
                $migration->setAttribute('credentials', $credentials);
            }

            $migration->setAttribute('stage', 'processing');
            $migration->setAttribute('status', 'processing');
            $this->updateMigrationDocument($migration, $project, $queueForRealtime);

            $source = $this->processSource($migration, $platform);
            $destination = $this->processDestination($migration, $tempAPIKey, $platform);

            $transfer = new Transfer(
                $source,
                $destination
            );

            /** Start Transfer */
            if (empty($source->getErrors())) {
                $migration->setAttribute('stage', 'migrating');
                $this->updateMigrationDocument($migration, $project, $queueForRealtime);

                $transfer->run(
                    $migration->getAttribute('resources'),
                    function () use ($migration, $transfer, $project, $queueForRealtime) {
                        $migration->setAttribute('resourceData', json_encode($transfer->getCache()));
                        $migration->setAttribute('statusCounters', json_encode($transfer->getStatusCounters()));
                        $this->updateMigrationDocument($migration, $project, $queueForRealtime);
                    },
                    $migration->getAttribute('resourceId'),
                    $migration->getAttribute('resourceType')
                );
            }

            $destination->shutdown();
            $source->shutdown();

            $sourceErrors = $source->getErrors();
            $destinationErrors = $destination->getErrors();

            if (!empty($sourceErrors) || ! empty($destinationErrors)) {
                $migration->setAttribute('status', 'failed');
                $migration->setAttribute('stage', 'finished');
                $migration->setAttribute('errors', $this->sanitizeErrors($sourceErrors, $destinationErrors));
                return;
            }

            $migration->setAttribute('status', 'completed');
            $migration->setAttribute('stage', 'finished');
        } catch (\Throwable $th) {
            Console::error('Message: ' . $th->getMessage());
            Console::error('File: ' . $th->getFile());
            Console::error('Line: ' . $th->getLine());
            Console::error($th->getTraceAsString());

            if (! $migration->isEmpty()) {
                $migration->setAttribute('status', 'failed');
                $migration->setAttribute('stage', 'finished');

                call_user_func($this->logError, $th, 'appwrite-worker', 'appwrite-queue-'.self::getName(), [
                    'migrationId' => $migration->getId(),
                    'source' => $migration->getAttribute('source') ?? '',
                    'destination' => $migration->getAttribute('destination') ?? '',
                ]);

                return;
            }

            if ($transfer) {
                $sourceErrors = $source->getErrors();
                $destinationErrors = $destination->getErrors();
                $migration->setAttribute('errors', $this->sanitizeErrors($sourceErrors, $destinationErrors));
            }
        } finally {
            $this->updateMigrationDocument($migration, $project, $queueForRealtime);

            if ($migration->getAttribute('status', '') === 'failed') {
                Console::error('Migration('.$migration->getSequence().':'.$migration->getId().') failed, Project('.$this->project->getSequence().':'.$this->project->getId().')');

                $sourceErrors = $source?->getErrors() ?? [];
                $destinationErrors = $destination?->getErrors() ?? [];

                foreach ([...$sourceErrors, ...$destinationErrors] as $error) {
                    /** @var MigrationException $error */
                    if ($error->getCode() === 0 || $error->getCode() >= 500) {
                        ($this->logError)($error, 'appwrite-worker', 'appwrite-queue-' . self::getName(), [
                            'migrationId' => $migration->getId(),
                            'source' => $migration->getAttribute('source') ?? '',
                            'destination' => $migration->getAttribute('destination') ?? '',
                            'resourceName' => $error->getResourceName(),
                            'resourceGroup' => $error->getResourceGroup(),
                        ]);
                    }
                }

                $source?->error();
                $destination?->error();
            }

            if ($migration->getAttribute('status', '') === 'completed') {
                $destination?->success();
                $source?->success();

                if ($migration->getAttribute('destination') === DestinationCSV::getName()) {
                    $this->handleCSVExportComplete($project, $migration, $queueForMails, $queueForRealtime, $platform, $authorization);
                }
            }

            $transfer = null;
            $source = null;
            $destination = null;
        }
    }

    /**
     * Handle actions to be performed when a CSV export migration is successfully completed
     *
     * @param Document $project
     * @param Document $migration
     * @param Mail $queueForMails
     * @return void
     * @throws AuthorizationException
     * @throws Structure
     * @throws \Utopia\Database\Exception
     * @throws Exception
     */
    protected function handleCSVExportComplete(
        Document $project,
        Document $migration,
        Mail $queueForMails,
        Realtime $queueForRealtime,
        array $platform,
        Authorization $authorization,
    ): void {
        $options = $migration->getAttribute('options', []);
        $bucketId = 'default'; // Always use platform default bucket
        $filename = $options['filename'] ?? 'export_' . \time();
        $userInternalId = $options['userInternalId'] ?? '';
        $user = $this->dbForPlatform->findOne('users', [
            Query::equal('$sequence', [$userInternalId])
        ]);

        if ($user->isEmpty()) {
            throw new \Exception('User ' . $userInternalId . ' not found');
        }

        $bucket = $authorization->skip(fn () => $this->dbForPlatform->getDocument('buckets', $bucketId));
        if ($bucket->isEmpty()) {
            throw new \Exception('Bucket not found');
        }

        $path = $this->deviceForFiles->getPath($bucketId . '/' . $this->sanitizeFilename($filename) . '.csv');
        $size = $this->deviceForFiles->getFileSize($path);
        $mime = $this->deviceForFiles->getFileMimeType($path);
        $hash = $this->deviceForFiles->getFileHash($path);
        $algorithm = Compression::NONE;
        $fileId = ID::unique();

        $sizeMB = \round($size / (1000 * 1000), 2);

        $planFileSize = empty($this->plan['fileSize'])
            ? PHP_INT_MAX
            : $this->plan['fileSize'];

        if ($sizeMB > $planFileSize) {
            try {
                $this->deviceForFiles->delete($path);
            } finally {
                $message = "Export file size {$sizeMB}MB exceeds your plan limit.";

                $this->dbForProject->updateDocument('migrations', $migration->getId(), $migration->setAttribute(
                    'errors',
                    json_encode(['code' => 0, 'message' => $message]),
                    Document::SET_TYPE_APPEND,
                ));

                $this->sendCSVEmail(
                    success: false,
                    project: $project,
                    user: $user,
                    options: $options,
                    queueForMails: $queueForMails,
                    platform: $platform,
                    sizeMB: $sizeMB
                );

                throw new \Exception($message);
            }
        }

        $this->dbForPlatform->createDocument('bucket_' . $bucket->getSequence(), new Document([
            '$id' => $fileId,
            '$permissions' => [
                Permission::read(Role::user($user->getId())),
            ],
            'bucketId' => $bucket->getId(),
            'bucketInternalId' => $bucket->getSequence(),
            'name' => $filename,
            'path' => $path,
            'signature' => $hash,
            'mimeType' => $mime,
            'sizeOriginal' => $size,
            'sizeActual' => $size,
            'algorithm' => $algorithm,
            'comment' => '',
            'chunksTotal' => 1,
            'chunksUploaded' => 1,
            'openSSLVersion' => null,
            'openSSLCipher' => null,
            'openSSLTag' => null,
            'openSSLIV' => null,
            'search' => \implode(' ', [$fileId, $filename]),
            'metadata' => ['content_type' => $mime]
        ]));

        Console::info("Created file document in bucket: $fileId");

        // Generate JWT valid for 1 hour
        $maxAge = 60 * 60;
        $encoder = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', $maxAge, 0);
        $jwt = $encoder->encode([
            'bucketId' => $bucketId,
            'fileId' => $fileId,
            'projectId' => $project->getId(),
            'internal' => true,
            'disposition' => 'attachment',
        ]);

        // Generate download URL with JWT
        $endpoint = System::getEnv('_APP_DOMAIN', '');
        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS', 'disabled') === 'enabled' ? 'https' : 'http';
        $downloadUrl = "{$protocol}://{$endpoint}/v1/storage/buckets/{$bucketId}/files/{$fileId}/push?project={$project->getId()}&jwt={$jwt}";
        $options['downloadUrl'] = $downloadUrl;
        $migration->setAttribute('options', $options);
        $this->updateMigrationDocument($migration, $project, $queueForRealtime);

        $this->sendCSVEmail(
            success: true,
            project: $project,
            user: $user,
            options: $options,
            queueForMails: $queueForMails,
            platform: $platform,
            downloadUrl: $downloadUrl
        );
    }

    /**
     * Send CSV export notification email
     *
     * @param bool $success Whether the export was successful
     * @param Document $project
     * @param Document $user The user who triggered the operation
     * @param array $options Migration options
     * @param Mail $queueForMails
     * @param array $platform
     * @param string $downloadUrl Download URL for successful exports
     * @param float $sizeMB File size in MB for failed exports
     * @return void
     * @throws \Exception
     */
    protected function sendCSVEmail(
        bool $success,
        Document $project,
        Document $user,
        array $options,
        Mail $queueForMails,
        array $platform,
        string $downloadUrl = '',
        float $sizeMB = 0.0,
    ): void {
        if (!($options['notify'] ?? false)) {
            return;
        }

        if ($user->isEmpty()) {
            Console::warning("User not found for CSV export notification: {$user->getSequence()}");
            return;
        }

        $locale = new Locale(System::getEnv('_APP_LOCALE', 'en'));
        $locale->setFallback(System::getEnv('_APP_LOCALE', 'en'));

        $emailType = $success
            ? 'success'
            : 'failure';

        // Get localized email content
        $subject = $locale->getText("emails.csvExport.{$emailType}.subject");
        $preview = $locale->getText("emails.csvExport.{$emailType}.preview");
        $hello = $locale->getText("emails.csvExport.{$emailType}.hello");
        $body = $locale->getText("emails.csvExport.{$emailType}.body");
        $footer = $locale->getText("emails.csvExport.{$emailType}.footer");
        $thanks = $locale->getText("emails.csvExport.{$emailType}.thanks");
        $signature = $locale->getText("emails.csvExport.{$emailType}.signature");
        $buttonText = $success ? $locale->getText("emails.csvExport.{$emailType}.buttonText") : '';

        // Build email body using appropriate template
        $templatePath = $success
            ? __DIR__ . '/../../../../app/config/locale/templates/email-inner-base.tpl'
            : __DIR__ . '/../../../../app/config/locale/templates/email-export-failed.tpl';

        $message = Template::fromFile($templatePath)
            ->setParam('{{body}}', $body, escapeHtml: false)
            ->setParam('{{hello}}', $hello)
            ->setParam('{{footer}}', $footer)
            ->setParam('{{thanks}}', $thanks)
            ->setParam('{{signature}}', $signature)
            ->setParam('{{direction}}', $locale->getText('settings.direction'))
            ->setParam('{{project}}', $project->getAttribute('name'))
            ->setParam('{{user}}', $user->getAttribute('name', $user->getAttribute('email')))
            ->setParam('{{size}}', $success ? '' : (string)$sizeMB);

        if ($success) {
            $message
                ->setParam('{{buttonText}}', $buttonText)
                ->setParam('{{redirect}}', $downloadUrl);
        }

        $emailBody = $message->render();

        $emailVariables = [
            'direction' => $locale->getText('settings.direction'),
            'logoUrl' => $platform['logoUrl'],
            'accentColor' => $platform['accentColor'],
            'twitter' => $platform['twitterUrl'],
            'discord' => $platform['discordUrl'],
            'github' => $platform['githubUrl'],
            'terms' => $platform['termsUrl'],
            'privacy' => $platform['privacyUrl'],
            'platform' => $platform['platformName'],
        ];

        $queueForMails
            ->setSubject($subject)
            ->setPreview($preview)
            ->setBody($emailBody)
            ->setBodyTemplate(__DIR__ . '/../../../../app/config/locale/templates/email-base-styled.tpl')
            ->setVariables($emailVariables)
            ->setName($user->getAttribute('name', $user->getAttribute('email')))
            ->setRecipient($user->getAttribute('email'))
            ->setSenderName($platform['emailSenderName'])
            ->trigger();

        Console::info("CSV export {$emailType} notification email sent to " . $user->getAttribute('email'));
    }

    /**
     * Sanitize a filename to make it filesystem-safe
     *
     * @param string $filename
     * @return string
     */
    protected function sanitizeFilename(string $filename): string
    {
        // Replace problematic characters with underscores
        $sanitized = \preg_replace('/[:\/<>"|*?]/', '_', $filename);
        $sanitized = \preg_replace('/[^\x20-\x7E]/', '_', $sanitized);
        $sanitized = \trim($sanitized);
        return empty($sanitized) ? 'export' : $sanitized;
    }

    /**
     * Sanitize migration errors, removing sensitive information like stack traces
     *
     * @param array $sourceErrors
     * @param array $destinationErrors
     * @return array
     */
    protected function sanitizeErrors(
        array $sourceErrors,
        array $destinationErrors,
    ): array {
        $errors = [];
        foreach ([...$sourceErrors, ...$destinationErrors] as $error) {
            $encoded = \json_decode(\json_encode($error), true);
            if (\is_array($encoded)) {
                if (isset($encoded['trace'])) {
                    unset($encoded['trace']);
                }
                $errors[] = \json_encode($encoded);
            } else {
                $errors[] = \json_encode($error);
            }
        }

        return $errors;
    }
}
