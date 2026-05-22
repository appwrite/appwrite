<?php

namespace Appwrite\Realtime\Message\Handlers;

use Appwrite\Event\Event as QueueEvent;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime as QueueRealtime;
use Appwrite\Extend\Exception;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Presences\State as PresenceState;
use Appwrite\Realtime\Message\Dispatcher;
use Appwrite\Utopia\Database\Documents\User;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Permissions;
use Utopia\Platform\Action;
use Utopia\Validator\JSON;
use Utopia\Validator\Text;

class Presence extends Action
{
    public function __construct()
    {
        $this
            ->desc('Upsert a presence document for the authenticated user')
            ->label(Dispatcher::LABEL_MESSAGE_TYPE, 'presence')
            ->param('status', '', new Text(2048), 'Presence status')
            ->param('presenceId', 'unique()', new Text(36), 'Presence document ID', true)
            ->param('metadata', null, new JSON(), 'Optional metadata payload', true, [], true)
            ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE]), 'An array of permissions strings. By default, only the current user is granted all permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->inject('connectionId')
            ->inject('realtime')
            ->inject('database')
            ->inject('authorization')
            ->inject('presenceState')
            ->inject('project')
            ->inject('publisherForUsage')
            ->inject('queueForEvents')
            ->inject('queueForRealtime')
            ->callback($this->action(...));
    }

    /**
     * @param array<int, string>|null $permissions
     * @return array<string, mixed>
     */
    public function action(
        string $status,
        string $presenceId,
        mixed $metadata,
        ?array $permissions,
        int $connectionId,
        Realtime $realtime,
        Database $database,
        Authorization $authorization,
        PresenceState $presenceState,
        ?Document $project,
        UsagePublisher $publisherForUsage,
        QueueEvent $queueForEvents,
        QueueRealtime $queueForRealtime,
    ): array {
        if ($project === null || $project->isEmpty()) {
            throw new Exception(Exception::REALTIME_POLICY_VIOLATION, 'Presence requires a project context.');
        }

        $userId = $realtime->connections[$connectionId]['userId'] ?? '';
        if (empty($userId)) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'User must be authorized');
        }

        $user = new User($database->getDocument('users', $userId)->getArrayCopy());
        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND, params: [$userId]);
        }

        $presenceData = [
            'userInternalId' => $user->getSequence(),
            'userId' => $user->getId(),
            'source' => 'realtime',
            'status' => $status,
            'expiresAt' => DateTime::format((new \DateTime())->modify('+30 days')),
            'hostname' => \gethostname() ?: null,
        ];
        if ($metadata !== null) {
            $presenceData['metadata'] = $metadata;
        }

        $presenceDocument = new Document($presenceData);
        $presenceState->setPermissions($presenceDocument, $permissions, $user, $authorization);

        $presence = $presenceState->upsertForUser(
            $database,
            $presenceDocument,
            $presenceId,
            (string) $user->getSequence(),
            function () use ($presenceState, $publisherForUsage, $project): void {
                $presenceState->triggerUsage($publisherForUsage, $project, 1);
            },
        );

        $presence->removeAttribute('$collection');
        $presence->removeAttribute('$tenant');
        $presence->removeAttribute('hostname');
        $presence->removeAttribute('permissionsHash');
        $presence->removeAttribute('userInternalId');

        $realtime->connections[$connectionId]['presences'][$presence->getId()] = $presence;

        $presenceState->triggerEvent(
            $queueForEvents,
            $queueForRealtime,
            $project,
            $user,
            'presences.[presenceId].upsert',
            $presence,
        );

        return [
            'type' => 'response',
            'data' => [
                'to' => 'presence',
                'presence' => $presence->getArrayCopy(),
            ],
        ];
    }
}
