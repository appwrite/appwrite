<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Devices\Sessions;

use Appwrite\Abuse\Limit;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\Project;
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
        return 'updateDeviceSession';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/account/devices/:deviceId/session')
            ->desc('Update device session')
            ->groups(['api', 'account'])
            ->label('scope', 'sessions.write')
            ->label('event', 'users.[userId].sessions.[sessionId].update')
            ->label('audits.event', 'device.session.update')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('audits.userId', '{response.userId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'devices',
                name: 'updateDeviceSession',
                description: <<<EOT
                Switch the active account on an account switching device to one of the sessions it holds. The chosen session becomes active and its secret is written to the browser's session cookie, so subsequent requests authenticate as that account. Use the string 'current' to reference the current browser's device.

                Switching rotates the chosen session's secret. Any other holder of that session, such as a copy kept elsewhere, stops working, so a session should not be shared across devices.
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
            ->param('deviceId', 'current', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Device ID. Use the string \'current\' to reference the device of the current browser.', true, ['dbForProject'])
            ->param('sessionId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'ID of the session, held by the device, to make active.', false, ['dbForProject'])
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('authDevice')
            ->inject('locale')
            ->inject('proofForToken')
            ->inject('domainVerification')
            ->inject('cookieDomain')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        ?string $deviceId,
        string $sessionId,
        Request $request,
        Response $response,
        Project $project,
        Database $dbForProject,
        ?Document $authDevice,
        Locale $locale,
        ProofsToken $proofForToken,
        bool $domainVerification,
        ?string $cookieDomain,
        Event $queueForEvents,
        Authorization $authorization,
    ): void {
        if ($authDevice === null) {
            throw new Exception(Exception::USER_DEVICE_NOT_FOUND);
        }

        if ($deviceId !== 'current' && $deviceId !== $authDevice->getId()) {
            throw new Exception(Exception::USER_DEVICE_NOT_FOUND);
        }

        $sessions = $authDevice->getAttribute('sessions', []);
        $session = null;
        foreach ($sessions as $candidate) {
            /** @var Document $candidate */
            if ($candidate->getId() === $sessionId) {
                $session = $candidate;
                break;
            }
        }

        if ($session === null) {
            throw new Exception(Exception::USER_DEVICE_SESSION_NOT_FOUND);
        }

        $protocol = $request->getProtocol();

        // Session secrets are hashed, so the original can never be put back into a cookie. Switching
        // mints a fresh secret, stores its hash, and hands the plaintext to the cookie below.
        $secret = $proofForToken->generate();

        $session = $authorization->skip(fn () => $dbForProject->updateDocument('sessions', $session->getId(), new Document([
            'secret' => $proofForToken->hash($secret),
        ])));

        // Signed out by another request after the device was read
        if ($session->isEmpty()) {
            throw new Exception(Exception::USER_DEVICE_SESSION_NOT_FOUND);
        }

        $authorization->skip(fn () => $dbForProject->updateDocument('devices', $authDevice->getId(), new Document([
            'activeSessionId' => $session->getId(),
            'activeSessionInternalId' => $session->getSequence(),
        ])));

        $user = $authorization->skip(fn () => $dbForProject->getDocument('users', $session->getAttribute('userId')));

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
            ->setAttribute('userName', $user->getAttribute('name', ''))
            ->setAttribute('secret', $encoded)
            ->setAttribute('expire', DateTime::format(new \DateTime($session->getAttribute('expire'))));

        $queueForEvents
            ->setParam('userId', $session->getAttribute('userId'))
            ->setParam('sessionId', $session->getId())
            ->setPayload($response->output($session, Response::MODEL_SESSION), sensitive: ['secret', 'providerAccessToken', 'providerRefreshToken']);

        $response->dynamic($session, Response::MODEL_SESSION);
    }
}
