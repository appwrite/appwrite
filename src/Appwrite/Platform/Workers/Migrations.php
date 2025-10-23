<?php

namespace Appwrite\Platform\Workers;

use Ahc\Jwt\JWT;
use Appwrite\Event\Mail;
use Appwrite\Event\Realtime;
use Appwrite\Template\Template;
use Exception;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Exception\Restricted;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
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
    protected Database $dbForProject;

    protected Database $dbForPlatform;

    protected Device $deviceForMigrations;
    protected Device $deviceForFiles;

    protected Document $project;

    /**
     * Cached for performance.
     *
     * @var array<string, int>
     */
    protected array $sourceReport = [];

    /**
     * @var callable
     */
    protected $logError;

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
    ): void {
        $payload = $message->getPayload() ?? [];
        $this->deviceForMigrations = $deviceForMigrations;
        $this->deviceForFiles = $deviceForFiles;

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $events    = $payload['events'] ?? [];
        $migration = new Document($payload['migration'] ?? []);

        if ($project->getId() === 'console') {
            return;
        }

        $this->dbForProject = $dbForProject;
        $this->dbForPlatform = $dbForPlatform;
        $this->project = $project;
        $this->logError = $logError;

        /**
         * Handle Event execution.
         */
        if (! empty($events)) {
            return;
        }

        $this->processMigration($migration, $queueForRealtime, $queueForMails);
    }

    /**
     * @throws Exception
     */
    protected function processSource(Document $migration): Source
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
                $credentials['endpoint'] === 'http://localhost/v1' ? 'http://appwrite/v1' : $credentials['endpoint'],
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
    protected function processDestination(Document $migration, string $apiKey): Destination
    {
        $destination = $migration->getAttribute('destination');
        $options = $migration->getAttribute('options', []);

        return match ($destination) {
            DestinationAppwrite::getName() => new DestinationAppwrite(
                $this->project->getId(),
                'http://appwrite/v1',
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
     * Sanitize a filename to make it filesystem-safe
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
     * @throws Authorization
     * @throws Structure
     * @throws Conflict
     * @throws \Utopia\Database\Exception
     * @throws Exception
     */
    protected function updateMigrationDocument(Document $migration, Document $project, Realtime $queueForRealtime): Document
    {
        $messages = [];

        $errors = $migration->getAttribute('errors', []);
        foreach ($errors as $error) {
            $decoded = \json_decode($error, true);
            if (\is_array($decoded)) {
                if (isset($decoded['trace'])) {
                    unset($decoded['trace']);
                }
                $messages[] = json_encode($decoded);
            } else {
                $messages[] = $error;
            }
        }

        $migration->setAttribute('errors', $messages);

        /** Trigger Realtime Events */
        $queueForRealtime
            ->setProject($project)
            ->setSubscribers(['console', $project->getId()])
            ->setEvent('migrations.[migrationId].update')
            ->setParam('migrationId', $migration->getId())
            ->setPayload($migration->getArrayCopy(), sensitive: ['options', 'credentials'])
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
                'databases.read',
                'collections.read',
                'collections.write',
                'tables.read',
                'tables.write',
                'documents.read',
                'documents.write',
                'rows.read',
                'rows.write',
                'tokens.read',
                'tokens.write',
            ]
        ]);

        return API_KEY_DYNAMIC . '_' . $apiKey;
    }

    /**
     * @throws Authorization
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
                $credentials['endpoint'] = $credentials['endpoint'] ?? 'http://appwrite/v1';
                $credentials['apiKey'] = $credentials['apiKey'] ?? $tempAPIKey;
                $migration->setAttribute('credentials', $credentials);
            }

            $migration->setAttribute('stage', 'processing');
            $migration->setAttribute('status', 'processing');
            $this->updateMigrationDocument($migration, $project, $queueForRealtime);

            $source = $this->processSource($migration);
            $destination = $this->processDestination($migration, $tempAPIKey);

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

                $migration->setAttribute('errors', $errors);
                return;
            }

            $migration->setAttribute('status', 'completed');
            $migration->setAttribute('stage', 'finished');
        } catch (\Throwable $th) {
            Console::error($th->getMessage());
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

                $migration->setAttribute('errors', $errors);
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
                    $this->handleCSVExportComplete($project, $migration, $queueForMails);
                }
            }
        }
    }

    protected function handleCSVExportComplete(Document $project, Document $migration, Mail $queueForMails): void
    {
        $options = $migration->getAttribute('options', []);
        $bucketId = $options['bucketId'] ?? null;
        $filename = $options['filename'] ?? 'export_' . \time();
        $userInternalId = $options['userInternalId'] ?? '';

        $bucket = $this->dbForProject->getDocument('buckets', $bucketId);
        if ($bucket->isEmpty()) {
            throw new \Exception("Bucket not found: $bucketId");
        }

        $path = $this->deviceForFiles->getPath($bucketId . '/' . $this->sanitizeFilename($filename) . '.csv');
        $size = $this->deviceForFiles->getFileSize($path);
        $mime = $this->deviceForFiles->getFileMimeType($path);
        $hash = $this->deviceForFiles->getFileHash($path);
        $algorithm = Compression::NONE;
        $fileId = ID::unique();

        $this->dbForProject->createDocument('bucket_' . $bucket->getSequence(), new Document([
            '$id' => $fileId,
            '$permissions' => [],
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

        // No notification required, skip email sending
        if (!($options['notify'] ?? false)) {
            return;
        }

        $user = $this->dbForPlatform->findOne('users', [
            Query::equal('$sequence', [$userInternalId])
        ]);

        if ($user->isEmpty()) {
            Console::warning("User not found for CSV export notification: $userInternalId");
            return;
        }

        $locale = new Locale(System::getEnv('_APP_LOCALE', 'en'));
        $locale->setFallback(System::getEnv('_APP_LOCALE', 'en'));

        // Generate JWT valid for 1 hour
        $maxAge = 60 * 60;
        $encoder = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', $maxAge, 0);
        $jwt = $encoder->encode([
            'bucketId' => $bucketId,
            'fileId' => $fileId,
            'projectId' => $project->getId(),
        ]);

        // Generate download URL with JWT
        $endpoint = System::getEnv('_APP_DOMAIN', '');
        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS', 'disabled') === 'enabled' ? 'https' : 'http';
        $downloadUrl = "{$protocol}://{$endpoint}/v1/storage/buckets/{$bucketId}/files/{$fileId}/push?project={$project->getId()}&jwt={$jwt}";

        // Get localized email content
        $subject = $locale->getText('emails.csvExport.subject');
        $preview = $locale->getText('emails.csvExport.preview');
        $hello = $locale->getText('emails.csvExport.hello');
        $body = $locale->getText('emails.csvExport.body');
        $footer = $locale->getText('emails.csvExport.footer');
        $thanks = $locale->getText('emails.csvExport.thanks');
        $buttonText = $locale->getText('emails.csvExport.buttonText');
        $signature = $locale->getText('emails.csvExport.signature');

        // Build email body using inner template
        $message = Template::fromFile(__DIR__ . '/../../../../app/config/locale/templates/email-inner-base.tpl')
            ->setParam('{{body}}', $body, escapeHtml: false)
            ->setParam('{{hello}}', $hello)
            ->setParam('{{footer}}', $footer)
            ->setParam('{{thanks}}', $thanks)
            ->setParam('{{buttonText}}', $buttonText)
            ->setParam('{{signature}}', $signature)
            ->setParam('{{direction}}', $locale->getText('settings.direction'))
            ->setParam('{{project}}', $project->getAttribute('name'))
            ->setParam('{{user}}', $user->getAttribute('name', $user->getAttribute('email')))
            ->setParam('{{redirect}}', $downloadUrl);

        $emailBody = $message->render();

        $emailVariables = [
            'direction' => $locale->getText('settings.direction'),
            'project' => $project->getAttribute('name'),
            'user' => $user->getAttribute('name', $user->getAttribute('email')),
            'redirect' => $downloadUrl,
        ];

        $queueForMails
            ->setSubject($subject)
            ->setPreview($preview)
            ->setBody($emailBody)
            ->setBodyTemplate(__DIR__ . '/../../../../app/config/locale/templates/email-base-styled.tpl')
            ->setVariables($emailVariables)
            ->setName($user->getAttribute('name', $user->getAttribute('email')))
            ->setRecipient($user->getAttribute('email'))
            ->trigger();

        Console::info('CSV export notification email sent to ' . $user->getAttribute('email'));
    }
}
