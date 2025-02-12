<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Auth\Auth;
use Exception;
use Throwable;
use Utopia\Audit\Audit;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Structure;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\System\System;

class Audits extends Action
{
    private const BATCH_SIZE_DEVELOPMENT = 1; // smaller batch size for development
    private const BATCH_SIZE_PRODUCTION = 5_000;
    private const BATCH_AGGREGATION_INTERVAL = 60; // in seconds

    private static array $logs = [];

    private function getBatchSize(): int
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
            ->inject('dbForProject')
            ->callback(fn ($message, $dbForProject) => $this->action($message, $dbForProject));
    }


    /**
     * @param Message $message
     * @param Database $dbForProject
     * @return void
     * @throws Throwable
     * @throws \Utopia\Database\Exception
     * @throws Authorization
     * @throws Structure
     */
    public function action(Message $message, Database $dbForProject): void
    {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        Console::info('Aggregating audit logs');

        $event = $payload['event'] ?? '';
        $auditPayload = $payload['payload'] ?? '';
        $mode = $payload['mode'] ?? '';
        $resource = $payload['resource'] ?? '';
        $userAgent = $payload['userAgent'] ?? '';
        $ip = $payload['ip'] ?? '';
        $user = new Document($payload['user'] ?? []);

        $userName = $user->getAttribute('name', '');
        $userEmail = $user->getAttribute('email', '');
        $userType = $user->getAttribute('type', Auth::ACTIVITY_TYPE_USER);

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
            'timestamp' => DateTime::formatTz(DateTime::now())
        ];

        self::$logs[] = $eventData;

        // Check if we should process the batch by checking both for the batch size and the elapsed time
        $batchSize = $this->getBatchSize();
        $shouldProcessBatch = count(self::$logs) >= $batchSize;
        if (!$shouldProcessBatch && count(self::$logs) > 0) {
            $oldestEventTime = self::$logs[0]['timestamp'];
            $shouldProcessBatch = (time() - $oldestEventTime) >= self::BATCH_AGGREGATION_INTERVAL;
        }

        if ($shouldProcessBatch) {
            Console::log('Processing batch with ' . count(self::$logs) . ' events');

            $audit = new Audit($dbForProject);

            try {
                $audit->logBatch(self::$logs);
                Console::success('Audit logs processed successfully');
            } catch (Throwable $e) {
                Console::error('Error processing audit logs: ' . $e->getMessage());
            } finally {
                // Clear the pending events after successful batch processing
                self::$logs = [];
            }
        }
    }
}
