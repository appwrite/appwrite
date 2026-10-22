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
use Swoole\Coroutine;
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

        // A sibling message on this socket is mid-establish (a staked claim with no sequence yet). Wait
        // for it to resolve: it either publishes the row's sequence (it counted -- we follow without
        // counting) or its claim disappears because its write failed (we take over and count, so a live
        // socket is never left uncounted). Only the in-memory claim knows whether our own socket's
        // sibling counted; the database cannot. This wait blocks nothing but this coroutine.
        if (\is_array($hold) && ($hold['userId'] ?? null) === $userId && ($hold['sequence'] ?? null) === null) {
            for ($attempt = 1; $attempt <= 250; $attempt++) {
                Coroutine::sleep(0.002);
                if (!isset($realtime->connections[$connectionId])) {
                    break;
                }
                $hold = $realtime->connections[$connectionId]['presence'] ?? null;
                if (!\is_array($hold) || ($hold['userId'] ?? null) !== $userId || ($hold['sequence'] ?? null) !== null) {
                    break;
                }
            }
        }

        $heldForUser = \is_array($hold) && ($hold['userId'] ?? null) === $userId;

        if (!$heldForUser) {
            // First presence on this socket, or the establishing sibling failed: stake the claim and
            // count. Guard on the connection still existing -- onClose may have removed it during the
            // user lookup, and a bare assignment would auto-vivify a zombie entry the close guard trusts.
            if (isset($realtime->connections[$connectionId])) {
                $realtime->connections[$connectionId]['presence'] = ['projectId' => $projectId, 'userId' => $userId, 'sequence' => null];
            }
            $sequence = null;
            $follower = false;
        } else {
            // A sibling owns this socket's hold: refresh the row's data without counting. It records no
            // hold of its own, so it can never release a count it did not add.
            $sequence = $hold['sequence'];
            $follower = true;
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

        // onClose may have run during the upsert; only record the hold while the socket is open. A
        // follower never records a hold: it added no count, so the establishing sibling owns the socket's
        // single hold -- otherwise this message could decrement a row it never counted and delete a
        // presence another socket still holds.
        $closed = !isset($realtime->connections[$connectionId]);
        if (!$closed && !$follower) {
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
