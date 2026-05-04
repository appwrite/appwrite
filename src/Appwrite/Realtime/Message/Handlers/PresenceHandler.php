<?php

namespace Appwrite\Realtime\Message\Handlers;

use Appwrite\Databases\PresenceState;
use Appwrite\Extend\Exception;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Realtime\Message\Dispatcher;
use Appwrite\Utopia\Database\Documents\User;
use Closure;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Validator\ArrayList;
use Utopia\Validator\JSON;
use Utopia\Validator\Text;
use Utopia\WebSocket\Server;

class PresenceHandler extends Action
{
    public function __construct()
    {
        $this
            ->desc('Upsert a presence document for the authenticated user')
            ->label(Dispatcher::LABEL_MESSAGE_TYPE, 'presence')
            ->param('status', '', new Text(2048), 'Presence status')
            ->param('presenceId', 'unique()', new Text(36), 'Presence document ID', true)
            ->param('metadata', null, new JSON(), 'Optional metadata payload', true, [], true)
            ->param('permissions', null, new ArrayList(new Text(2048)), 'Optional permissions list', true)
            ->inject('connection')
            ->inject('server')
            ->inject('realtime')
            ->inject('database')
            ->inject('authorization')
            ->inject('presenceState')
            ->inject('project')
            ->inject('triggerPresenceUsage')
            ->inject('triggerPresenceEvent')
            ->callback($this->action(...));
    }

    /**
     * @param array<int, string>|null $permissions
     * @param Closure(int, string): void $triggerPresenceUsage
     * @param Closure(Server, Realtime, ?Document, User, string, Document): void $triggerPresenceEvent
     * @return array<string, mixed>
     */
    public function action(
        string $status,
        string $presenceId,
        mixed $metadata,
        ?array $permissions,
        int $connection,
        Server $server,
        Realtime $realtime,
        Database $database,
        Authorization $authorization,
        PresenceState $presenceState,
        ?Document $project,
        Closure $triggerPresenceUsage,
        Closure $triggerPresenceEvent,
    ): array {
        $userId = $realtime->connections[$connection]['userId'] ?? '';
        if (empty($userId)) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'User must be authorized');
        }

        /** @var User $user */
        $user = $database->getDocument('users', $userId);
        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND, params: [$userId]);
        }

        $presenceData = [
            'userInternalId' => $user->getSequence(),
            'userId' => $user->getId(),
            'source' => 'realtime',
            'status' => $status,
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
            function () use ($project, $triggerPresenceUsage): void {
                if ($project !== null && !$project->isEmpty()) {
                    $triggerPresenceUsage(1, $project->getId());
                }
            },
        );

        $presence->removeAttribute('hostname');

        $realtime->connections[$connection]['presences'] = \array_merge(
            $realtime->connections[$connection]['presences'] ?? [],
            [$presence->getId()],
        );

        $triggerPresenceEvent($server, $realtime, $project, $user, 'presences.[presenceId].upsert', $presence);

        return [
            'type' => 'response',
            'data' => [
                'to' => 'presence',
                'presence' => $presence->getArrayCopy(),
            ],
        ];
    }
}
