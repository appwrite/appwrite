<?php

namespace Appwrite\Platform\Workers;

use Exception;
use Throwable;
use Utopia\Audit\Audit;
use Utopia\CLI\Console;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Structure;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\System\System;

class Audits extends Action
{
    protected const BATCH_SIZE_DEVELOPMENT = 1; // smaller batch size for development
    protected const BATCH_SIZE_PRODUCTION = 5_000;
    protected const BATCH_AGGREGATION_INTERVAL = 60; // in seconds

    private int $lastTriggeredTime = 0;

    private array $logs = [];


    protected function getBatchSize(): int
    {
        return System::getEnv('_APP_ENV', 'development') === 'development'
            ? self::BATCH_SIZE_DEVELOPMENT
            : self::BATCH_SIZE_PRODUCTION;
    }

    public static function getName(): string
    {
        return 'audits';
    }

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this
            ->desc('Audits worker')
            ->inject('message')
            ->inject('getProjectDB')
            ->inject('project')
            ->callback([$this, 'action']);

        $this->lastTriggeredTime = time();
    }


    /**
     * @param Message $message
     * @param callable $getProjectDB
     * @param Document $project
     * @return void
     * @throws Throwable
     * @throws \Utopia\Database\Exception
     * @throws Authorization
     * @throws Structure
     */
    public function action(Message $message, callable $getProjectDB, Document $project): void
    {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        Console::info('Aggregating audit logs');

        $event = $payload['event'] ?? '';

        $auditPayload = '';
        if ($project->getId() === 'console') {
            $auditPayload = $payload['payload'] ?? '';
        }
        $mode = $payload['mode'] ?? '';
        $resource = $payload['resource'] ?? '';
        $userAgent = $payload['userAgent'] ?? '';
        $ip = $payload['ip'] ?? '';
        $user = new Document($payload['user'] ?? []);

        $userName = $user->getAttribute('name', '');
        $userEmail = $user->getAttribute('email', '');
        $userType = $user->getAttribute('type', ACTIVITY_TYPE_USER);

        // Create event data
        $eventData = [
            'userId' => $user->getInternalId(),
            'event' => $event,
            'resource' => $resource,
            'userAgent' => $userAgent,
            'ip' => $ip,
            'location' => '',
            'data' => [
                'userId' => $user->getId(),
                'userName' => $userName,
                'userEmail' => $userEmail,
                'userType' => $userType,
                'mode' => $mode,
                'data' => $auditPayload,
            ],
            'timestamp' => date("Y-m-d H:i:s", $message->getTimestamp()),
        ];

        if (isset($this->logs[$project->getInternalId()])) {
            $this->logs[$project->getInternalId()]['logs'][] = $eventData;
        } else {
            $this->logs[$project->getInternalId()] = [
                'project' => new Document([
                    '$id' => $project->getId(),
                    '$internalId' => $project->getInternalId(),
                    'database' => $project->getAttribute('database'),
                ]),
                'logs' => [$eventData]
            ];
        }

        // Check if we should process the batch by checking both for the batch size and the elapsed time
        $batchSize = $this->getBatchSize();
        $shouldProcessBatch = \count($this->logs) >= $batchSize;
        if (!$shouldProcessBatch && \count($this->logs) > 0) {
            $shouldProcessBatch = (\time() - $this->lastTriggeredTime) >= self::BATCH_AGGREGATION_INTERVAL;
        }

        if ($shouldProcessBatch) {
            try {
                foreach ($this->logs as $internalId => $projectLogs) {
                    $dbForProject = $getProjectDB($projectLogs['project']);

                    Console::log('Processing batch with ' . count($projectLogs['logs']) . ' events');
                    $audit = new Audit($dbForProject);

                    $audit->logBatch($projectLogs['logs']);
                    Console::success('Audit logs processed successfully');

                    unset($this->logs[$internalId]);
                }
            } catch (Throwable $e) {
                Console::error('Error processing audit logs: ' . $e->getMessage());
            } finally {
                $this->lastTriggeredTime = time();
            }
        }
    }
}
