<?php

namespace Appwrite\Platform\Workers;

use Exception;
use Throwable;
use Utopia\Console;
use Utopia\Database\Document;
use Utopia\Database\Exception\Structure;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Queue\Result\Commit;
use Utopia\Queue\Result\NoCommit;
use Utopia\System\System;

class Audits extends Action
{
    protected const int BATCH_AGGREGATION_INTERVAL = 60; // in seconds

    private int $lastTriggeredTime = 0;

    private array $logs = [];


    protected function getBatchSize(): int
    {
        return intval(System::getEnv('_APP_QUEUE_PREFETCH_COUNT', 1));
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
            ->inject('project')
            ->inject('getAudit')
            ->callback($this->action(...));

        $this->lastTriggeredTime = time();
    }


    /**
     * @param Message $message
     * @param Document $project
     * @param callable(Document): \Utopia\Audit\Audit $getAudit
     * @return Commit|NoCommit
     * @throws Throwable
     * @throws \Utopia\Database\Exception
     * @throws Structure
     */
    public function action(Message $message, Document $project, callable $getAudit): Commit|NoCommit
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

        $impersonatorUserId = $user->getAttribute('impersonatorUserId');
        $actorUserId = $impersonatorUserId ?: $user->getId();
        $actorUserInternalId = $impersonatorUserId
            ? $user->getAttribute('impersonatorUserInternalId')
            : $user->getSequence();
        $actorUserName = $impersonatorUserId
            ? $user->getAttribute('impersonatorUserName', '')
            : $user->getAttribute('name', '');
        $actorUserEmail = $impersonatorUserId
            ? $user->getAttribute('impersonatorUserEmail', '')
            : $user->getAttribute('email', '');
        $userType = $user->getAttribute('type', ACTIVITY_TYPE_USER);

        // Create event data
        $eventData = [
            'userId' => $actorUserInternalId,
            'event' => $event,
            'resource' => $resource,
            'userAgent' => $userAgent,
            'ip' => $ip,
            'location' => '',
            'data' => [
                'userId' => $actorUserId,
                'userName' => $actorUserName,
                'userEmail' => $actorUserEmail,
                'userType' => $userType,
                'mode' => $mode,
                'data' => $auditPayload,
            ],
            'time' => date("Y-m-d H:i:s", $message->getTimestamp()),
        ];

        if (!empty($impersonatorUserId)) {
            $eventData['data']['data'] = \is_array($auditPayload)
                ? \array_merge($auditPayload, [
                    'impersonatedUserId' => $user->getId(),
                    'impersonatedUserName' => $user->getAttribute('name', ''),
                    'impersonatedUserEmail' => $user->getAttribute('email', ''),
                ])
                : [
                    'payload' => $auditPayload,
                    'impersonatedUserId' => $user->getId(),
                    'impersonatedUserName' => $user->getAttribute('name', ''),
                    'impersonatedUserEmail' => $user->getAttribute('email', ''),
                ];
        }

        if (isset($this->logs[$project->getSequence()])) {
            $this->logs[$project->getSequence()]['logs'][] = $eventData;
        } else {
            $this->logs[$project->getSequence()] = [
                'project' => new Document([
                    '$id' => $project->getId(),
                    '$sequence' => $project->getSequence(),
                    'database' => $project->getAttribute('database'),
                ]),
                'logs' => [$eventData]
            ];
        }

        // Check if we should process the batch by checking both for the batch size and the elapsed time
        $batchSize = $this->getBatchSize();
        $logCount = array_reduce($this->logs, fn (int $current, $logs) => $current + count($logs['logs']), 0);
        $shouldProcessBatch = $logCount >= $batchSize;
        if (!$shouldProcessBatch && $logCount > 0) {
            $shouldProcessBatch = (\time() - $this->lastTriggeredTime) >= self::BATCH_AGGREGATION_INTERVAL;
        }

        if (!$shouldProcessBatch) {
            return new NoCommit();
        }

        foreach ($this->logs as $sequence => $projectLogs) {
            try {
                Console::log('Processing Project "' . $sequence . '" batch with ' . count($projectLogs['logs']) . ' events');

                $projectDocument = $projectLogs['project'];
                $audit = $getAudit($projectDocument);
                $audit->logBatch($projectLogs['logs']);

                Console::success('Audit logs processed successfully');
            } catch (Throwable $e) {
                Console::error('Error processing audit logs for Project "' . $sequence . '": ' . $e->getMessage());
            } finally {
                unset($this->logs[$sequence]);
            }
        }

        $this->lastTriggeredTime = time();
        return new Commit();
    }
}
