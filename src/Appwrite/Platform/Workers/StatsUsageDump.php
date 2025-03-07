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

class StatsUsageDump extends Action
{
    protected array $stats = [];

    protected Registry $register;

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
                /** @var \Utopia\Database\Database $dbForProject */
                $dbForProject = $getProjectDB($project);
                $documents = [];
                $documentsClone = [];
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
                        $documents[] = $document;

                        $documentClone = new Document($document->getArrayCopy());
                        $documentsClone[] = $documentClone;
                    }
                }

                $dbForProject->createOrUpdateDocumentsWithIncrease(
                    'stats',
                    'value',
                    $documents
                );
                $this->writeToLogsDB($project, $documentsClone);
            } catch (\Exception $e) {
                console::error('[' . DateTime::now() . '] project [' . $project->getInternalId() . '] database [' . $project['database'] . '] ' . ' ' . $e->getMessage());
            }
        }
    }

    /**
     * Write to logs DB
     *
     * @param Document $project
     * @param array<Document> $documents
     * @return void
     */
    protected function writeToLogsDB(Document $project, array $documents): void
    {
        if (System::getEnv('_APP_STATS_USAGE_DUAL_WRITING', 'disabled') === 'disabled') {
            Console::log('Dual Writing is disabled. Skipping...');
            return;
        }

        /** @var \Utopia\Database\Database $dbForLogs*/
        $dbForLogs = call_user_func($this->getLogsDB, $project);

        try {
            $dbForLogs->createOrUpdateDocumentsWithIncrease(
                'stats',
                'value',
                $documents
            );
            Console::success('Usage logs pushed to Logs DB');
        } catch (\Throwable $th) {
            Console::error($th->getMessage());
        }

        $this->register->get('pools')->get('logs')->reclaim();
    }
}
