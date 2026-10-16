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

        // Bump the row's worker count only on this worker's first hold for the user (0->1).
        $alreadyHeld = isset($realtime->connections[$connectionId]['presence']);
        $firstWorkerHold = !$alreadyHeld && empty($realtime->presenceHolders[$projectId][$userId] ?? []);

        // Reserve the hold synchronously (no coroutine yield between the check and this set) so a
        // concurrent first message on this worker sees it and does not also count as firstWorkerHold.
        if (!$alreadyHeld) {
            $realtime->presenceHolders[$projectId][$userId][$connectionId] = true;
        }

        $created = false;
        try {
            $presence = $presenceState->recordPresence($database, $presenceDocument, $userId, $firstWorkerHold, $created);
        } catch (\Throwable $th) {
            // Roll back only our own reservation (still `true`); a concurrent message on this
            // connection that already succeeded has overwritten it with a row id -- leave that.
            if (!$alreadyHeld && ($realtime->presenceHolders[$projectId][$userId][$connectionId] ?? null) === true) {
                unset($realtime->presenceHolders[$projectId][$userId][$connectionId]);
                if (empty($realtime->presenceHolders[$projectId][$userId] ?? [])) {
                    unset($realtime->presenceHolders[$projectId][$userId]);
                }
            }
            throw $th;
        }

        // The socket can close during the write; onClose then runs before the hold is tagged and
        // can't release it. Undo our worker count, and if that deleted the row (last holder),
        // announce offline instead of the online upsert below -- never resurrect a dead hold.
        if (!isset($realtime->connections[$connectionId])) {
            $deleted = ($created || $firstWorkerHold)
                ? $presenceState->releaseHolder($database, $userId, $presence->getId())
                : null;

            if (!$alreadyHeld) {
                unset($realtime->presenceHolders[$projectId][$userId][$connectionId]);
                if (empty($realtime->presenceHolders[$projectId][$userId] ?? [])) {
                    unset($realtime->presenceHolders[$projectId][$userId]);
                }
            }

            if ($deleted !== null) {
                $presenceState->purgeListCache($database);
                // A row this message created and then deleted nets zero usage; otherwise undo the
                // +1 its original creator counted for the now-offline user.
                if (!$created) {
                    $presenceState->triggerUsage($publisherForUsage, $project, -1);
                }
                foreach (['$collection', '$tenant', 'hostname', 'permissionsHash', 'userInternalId', 'connections'] as $attribute) {
                    $deleted->removeAttribute($attribute);
                }
                $presenceState->triggerEvent($queueForEvents, $queueForRealtime, $project, $user, 'presences.[presenceId].delete', $deleted);
            }

            foreach (['$collection', '$tenant', 'hostname', 'permissionsHash', 'userInternalId', 'connections'] as $attribute) {
                $presence->removeAttribute($attribute);
            }

            return [
                'type' => 'response',
                'data' => [
                    'to' => 'presence',
                    'presence' => $presence->getArrayCopy(),
                ],
            ];
        }

        // Tag the hold with the row id, so a delete event for a superseded row only clears holds
        // on that row, not a recreated one.
        $realtime->presenceHolders[$projectId][$userId][$connectionId] = $presence->getId();
        $realtime->connections[$connectionId]['presence'] = [
            'projectId' => $projectId,
            'userId' => $userId,
            'presenceId' => $presence->getId(),
        ];

        // Count usage only on the actual row creation (the user came online), never on an
        // increment of a row an HTTP upsert already counted.
        if ($created) {
            $presenceState->triggerUsage($publisherForUsage, $project, 1);
        }

        $presence->removeAttribute('$collection');
        $presence->removeAttribute('$tenant');
        $presence->removeAttribute('hostname');
        $presence->removeAttribute('permissionsHash');
        $presence->removeAttribute('userInternalId');
        $presence->removeAttribute('connections');

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
