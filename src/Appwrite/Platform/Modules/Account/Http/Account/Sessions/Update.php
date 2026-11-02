<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Client\Client;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateSession';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/account/sessions/:sessionId')
            ->desc('Update session')
            ->groups(['api', 'account'])
            ->label('scope', 'account')
            ->label('event', 'users.[userId].sessions.[sessionId].update')
            ->label('audits.event', 'session.update')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('audits.userId', '{response.userId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'sessions',
                name: 'updateSession',
                description: '/docs/references/account/update-session.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_SESSION,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->label('abuse-limit', 10)
            ->param('sessionId', 'current', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Session ID. Use the string \'current\' to update the current device session.', true, ['dbForProject'])
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('session')
            ->inject('clientForOAuth2')
            ->callback($this->action(...));
    }

    public function action(
        ?string $sessionId,
        Response $response,
        User $user,
        Database $dbForProject,
        Document $project,
        Event $queueForEvents,
        ?Document $current,
        Client $clientForOAuth2,
    ): void {
        $sessionId = ($sessionId === 'current')
            ? $current?->getId()
            : $sessionId;
        $sessions = $user->getAttribute('sessions', []);

        $session = null;
        foreach ($sessions as $key => $value) {
            if ($sessionId === $value->getId()) {
                $session = $value;
                break;
            }
        }

        if ($session === null) {
            throw new Exception(Exception::USER_SESSION_NOT_FOUND);
        }

        // Extend session
        $authDuration = $project->getAttribute('auths', [])['duration'] ?? TOKEN_EXPIRATION_LOGIN_LONG;
        $session->setAttribute('expire', DateTime::addSeconds(new \DateTime(), $authDuration));

        // Refresh OAuth access token
        $provider = $session->getAttribute('provider', '');
        $refreshToken = $session->getAttribute('providerRefreshToken', '');
        $oAuthProviders = Config::getParam('oAuthProviders') ?? [];
        $className = $oAuthProviders[$provider]['class'] ?? null;
        if (!empty($refreshToken) && ($className === null || !\class_exists($className))) {
            throw new Exception(Exception::PROJECT_PROVIDER_UNSUPPORTED);
        }

        // Only flows that request offline access come back with a refresh token;
        // native ID token sign-ins never do. Without one there is nothing to
        // exchange, and posting an empty refresh_token makes the provider fail
        // the request, so leave the stored credentials untouched.
        if (!empty($refreshToken) && $className !== null && \class_exists($className)) {
            $appId = $project->getAttribute('oAuthProviders', [])[$provider . 'Appid'] ?? '';
            $appSecret = $project->getAttribute('oAuthProviders', [])[$provider . 'Secret'] ?? '{}';

            $oauth2 = new $className($clientForOAuth2, $appId, $appSecret, '', [], []);
            $oauth2->refreshTokens($refreshToken);

            $session
                ->setAttribute('providerAccessToken', $oauth2->getAccessToken(''))
                ->setAttribute('providerRefreshToken', $oauth2->getRefreshToken(''))
                ->setAttribute('providerAccessTokenExpiry', DateTime::addSeconds(new \DateTime(), (int) $oauth2->getAccessTokenExpiry('')));

            try {
                $identity = $dbForProject->findOne('identities', [
                    Query::equal('provider', [$provider]),
                    Query::equal('providerUid', [$session->getAttribute('providerUid', '')]),
                ]);

                if (!$identity->isEmpty()) {
                    $dbForProject->updateDocument('identities', $identity->getId(), new Document([
                        'photo' => $oauth2->getUserPhoto($oauth2->getAccessToken('')),
                    ]));
                }
            } catch (\Throwable $th) {
                // Photo refresh is best-effort and must not block session token refresh
            }
        }

        // Save changes
        $dbForProject->updateDocument('sessions', $sessionId, $session);
        $dbForProject->purgeCachedDocument('users', $user->getId());

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('sessionId', $session->getId())
            ->setPayload($response->output($session, Response::MODEL_SESSION), sensitive: ['secret', 'providerAccessToken', 'providerRefreshToken'])
        ;

        $response->dynamic($session, Response::MODEL_SESSION);
    }
}
