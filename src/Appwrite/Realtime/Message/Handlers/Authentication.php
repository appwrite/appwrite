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
use Appwrite\Utopia\Response;
use Utopia\Auth\Hashes\Sha;
use Utopia\Auth\Proofs\Token;
use Utopia\Auth\Store;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Pools\Group;
use Utopia\Registry\Registry;
use Utopia\Span\Span;
use Utopia\Validator\Text;

class Authentication extends Action
{
    public function __construct()
    {
        $this
            ->desc('Authenticate the connection with a session token')
            ->label(Dispatcher::LABEL_MESSAGE_TYPE, 'authentication')
            ->param('session', '', new Text(2048), 'Encoded session token')
            ->inject('connectionId')
            ->inject('realtime')
            ->inject('database')
            ->inject('register')
            ->inject('response')
            ->inject('presenceState')
            ->inject('pools')
            ->inject('project')
            ->inject('publisherForUsage')
            ->inject('queueForEvents')
            ->inject('queueForRealtime')
            ->callback($this->action(...));
    }

    /**
     * @return array<string, mixed>
     */
    public function action(
        string $session,
        int $connectionId,
        Realtime $realtime,
        Database $database,
        Registry $register,
        Response $response,
        PresenceState $presenceState,
        Group $pools,
        ?Document $project,
        UsagePublisher $publisherForUsage,
        QueueEvent $queueForEvents,
        QueueRealtime $queueForRealtime,
    ): array {
        $store = new Store();
        $store->decode($session);

        $userId = $store->getProperty('id', '');
        if ($userId !== '') {
            $database->purgeCachedDocument('users', $userId);
        }

        /** @var User $user */
        $user = $database->getDocument('users', $userId);

        // TODO: move proof construction to the DI container so there's one source of truth.
        $proofForToken = new Token();
        $proofForToken->setHash(new Sha());

        $sessionId = $user->sessionVerify($store->getProperty('secret', ''), $proofForToken);

        if (empty($user->getId()) || $sessionId === false) {
            throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'Session is not valid.');
        }

        $roles = $user->getRoles($database->getAuthorization());

        $authorization = $realtime->connections[$connectionId]['authorization'] ?? null;
        $impersonatedUserId = $realtime->connections[$connectionId]['impersonatedUserId'] ?? null;
        $projectId = $realtime->connections[$connectionId]['projectId'] ?? null;

        if ($impersonatedUserId !== null) {
            throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'Cannot re-authenticate on an impersonated connection.');
        }
        // Capture the pre-auth userId and presence holds before unsubscribe() clears the
        // connection entry, so we can rebind account channels and carry or release the holds.
        $previousUserId = $realtime->connections[$connectionId]['userId'] ?? '';
        $presences = $realtime->connections[$connectionId]['presences'] ?? [];

        $subscriptionsBefore = \count($realtime->getSubscriptionMetadata($connectionId));
        $meta = $realtime->getSubscriptionMetadata($connectionId);

        $realtime->unsubscribe($connectionId);

        if (!empty($projectId)) {
            foreach ($meta as $subscriptionId => $subscription) {
                $queries = Query::parseQueries($subscription['queries'] ?? []);
                $channels = Realtime::rebindAccountChannels(
                    $subscription['channels'] ?? [],
                    $previousUserId,
                    $user->getId(),
                );

                $realtime->subscribe(
                    $projectId,
                    $connectionId,
                    $subscriptionId,
                    $roles,
                    $channels,
                    $queries,
                    $user->getId(),
                );
            }
        }

        if ($authorization !== null) {
            $realtime->connections[$connectionId]['authorization'] = $authorization;
            $realtime->connections[$connectionId]['impersonatedUserId'] = $impersonatedUserId;
            $realtime->connections[$connectionId]['sessionId'] = $sessionId;
            $realtime->connections[$connectionId]['expire'] = $user->getSessionExpiry($sessionId);
            // Now authenticated by this session, not by any JWT it was opened with.
            $realtime->connections[$connectionId]['jwtExpire'] = null;
        }

        // The socket is unchanged, so its presence holds carry across a re-auth to the same
        // user; a re-auth to a different user releases the previous user's holds.
        if (!empty($presences) && !empty($projectId) && $projectId !== 'console') {
            if ($previousUserId === $user->getId()) {
                if (isset($realtime->connections[$connectionId])) {
                    $realtime->connections[$connectionId]['presences'] = $presences;
                }
            } else {
                $this->releasePreviousHolds(
                    $pools,
                    $presenceState,
                    $database,
                    $publisherForUsage,
                    $queueForEvents,
                    $queueForRealtime,
                    $project,
                    (string) $projectId,
                    (string) $previousUserId,
                    \array_keys($presences),
                );
            }
        }

        $subscriptionsAfter = \count($realtime->getSubscriptionMetadata($connectionId));
        $subscriptionDelta = $subscriptionsAfter - $subscriptionsBefore;
        if ($subscriptionDelta !== 0) {
            $register->get('telemetry.workerSubscriptionCounter')
                ->add($subscriptionDelta, $register->get('telemetry.workerAttributes'));
        }

        Span::add('realtime.subscription_delta', $subscriptionDelta);

        return [
            'type' => 'response',
            'data' => [
                'to' => 'authentication',
                'success' => true,
                'user' => $response->output($user, Response::MODEL_ACCOUNT),
            ],
        ];
    }

    /**
     * @param  array<int, string>  $presenceIds
     */
    private function releasePreviousHolds(
        Group $pools,
        PresenceState $presenceState,
        Database $database,
        UsagePublisher $publisherForUsage,
        QueueEvent $queueForEvents,
        QueueRealtime $queueForRealtime,
        ?Document $project,
        string $projectId,
        string $previousUserId,
        array $presenceIds,
    ): void {
        if ($project === null || $project->isEmpty() || $previousUserId === '') {
            return;
        }

        $deleted = $presenceState->releaseConnection($pools, $database, $projectId, $previousUserId, $presenceIds);
        if (empty($deleted)) {
            return;
        }

        $presenceState->triggerUsage($publisherForUsage, $project, -\count($deleted));

        $previousUser = new User($database->getAuthorization()->skip(
            fn () => $database->getDocument('users', $previousUserId)
        )->getArrayCopy());

        foreach ($deleted as $presence) {
            $presenceState->triggerEvent(
                $queueForEvents,
                $queueForRealtime,
                $project,
                $previousUser,
                'presences.[presenceId].delete',
                $presence,
            );
        }
    }
}
