<?php

namespace Appwrite\Platform\Workers;

use Exception;
use Utopia\App;
use Utopia\Audit\Audit;
use Utopia\Database\Database;
use Utopia\Database\Document;
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
            ->inject('polls')
            ->callback(fn ($message, $dbForProject, $pools) => $this->action($message, $dbForProject, $pools));
    }


    /**
     * @throws Exception
     */
    public function action(Message $message, $dbForProject): void
    {

        $payload = $message->getPayload() ?? [];
        var_dump('audits worker');
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


        $this->execute($event, $auditPayload, $mode, $resource, $userAgent, $ip, $user, $dbForProject);
    }

    private function execute(string $event, array $payload, string $mode, string $resource, string $userAgent, string $ip, Document $user, Database $dbForProject): void
    {

        $userName = $user->getAttribute('name', '');
        $userEmail = $user->getAttribute('email', '');

        $audit = new Audit($dbForProject);
        $audit->log(
            userInternalId: $user->getInternalId(),
            userId: $user->getId(),
            // Pass first, most verbose event pattern
            event: $event,
            resource: $resource,
            userAgent: $userAgent,
            ip: $ip,
            location: '',
            data: [
            'userName' => $userName,
            'userEmail' => $userEmail,
            'mode' => $mode,
            'data' => $payload,
            ]
        );
    }
}
