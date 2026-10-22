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
        if ($presenceId !== 'unique()') {
            $presenceDocument->setAttribute('$id', $presenceId);
        }

        $projectId = $project->getId();

        // Decide this socket's count contribution in memory, synchronously -- a Swoole worker never
        // switches coroutines except on I/O, so staking the claim here (before the upsert yields) means
        // a concurrent presence message on the same socket sees it and does not count twice. No lock,
        // no DB inside one: the upsert below runs unlocked and the database serializes the write.
        $hold = $realtime->connections[$connectionId]['presence'] ?? null;
        $heldForUser = \is_array($hold) && ($hold['userId'] ?? null) === $userId;

        if (!$heldForUser) {
            // First presence on this socket: stake the claim now so a sibling message refreshes.
            $realtime->connections[$connectionId]['presence'] = ['projectId' => $projectId, 'userId' => $userId, 'sequence' => null];
            $sequence = null;
            $follower = false;
        } elseif (($hold['sequence'] ?? null) === null) {
            // A sibling message is still establishing the hold: refresh without counting.
            $sequence = null;
            $follower = true;
        } else {
            // Established hold: a status refresh, or a recount if the row was recreated (the state
            // method compares this sequence against the row).
            $sequence = $hold['sequence'];
            $follower = false;
        }

        try {
            $write = $presenceState->upsertForUserWithConnections($database, $presenceDocument, $userId, $sequence, $follower);
        } catch (\Throwable $th) {
            // Our claim never became a real hold; drop it so the socket can establish on a retry.
            if (!$heldForUser && ($realtime->connections[$connectionId]['presence']['sequence'] ?? false) === null) {
                unset($realtime->connections[$connectionId]['presence']);
            }
            throw $th;
        }

        $presence = $write['presence'];
        $created = $write['created'];
        $counted = $write['counted'];

        // onClose may have run during the upsert; only record the hold while the socket is open.
        $closed = !isset($realtime->connections[$connectionId]);
        if (!$closed) {
            $realtime->connections[$connectionId]['presence'] = [
                'projectId' => $projectId,
                'userId' => $userId,
                'sequence' => (string) $presence->getSequence(),
            ];
        }

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
