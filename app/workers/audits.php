<?php

require_once __DIR__ . '/../worker.php';

use Utopia\Audit\Audit;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Queue\Message;
use Utopia\Queue\Server;

Authorization::disable();
Authorization::setDefaultStatus(false);

Server::setResource('execute', function (Database $dbForProject) {
    return function (
        string $event,
        array $payload,
        string $mode,
        string $resource,
        string $userAgent,
        string $ip,
        Document $user,
        Document $project
    ) use ($dbForProject) {
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
    };
}, ['dbForProject']);

$server->job()
    ->inject('message')
    ->inject('dbForProject')
    ->inject('execute')
    ->action(function (Message $message, callable $execute) {
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
        $project = new Document($payload['project'] ?? []);
        $user = new Document($payload['user'] ?? []);

        $execute(
            $event,
            $auditPayload,
            $mode,
            $resource,
            $userAgent,
            $ip,
            $user,
            $project
        );
    });

$server->workerStart();
$server->start();
