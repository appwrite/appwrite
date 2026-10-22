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
use Utopia\Database\PermissionType;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Permissions;
use Utopia\Lock\Striped;
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
            ->inject('connectionId')
            ->inject('realtime')
            ->inject('database')
            ->inject('authorization')
            ->inject('presenceState')
            ->inject('presenceLock')
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
        Striped $presenceLock,
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
        if ($presenceId !== 'unique()') {
            $presenceDocument->setAttribute('$id', $presenceId);
        }

        $projectId = $project->getId();

        // Lock only the read-upsert-stash section per socket: two overlapping presence messages would
        // otherwise both read no hold and both count, while onClose releases once (the socket stays
        // online after it disconnects). The user fetch above and the events below stay outside the
        // lock. A negative timeout waits indefinitely, so the write always runs.
        $result = $presenceLock->withLock($connectionId, function () use ($realtime, $connectionId, $userId, $projectId, $database, $presenceState, $presenceDocument): array {
            // Row this socket already counts against; matching its sequence means a status refresh,
            // not a new hold. Sequence (not id) so a same-id delete-and-recreate is seen as new.
            $stash = $realtime->connections[$connectionId]['presence'] ?? null;
            $sequence = (\is_array($stash) && ($stash['userId'] ?? null) === $userId)
                ? ($stash['sequence'] ?? null)
                : null;

            $write = $presenceState->upsertForUserWithConnections($database, $presenceDocument, $userId, $sequence);
            $presence = $write['presence'];

            // onClose may have run during the upsert; only record the hold while the socket is open.
            $closed = !isset($realtime->connections[$connectionId]);
            if (!$closed) {
                $realtime->connections[$connectionId]['presence'] = [
                    'projectId' => $projectId,
                    'userId' => $userId,
                    'sequence' => (string) $presence->getSequence(),
                ];
            }

            return ['presence' => $presence, 'created' => $write['created'], 'counted' => $write['counted'], 'closed' => $closed];
        }, -1.0);

        /** @var Document $presence */
        $presence = $result['presence'];
        $created = $result['created'];
        $counted = $result['counted'];
        $closed = $result['closed'];

        foreach (['$collection', '$tenant', 'hostname', 'permissionsHash', 'userInternalId', 'connections'] as $attribute) {
            $presence->removeAttribute($attribute);
        }

        // Socket closed mid-write: undo any count we added, announcing offline if that emptied the row.
        if ($closed) {
            $deleted = $counted
                ? $presenceState->releaseConnectionForUser($database, $userId, (string) $presence->getSequence())
                : null;

            if ($deleted !== null) {
                // created-then-deleted nets zero usage; otherwise undo the creator's +1.
                $presenceState->triggerPresenceDeleted($database, $project, $userId, $deleted, $publisherForUsage, $queueForEvents, $queueForRealtime, triggerUsage: !$created);
            } elseif ($created) {
                // Created row survives under another socket's hold: count the online transition here.
                $presenceState->triggerUsage($publisherForUsage, $project, 1);
            }

            return [
                'type' => 'response',
                'data' => [
                    'to' => 'presence',
                    'presence' => $presence->getArrayCopy(),
                ],
            ];
        }

        // Usage +1 only on actual creation (user came online), not on an increment.
        if ($created) {
            $presenceState->triggerUsage($publisherForUsage, $project, 1);
        }

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
