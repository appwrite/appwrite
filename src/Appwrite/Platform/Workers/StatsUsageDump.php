<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Extend\Exception;
use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Registry\Registry;
use Utopia\System\System;

/**
 * TODO remove later
 */
class StatsUsageDump extends Action
{
    public const METRIC_COLLECTION_LEVEL_STORAGE = 4;
    public const METRIC_DATABASE_LEVEL_STORAGE = 3;
    public const METRIC_PROJECT_LEVEL_STORAGE = 2;
    protected array $stats = [];

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
     * @var callable(Document): Database
     */
    protected $getLogsDB;

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
                /** @var Database $dbForProject */
                $dbForProject = $getProjectDB($project);
                foreach ($stats['keys'] ?? [] as $key => $value) {
                    if ($value == 0) {
                        continue;
                    }

                    if (str_contains($key, METRIC_DATABASES_STORAGE)) {
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

                        $documentClone = clone $document;

                        $dbForProject->createOrUpdateDocumentsWithIncrease(
                            'stats',
                            'value',
                            [$document]
                        );

                        $this->writeToLogsDB($project, $documentClone);
                    }
                }
            } catch (\Exception $e) {
                console::error('[' . DateTime::now() . '] project [' . $project->getInternalId() . '] database [' . $project['database'] . '] ' . ' ' . $e->getMessage());
            }
        }
    }

    protected function writeToLogsDB(Document $project, Document $document): void
    {
        if (System::getEnv('_APP_STATS_USAGE_DUAL_WRITING', 'disabled') === 'disabled') {
            Console::log('Dual Writing is disabled. Skipping...');
            return;
        }

        if (array_key_exists($document->getAttribute('metric'), $this->skipBaseMetrics)) {
            return;
        }
        foreach ($this->skipParentIdMetrics as $skipMetric) {
            if (str_ends_with($document->getAttribute('metric'), $skipMetric)) {
                return;
            }
        }

        $dbForLogs = ($this->getLogsDB)($project);

        try {
            $dbForLogs->createOrUpdateDocumentsWithIncrease(
                'stats',
                'value',
                [$document]
            );
            Console::success('Usage logs pushed to Logs DB');
        } catch (\Throwable $th) {
            Console::error($th->getMessage());
        }
    }
}
