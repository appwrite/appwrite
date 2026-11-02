<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions\Bulk;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Extend\Exception;
use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Auth\Proofs\Token as ProofsToken;
use Utopia\Auth\Store;
use Utopia\Config\Config;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Locale\Locale;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteSessions';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/account/sessions')
            ->desc('Delete sessions')
            ->groups(['api', 'account'])
            ->label('scope', 'account')
            ->label('event', 'users.[userId].sessions.[sessionId].delete')
            ->label('audits.event', 'session.delete')
            ->label('audits.resource', 'user/{user.$id}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'sessions',
                name: 'deleteSessions',
                description: '/docs/references/account/delete-sessions.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->label('abuse-limit', 100)
            ->param('current', true, new Boolean(), 'Delete the current session too. Use false to sign out of every other session while staying signed in on this one.', true)
            ->inject('request')
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('locale')
            ->inject('queueForEvents')
            ->inject('publisherForDeletes')
            ->inject('store')
            ->inject('proofForToken')
            ->inject('domainVerification')
            ->inject('cookieDomain')
            ->inject('session')
            ->inject('project')
            ->inject('mqtt')
            ->callback($this->action(...));
    }

    public function action(
        bool $current,
        Request $request,
        Response $response,
        User $user,
        Database $dbForProject,
        Locale $locale,
        Event $queueForEvents,
        DeletePublisher $publisherForDeletes,
        Store $store,
        ProofsToken $proofForToken,
        bool $domainVerification,
        ?string $cookieDomain,
        ?Document $callingSession,
        Document $project,
        Mqtt $mqtt,
    ): void {
        // Nothing to keep (e.g. account API key), so refuse rather than delete every session.
        if (!$current && $callingSession === null) {
            throw new Exception(Exception::USER_SESSION_NOT_FOUND);
        }

        $protocol = $request->getProtocol();
        $sessions = $user->getAttribute('sessions', []);
        $currentSession = null;

        foreach ($sessions as $session) {
            /** @var Document $session */
            if (!$current && $session->getId() === $callingSession->getId()) {
                continue;
            }

            $dbForProject->deleteDocument('sessions', $session->getId());

            // Clears the caller's fallback cookie, so only when its own session goes too.
            if (!$domainVerification && $current) {
                $response->addHeader('X-Fallback-Cookies', \json_encode([]));
            }

            $session
                ->setAttribute('current', false)
                ->setAttribute('countryName', $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown')));

            if ($proofForToken->verify($store->getProperty('secret', ''), $session->getAttribute('secret'))) {
                $session->setAttribute('current', true);

                // If current session delete the cookies too
                $response
                    ->addCookie($store->getKey() . '_legacy', '', \time() - 3600, '/', $cookieDomain, ('https' == $protocol), true, null)
                    ->addCookie($store->getKey(), '', \time() - 3600, '/', $cookieDomain, ('https' == $protocol), true, Config::getParam('cookieSamesite'));

                // Use current session for events.
                $currentSession = $session;
                $queueForEvents
                    ->setPayload($response->output($session, Response::MODEL_SESSION), sensitive: ['secret', 'providerAccessToken', 'providerRefreshToken']);

                $publisherForDeletes->enqueue(new DeleteMessage(
                    project: $queueForEvents->getProject(),
                    type: DELETE_TYPE_SESSION_TARGETS,
                    document: $session,
                ));
            }
        }

        $dbForProject->purgeCachedDocument('users', $user->getId());

        try {
            $mqtt->revoke($project->getId(), $user->getId());
        } catch (\Throwable $error) {
            Console::error('Failed to revoke MQTT connections: ' . $error->getMessage());
        }

        if ($currentSession instanceof Document) {
            $queueForEvents
                ->setParam('userId', $user->getId())
                ->setParam('sessionId', $currentSession->getId());
        } else {
            $queueForEvents->reset();
        }

        $response->noContent();
    }
}
