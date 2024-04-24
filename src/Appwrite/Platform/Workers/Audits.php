<?php

namespace Appwrite\Platform\Workers;

use Exception;
use Throwable;
use Utopia\Audit\Audit;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Validator\Authorization as ValidatorAuthorization;
use Utopia\Platform\Action;
use Utopia\Queue\Message;

class Audits extends Action
{
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
            ->inject('auth')
            ->callback(fn ($message, $dbForProject, ValidatorAuthorization $auth) => $this->action($message, $dbForProject, $auth));
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
    public function action(Message $message, Database $dbForProject, ValidatorAuthorization $auth): void
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

        $audit = new Audit($dbForProject, $auth);
        $audit->log(
            userId: $user->getInternalId(),
            // Pass first, most verbose event pattern
            event: $event,
            resource: $resource,
            userAgent: $userAgent,
            ip: $ip,
            location: '',
            data: [
                'userId' => $user->getId(),
                'userName' => $userName,
                'userEmail' => $userEmail,
                'mode' => $mode,
                'data' => $auditPayload,
            ]
        );
    }
}
