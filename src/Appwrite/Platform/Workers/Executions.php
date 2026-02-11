<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Logs;
use Appwrite\Logs\Log;
use Appwrite\Logs\Resource;
use Appwrite\Logs\Method;
use Exception;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\System\System;

class Executions extends Action
{
    public static function getName(): string
    {
        return 'executions';
    }

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this
            ->desc('Executions worker')
            ->groups(['executions'])
            ->inject('message')
            ->inject('dbForProject')
            ->inject('logs')
            ->callback($this->action(...));
    }

    public function action(
        Message $message,
        Database $dbForProject,
        Logs $logs
    ): void {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $execution = new Document($payload['execution'] ?? []);

        if ($execution->isEmpty()) {
            throw new Exception('Missing execution');
        }

        if (System::getEnv('FEATURE_LOGS', 'enabled') === 'enabled') {
            $logs->append(new Log(
                // Meta
                resource: Resource::Deployment,
                resourceId: $execution->getAttribute('deploymentId'),
                timestamp: microtime(true),
                durationSeconds: $execution->getAttribute('duration'),

                // Request
                requestMethod: Method::tryFrom($execution->getAttribute('requestMethod')),
                requestScheme: '',
                requestHost: '',
                requestPath: $execution->getAttribute('requestPath'),
                requestQuery: '',
                requestSizeBytes: 0,

                // Response
                responseStatusCode: $execution->getAttribute('responseStatusCode'),
                responseSizeBytes: 0,
            ));


        } else {
            $dbForProject->upsertDocument('executions', $execution);
        }
    }
}
