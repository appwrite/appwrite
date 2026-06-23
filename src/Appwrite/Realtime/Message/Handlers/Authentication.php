<?php

namespace Appwrite\Realtime\Message\Handlers;

use Appwrite\Extend\Exception;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Realtime\Message\Dispatcher;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Auth\Hashes\Sha;
use Utopia\Auth\Proofs\Token;
use Utopia\Auth\Store;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Platform\Action;
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

        if (
            empty($user->getId())
            || !$user->sessionVerify($store->getProperty('secret', ''), $proofForToken)
        ) {
            throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'Session is not valid.');
        }

        $roles = $user->getRoles($database->getAuthorization());

        $authorization = $realtime->connections[$connectionId]['authorization'] ?? null;
        $impersonatedUserId = $realtime->connections[$connectionId]['impersonatedUserId'] ?? null;
        $projectId = $realtime->connections[$connectionId]['projectId'] ?? null;

        if ($impersonatedUserId !== null) {
            throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'Cannot re-authenticate on an impersonated connection.');
        }
        // Capture the pre-auth userId before unsubscribe() clears the connection entry,
        // so we can rebind any account channels that were stored under it.
        $previousUserId = $realtime->connections[$connectionId]['userId'] ?? '';

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
}
