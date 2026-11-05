<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Clients\ActiveSession;

use Appwrite\Abuse\Limit;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Auth\Proofs\Token as ProofsToken;
use Utopia\Auth\Store;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Locale\Locale;
use Utopia\Platform\Scope\HTTP;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateClientSession';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/account/clients/:clientId/active-session')
            ->desc('Update client active session')
            ->groups(['api', 'account'])
            ->label('scope', 'sessions.write')
            ->label('event', 'users.[userId].sessions.[sessionId].update')
            ->label('audits.event', 'client.updateSession')
            ->label('audits.resource', 'client/{request.clientId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'clients',
                name: 'updateClientSession',
                description: <<<EOT
                Switch the active account on an account switching client to one of the sessions it holds. The chosen session becomes active and its secret is written to the device's session cookie, so subsequent requests authenticate as that account. Use the string 'current' to reference the device's own client.

                Switching rotates the chosen session's secret. Any other holder of that session, such as a copy kept on another device, stops working, so a session should not be shared across clients.
                EOT,
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_SESSION,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->label('abuse', [Limit::attempts(['ip'], 100)])
            ->param('clientId', 'current', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Client ID. Use the string \'current\' to reference the current device client.', true, ['dbForProject'])
            ->param('sessionId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'ID of the session, held by the client, to make active.', false, ['dbForProject'])
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('client')
            ->inject('locale')
            ->inject('proofForToken')
            ->inject('domainVerification')
            ->inject('cookieDomain')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        ?string $clientId,
        string $sessionId,
        Request $request,
        Response $response,
        Document $project,
        Database $dbForProject,
        ?Document $client,
        Locale $locale,
        ProofsToken $proofForToken,
        bool $domainVerification,
        ?string $cookieDomain,
        Event $queueForEvents,
        Authorization $authorization,
    ): void {
        if ($client === null) {
            throw new Exception(Exception::CLIENT_NOT_FOUND);
        }

        if ($clientId !== 'current' && $clientId !== $client->getId()) {
            throw new Exception(Exception::CLIENT_NOT_FOUND);
        }

        $sessions = $client->getAttribute('sessions', []);
        $session = null;
        foreach ($sessions as $candidate) {
            /** @var Document $candidate */
            if ($candidate->getId() === $sessionId) {
                $session = $candidate;
                break;
            }
        }

        if ($session === null) {
            throw new Exception(Exception::CLIENT_SESSION_NOT_FOUND);
        }

        $protocol = $request->getProtocol();

        // Session secrets are hashed, so the original can never be put back into a cookie. Switching
        // mints a fresh secret, stores its hash, and hands the plaintext to the cookie below.
        $secret = $proofForToken->generate();

        $session = $authorization->skip(fn () => $dbForProject->updateDocument('sessions', $session->getId(), new Document([
            'secret' => $proofForToken->hash($secret),
        ])));

        $authorization->skip(fn () => $dbForProject->updateDocument('clients', $client->getId(), new Document([
            'activeSessionId' => $session->getId(),
            'activeSessionInternalId' => $session->getSequence(),
        ])));

        $dbForProject->purgeCachedDocument('users', $session->getAttribute('userId'));

        $store = new Store();
        $store
            ->setKey($project->getSessionKey())
            ->setProperty('id', $session->getAttribute('userId'))
            ->setProperty('secret', $secret);
        $encoded = $store->encode();

        if (!$domainVerification) {
            $response->addHeader('X-Fallback-Cookies', \json_encode([$store->getKey() => $encoded]));
        }

        $expire = (new \DateTime($session->getAttribute('expire')))->getTimestamp();

        $response
            ->addCookie($store->getKey() . '_legacy', $encoded, $expire, '/', $cookieDomain, ('https' == $protocol), true, null)
            ->addCookie($store->getKey(), $encoded, $expire, '/', $cookieDomain, ('https' == $protocol), true, Config::getParam('cookieSamesite'));

        $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

        $session
            ->setAttribute('current', true)
            ->setAttribute('countryName', $countryName)
            ->setAttribute('secret', $encoded)
            ->setAttribute('expire', DateTime::format(new \DateTime($session->getAttribute('expire'))));

        $queueForEvents
            ->setParam('userId', $session->getAttribute('userId'))
            ->setParam('sessionId', $session->getId())
            ->setPayload($response->output($session, Response::MODEL_SESSION), sensitive: ['secret', 'providerAccessToken', 'providerRefreshToken']);

        $response->dynamic($session, Response::MODEL_SESSION);
    }
}
