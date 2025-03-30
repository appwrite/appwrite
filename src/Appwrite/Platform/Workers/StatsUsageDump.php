<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Extend\Exception;
use Throwable;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Registry\Registry;
use Utopia\System\System;

class StatsUsageDump extends Action
{
    protected const BATCH_AGGREGATION_INTERVAL = 60; // in seconds

    private int $lastDispatchTime = 0;
    private int $lastDispatchTimeLogsDB = 0;
    protected array $stats = [];

    /**
    * Stats for batch write separated per project
    * @var array
    */
    private array $projects = [];

    /**
     * Array of stat documents to batch write to logsDB
     * @var array
     */
    private array $statDocuments = [];

    protected function getBatchSize(): int
    {
        return intval(System::getEnv('_APP_QUEUE_PREFETCH_COUNT', 1));
    }

    protected Registry $register;

    /**
     * Metrics to skip writing to logsDB
     * As these metrics are calculated separately
     * by logs DB
     * @var array
     */
    protected array $skipBaseMetrics = [
        METRIC_DATABASES => true,
        METRIC_BUCKETS => true,
        METRIC_USERS => true,
        METRIC_FUNCTIONS => true,
        METRIC_TEAMS => true,
        METRIC_MESSAGES => true,
        METRIC_MAU => true,
        METRIC_WEBHOOKS => true,
        METRIC_PLATFORMS => true,
        METRIC_PROVIDERS => true,
        METRIC_TOPICS => true,
        METRIC_KEYS => true,
        METRIC_FILES => true,
        METRIC_FILES_STORAGE => true,
        METRIC_DEPLOYMENTS_STORAGE => true,
        METRIC_BUILDS_STORAGE => true,
        METRIC_DEPLOYMENTS => true,
        METRIC_BUILDS => true,
        METRIC_COLLECTIONS => true,
        METRIC_DOCUMENTS => true,
        METRIC_DATABASES_STORAGE => true,
    ];

    /**
     * Skip metrics associated with parent IDs
     * these need to be checked individually with `str_ends_with`
     */
    protected array $skipParentIdMetrics = [
        '.files',
        '.files.storage',
        '.collections',
        '.documents',
        '.deployments',
        '.deployments.storage',
        '.builds',
        '.builds.storage',
        '.databases.storage'
    ];

    /**
     * @var callable
     */
    protected mixed $getLogsDB;

    protected array $periods = [
        '1h' => 'Y-m-d H:00',
        '1d' => 'Y-m-d 00:00',
        'inf' => '0000-00-00 00:00'
    ];

    public static function getName(): string
    {
        return 'stats-usage-dump';
    }

    /**
     * @throws \Exception
     */
    public function __construct()
    {
        $this
            ->inject('message')
            ->inject('getProjectDB')
            ->inject('getLogsDB')
            ->inject('register')
            ->callback([$this, 'action']);
    }

    /**
     * @param Message $message
     * @param callable $getProjectDB
     * @param callable $getLogsDB
     * @param Registry $register
     * @return void
     * @throws Exception
     * @throws \Throwable
     * @throws \Utopia\Database\Exception
     */
    public function action(Message $message, callable $getProjectDB, callable $getLogsDB, Registry $register): void
    {
        $this->getLogsDB = $getLogsDB;
        $this->register = $register;
        $payload = $message->getPayload() ?? [];
        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        foreach ($payload['stats'] ?? [] as $stats) {
            $project = new Document($stats['project'] ?? []);
            $numberOfKeys = !empty($stats['keys']) ? count($stats['keys']) : 0;
            $receivedAt = $stats['receivedAt'] ?? null;
            if ($numberOfKeys === 0) {
                continue;
            }

            console::log('['.DateTime::now().'] Id: '.$project->getId(). ' InternalId: '.$project->getInternalId(). ' Db: '.$project->getAttribute('database').' ReceivedAt: '.$receivedAt. ' Keys: '.$numberOfKeys);

            try {
                foreach ($stats['keys'] ?? [] as $key => $value) {
                    if ($value == 0) {
                        continue;
                    }

                    foreach ($this->periods as $period => $format) {
                        $time = null;

                        if ($period !== 'inf') {
                            $time = !empty($receivedAt) ? (new \DateTime($receivedAt))->format($format) : date($format, time());
                        }
                        $id = \md5("{$time}_{$period}_{$key}");

                        $document = new Document([
                            '$id' => $id,
                            'period' => $period,
                            'time' => $time,
                            'metric' => $key,
                            'value' => $value,
                            'region' => System::getEnv('_APP_REGION', 'default'),
                        ]);


                        $this->projects[$project->getInternalId()]['project']  = new Document([
                            '$id' => $project->getId(),
                            '$internalId' => $project->getInternalId(),
                            'database' => $project->getAttribute('database'),
                        ]);
                        $this->projects[$project->getInternalId()]['stats'][] = $document;

                        $this->prepareForLogsDB($project, $document);
                    }
                }
            } catch (\Exception $e) {
                console::error('[' . DateTime::now() . '] project [' . $project->getInternalId() . '] database [' . $project['database'] . '] ' . ' ' . $e->getMessage());
            }
        }

        $batchSize = $this->getBatchSize();
        $shouldProcessBatch = \count($this->projects) >= $batchSize;
        if (!$shouldProcessBatch && \count($this->projects) > 0) {
            $shouldProcessBatch = (\time() - $this->lastDispatchTime) >= self::BATCH_AGGREGATION_INTERVAL;
        }

        if ($shouldProcessBatch || App::isDevelopment()) {
            foreach ($this->projects as $internalId => $projectStats) {
                if (empty($internalId)) {
                    continue;
                }
                try {
                    /** @var \Utopia\Database\Database $dbForProject */
                    $dbForProject = $getProjectDB($projectStats['project']);
                    Console::log('Processing batch with ' . count($projectStats['stats']) . ' stats');
                    $dbForProject->createOrUpdateDocumentsWithIncrease('stats', 'value', $projectStats['stats']);
                    Console::success('Batch successfully written to DB');

                    unset($this->projects[$internalId]);
                } catch (Throwable $e) {
                    Console::error('Error processing stats: ' . $e->getMessage());
                }
            }
            $this->lastDispatchTime = time();
        }
        $this->writeToLogsDB();

    }

    protected function prepareForLogsDB(Document $project, Document $stat)
    {
        if (System::getEnv('_APP_STATS_USAGE_DUAL_WRITING', 'disabled') === 'disabled') {
            return;
        }
        if (array_key_exists($stat->getAttribute('metric'), $this->skipBaseMetrics)) {
            return;
        }
        foreach ($this->skipParentIdMetrics as $skipMetric) {
            if (str_ends_with($stat->getAttribute('metric'), $skipMetric)) {
                return;
            }
        }
        $documentClone = new Document($stat->getArrayCopy());
        $documentClone->setAttribute('$tenant', (int) $project->getInternalId());
        $this->statDocuments[] = $documentClone;
    }

    protected function writeToLogsDB(): void
    {
        if (System::getEnv('_APP_STATS_USAGE_DUAL_WRITING', 'disabled') === 'disabled') {
            Console::log('Dual Writing is disabled. Skipping...');
            return;
        }

        $batchSize = $this->getBatchSize();
        $shouldProcessBatch = \count($this->statDocuments) >= $batchSize;
        if (!$shouldProcessBatch && \count($this->statDocuments) > 0) {
            $shouldProcessBatch = (\time() - $this->lastDispatchTimeLogsDB) >= self::BATCH_AGGREGATION_INTERVAL;
        }

        if (!$shouldProcessBatch) {
            return;
        }

        /** @var \Utopia\Database\Database $dbForLogs*/
        $dbForLogs = call_user_func($this->getLogsDB);
        $dbForLogs
            ->setTenant(null)
            ->setTenantPerDocument(true);

        try {
            Console::log('Processing batch with ' . count($this->statDocuments) . ' stats');
            $dbForLogs->createOrUpdateDocumentsWithIncrease(
                'stats',
                'value',
                $this->statDocuments
            );
            Console::success('Usage logs pushed to Logs DB');
        } catch (Throwable $th) {
            Console::error($th->getMessage());
        } finally {
            $this->lastDispatchTimeLogsDB = time();
        }

        $this->register->get('pools')->get('logs')->reclaim();
    }
}
