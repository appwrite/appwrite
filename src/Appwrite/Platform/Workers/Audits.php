<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Message\Audit;
use Exception;
use Throwable;
use Utopia\Database\Document;
use Utopia\Database\Exception\Structure;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Span\Span;
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
            ->inject('getAudit')
            ->callback($this->action(...));

        $this->lastTriggeredTime = time();
    }


    /**
     * @param Message $message
     * @param callable(Document): \Utopia\Audit\Audit $getAudit
     * @throws Throwable
     * @throws \Utopia\Database\Exception
     * @throws Structure
     */
    public function action(Message $message, callable $getAudit): void
    {
        $payload = $message->getPayload();

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $auditMessage = Audit::fromArray($payload);

        $event = $auditMessage->event;

        $auditPayload = '';
        if ($auditMessage->project->getId() === 'console') {
            $auditPayload = $auditMessage->payload;
        }
        $mode = $auditMessage->mode;
        $resource = $auditMessage->resource;
        $userAgent = $auditMessage->userAgent;
        $ip = $auditMessage->ip;
        $user = $auditMessage->user;
        $impersonatorUser = $auditMessage->impersonatorUser;

        $isImpersonated = !$impersonatorUser->isEmpty();
        $actorUserId = $isImpersonated ? $impersonatorUser->getId() : $user->getId();
        $actorUserInternalId = $isImpersonated ? $impersonatorUser->getSequence() : $user->getSequence();
        $actorUserName = $isImpersonated ? $impersonatorUser->getAttribute('name', '') : $user->getAttribute('name', '');
        $actorUserEmail = $isImpersonated ? $impersonatorUser->getAttribute('email', '') : $user->getAttribute('email', '');
        $userType = $isImpersonated ? $impersonatorUser->getAttribute('type', ACTOR_TYPE_USER) : $user->getAttribute('type', ACTOR_TYPE_USER);

        // Create event data
        $eventData = [
            'userId' => $actorUserInternalId,
            'event' => $event,
            'resource' => $resource,
            'userAgent' => $userAgent,
            'ip' => $ip,
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

        if ($isImpersonated) {
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

        $projectSequence = (string) $auditMessage->project->getSequence();
        if (isset($this->logs[$projectSequence])) {
            $this->logs[$projectSequence]['logs'][] = $eventData;
        } else {
            $this->logs[$projectSequence] = [
                'project' => new Document([
                    '$id' => $auditMessage->project->getId(),
                    '$sequence' => $auditMessage->project->getSequence(),
                    'database' => $auditMessage->project->getAttribute('database'),
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
            return;
        }

        $projects = 0;
        $events = 0;
        foreach ($this->logs as $sequence => $projectLogs) {
            try {
                $audit = $getAudit($projectLogs['project']);
                $audit->logBatch($projectLogs['logs']);

                $projects++;
                $events += count($projectLogs['logs']);
            } catch (Throwable $e) {
                Span::add('audits.error', $e->getMessage());
            } finally {
                unset($this->logs[$sequence]);
            }
        }

        Span::add('audits.projects', $projects);
        Span::add('audits.count', $events);

        $this->lastTriggeredTime = time();
    }
}
