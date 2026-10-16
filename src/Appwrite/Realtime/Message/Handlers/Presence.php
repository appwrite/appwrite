<?php

namespace Appwrite\Realtime\Message\Handlers;

use Appwrite\Event\Event as QueueEvent;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime as QueueRealtime;
use Appwrite\Extend\Exception;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Presences\State as PresenceState;
use Appwrite\Presences\Store as PresenceStore;
use Appwrite\Realtime\Message\Dispatcher;
use Appwrite\Utopia\Database\Documents\User;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\PermissionType;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Permissions;
use Utopia\Platform\Action;
use Utopia\Validator\JSON\ObjectValidator as JSONObject;
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
            ->param('metadata', null, new JSONObject(), 'Optional metadata payload', true, [], true)
            ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [PermissionType::Read, PermissionType::Update, PermissionType::Delete, PermissionType::Write]), 'An array of permissions strings. By default, only the current user is granted all permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('connectionId', '', new Text(256), 'Client connection ID that holds this presence; the SDK sets it per connection.', true)
            ->inject('connectionId')
            ->inject('realtime')
            ->inject('database')
            ->inject('authorization')
            ->inject('presenceState')
            ->inject('presenceStore')
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
        string $connectionId,
        int $socketId,
        Realtime $realtime,
        Database $database,
        Authorization $authorization,
        PresenceState $presenceState,
        PresenceStore $presenceStore,
        ?Document $project,
        UsagePublisher $publisherForUsage,
        QueueEvent $queueForEvents,
        QueueRealtime $queueForRealtime,
    ): array {
        if ($project === null || $project->isEmpty()) {
            throw new Exception(Exception::REALTIME_POLICY_VIOLATION, 'Presence requires a project context.');
        }

        $userId = $realtime->connections[$socketId]['userId'] ?? '';
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
            'expiresAt' => DateTime::format((new \DateTime())->modify('+15 minutes')),
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

        // The SDK sends a connection id per connection; fall back to a node-unique id so two
        // nodes' matching socket fds cannot collide into one holder.
        $holderId = $connectionId !== '' ? $connectionId : (\gethostname() ?: 'node') . ':' . $socketId;

        // If this connection already held under a different id (a second presence with another
        // id), release the old hold so it does not leak until the keep-alive lapses.
        $existing = $realtime->connections[$socketId]['presence'] ?? null;
        if ($existing !== null && ($existing['connectionId'] ?? '') !== $holderId) {
            $presenceStore->release((string) $existing['projectId'], (string) $existing['userId'], (string) $existing['connectionId']);
        }

        $presenceStore->hold($project->getId(), $userId, $holderId, [
            'status' => $status,
            'metadata' => $metadata,
        ]);

        // Stash the holder on the connection so onClose can release exactly this hold.
        $realtime->connections[$socketId]['presence'] = [
            'connectionId' => $holderId,
            'projectId' => $project->getId(),
            'userId' => $userId,
        ];

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
