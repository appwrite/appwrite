<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions;

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
use Utopia\Database\Validator\UID;
use Utopia\Locale\Locale;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteSession';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/account/sessions/:sessionId')
            ->desc('Delete session')
            ->groups(['api', 'account', 'mfa'])
            ->label('scope', 'account')
            ->label('impersonation', 'allow')
            ->label('event', 'users.[userId].sessions.[sessionId].delete')
            ->label('audits.event', 'session.delete')
            ->label('audits.resource', 'user/{user.$id}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'sessions',
                name: 'deleteSession',
                description: '/docs/references/account/delete-session.md',
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
            ->param('sessionId', 'current', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Session ID. Use the string \'current\' to delete the current device session.', true, ['dbForProject'])
            ->inject('requestTimestamp')
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
        ?string $sessionId,
        ?\DateTime $requestTimestamp,
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
        ?Document $current,
        Document $project,
        Mqtt $mqtt,
    ): void {
        $protocol = $request->getProtocol();
        $sessionId = ($sessionId === 'current')
            ? $current?->getId()
            : $sessionId;

        $sessions = $user->getAttribute('sessions', []);

        foreach ($sessions as $key => $session) {
            /** @var Document $session */
            if ($sessionId !== $session->getId()) {
                continue;
            }

            $dbForProject->deleteDocument('sessions', $session->getId());

            unset($sessions[$key]);

            $session->setAttribute('current', $session->getId() === $current?->getId());

            if ($session->getAttribute('current')) {
                $session->setAttribute('countryName', $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown')));
            }

            if ($proofForToken->verify($store->getProperty('secret', ''), $session->getAttribute('secret'))) { // If current session delete the cookies too
                if (!$domainVerification) {
                    $response->addHeader('X-Fallback-Cookies', \json_encode([]));
                }

                $response
                    ->addCookie($store->getKey() . '_legacy', '', \time() - 3600, '/', $cookieDomain, ('https' == $protocol), true, null)
                    ->addCookie($store->getKey(), '', \time() - 3600, '/', $cookieDomain, ('https' == $protocol), true, Config::getParam('cookieSamesite'));
            }

            $dbForProject->purgeCachedDocument('users', $user->getId());

            try {
                $mqtt->revoke($project->getId(), $user->getId());
            } catch (\Throwable $error) {
                Console::error('Failed to revoke MQTT connections: ' . $error->getMessage());
            }

            $queueForEvents
                ->setParam('userId', $user->getId())
                ->setParam('sessionId', $session->getId())
                ->setPayload($response->output($session, Response::MODEL_SESSION), sensitive: ['secret', 'providerAccessToken', 'providerRefreshToken']);

            $publisherForDeletes->enqueue(new DeleteMessage(
                project: $queueForEvents->getProject(),
                type: DELETE_TYPE_SESSION_TARGETS,
                document: $session,
            ));

            $response->noContent();
            return;
        }

        throw new Exception(Exception::USER_SESSION_NOT_FOUND);
    }
}
