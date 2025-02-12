<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Auth\Auth;
use Exception;
use Throwable;
use Utopia\Audit\Audit;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Structure;
use Utopia\Platform\Action;
use Utopia\Queue\Message;

class Audits extends Action
{
    private const BATCH_SIZE = 5_000;
    private const BATCH_TIME_WINDOW = 60;

    private static array $pendingEvents = [];

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
            'timestamp' => time()
        ];

        self::$pendingEvents[] = $eventData;

        // Check if we should process the batch by checking both for the batch size and the elapsed time
        $shouldProcessBatch = count(self::$pendingEvents) >= self::BATCH_SIZE;
        if (!$shouldProcessBatch && count(self::$pendingEvents) > 0) {
            $oldestEventTime = self::$pendingEvents[0]['timestamp'];
            $shouldProcessBatch = (time() - $oldestEventTime) >= self::BATCH_TIME_WINDOW;
        }

        if ($shouldProcessBatch) {
            $audit = new Audit($dbForProject);
            $batchEvents = array_map(function($event) {
                return [
                    'userId' => $event['userId'],
                    'event' => $event['event'],
                    'resource' => $event['resource'],
                    'userAgent' => $event['userAgent'],
                    'ip' => $event['ip'],
                    'location' => $event['location'],
                    'data' => $event['data'],
                    'timestamp' => $event['timestamp']
                ];
            }, self::$pendingEvents);

            $audit->logByBatch($batchEvents);
            
            // Clear the pending events after successful batch processing
            self::$pendingEvents = [];
        }
    }
}
