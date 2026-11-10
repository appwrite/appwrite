<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Devices;

use Appwrite\Abuse\Limit;
use Appwrite\Auth\AuthDevice;
use Appwrite\Detector\Detector;
use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Extend\Exception;
use Appwrite\Geo\Geo;
use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\Project;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Auth\Proofs\Token as ProofsToken;
use Utopia\Auth\Store;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Validator\Authorization;
use Utopia\Locale\Locale;
use Utopia\Platform\Scope\HTTP;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createDevice';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/devices')
            ->desc('Create device')
            ->groups(['api', 'account'])
            ->label('scope', 'sessions.write')
            ->label('audits.event', 'device.create')
            ->label('audits.resource', 'user/{user.$id}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'devices',
                name: 'createDevice',
                description: <<<EOT
                Create an account switching device. A device is a container, scoped to one browser or app install, that holds multiple sessions and tracks which one is active. Once a device exists, session creation endpoints attach the new session to it automatically, so a single browser can stay signed in to several accounts and switch between them. A device holds at most one session per user: signing in again as a user it already holds replaces that user's session. The device lives as long as its newest session.

                The device secret is returned in a cookie. Run this on app start: if the browser already has a valid device, the existing one is returned with the same status code instead of an error, so it is always safe to call.
                EOT,
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_DEVICE,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->label('abuse', [Limit::attempts(['ip'], 50)])
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('geo')
            ->inject('authDevice')
            ->inject('locale')
            ->inject('proofForToken')
            ->inject('domainVerification')
            ->inject('cookieDomain')
            ->inject('queueForEvents')
            ->inject('publisherForDeletes')
            ->inject('authorization')
            ->inject('mode')
            ->inject('user')
            ->inject('store')
            ->inject('mqtt')
            ->callback($this->action(...));
    }

    public function action(
        Request $request,
        Response $response,
        Project $project,
        Database $dbForProject,
        Geo $geo,
        ?Document $authDevice,
        Locale $locale,
        ProofsToken $proofForToken,
        bool $domainVerification,
        ?string $cookieDomain,
        Event $queueForEvents,
        DeletePublisher $publisherForDeletes,
        Authorization $authorization,
        string $mode,
        User $user,
        Store $storeForSession,
        Mqtt $mqtt,
    ): void {
        if (($project->getAttribute('auths', [])['accountDevices'] ?? true) === false) {
            throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Account devices are disabled for this project');
        }

        // A browser that already holds a valid device keeps it: devs call this on every app
        // start, so returning the existing device beats an error. No new cookie is written,
        // the browser already carries it, and the secret is already gone from our side.
        if ($authDevice !== null) {
            $authDevice = AuthDevice::present($dbForProject, $authorization, $locale, $authDevice);

            $response
                ->setStatusCode(Response::STATUS_CODE_CREATED)
                ->dynamic($authDevice, Response::MODEL_DEVICE);
            return;
        }

        $protocol = $request->getProtocol();
        $duration = $project->getAttribute('auths', [])['duration'] ?? TOKEN_EXPIRATION_LOGIN_LONG;
        $expire = DateTime::addSeconds(new \DateTime(), $duration);

        $secret = $proofForToken->generate();
        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $geoRecord = $geo->get($request->getIP());

        $deviceId = Id::unique();
        $device = new Document(array_merge(
            [
                '$id' => $deviceId,
                '$permissions' => [],
                'secret' => $proofForToken->hash($secret), // One way hash encryption to protect DB leak
                'activeSessionId' => '',
                'activeSessionInternalId' => '',
                'userAgent' => $request->getUserAgent('UNKNOWN'),
                'ip' => $request->getIP(),
                'countryCode' => \strtolower($geoRecord->getCountryCode()),
                'expire' => $expire,
            ],
            $detector->getOS(),
            $detector->getClient(),
            $detector->getDevice()
        ));

        $device = $authorization->skip(fn () => $dbForProject->createDocument('devices', $device));

        // Adopt the caller's current session so the account they are already signed in as is
        // in the device and switchable, not just sessions created after the device exists.
        // A session still pointing at a device that no longer exists (expired and swept) is
        // free to be adopted too.
        $current = $this->proven($user, $storeForSession, $proofForToken, $project, $mode);

        if ($current !== null && $this->isAdoptable($dbForProject, $authorization, $current)) {
            $current = $authorization->skip(fn () => $dbForProject->updateDocument('sessions', $current->getId(), new Document([
                'deviceId' => $device->getId(),
                'deviceInternalId' => $device->getSequence(),
            ])));

            $device = AuthDevice::attach($dbForProject, $authorization, $device, $current, $publisherForDeletes, $project, $mqtt);
            $expire = $device->getAttribute('expire');
        }

        $storeForDevice = new Store();
        $storeForDevice
            ->setKey($project->getDeviceKey())
            ->setProperty('id', $deviceId)
            ->setProperty('secret', $secret);
        $encoded = $storeForDevice->encode();

        if (!$domainVerification) {
            $response->addHeader('X-Fallback-Cookies', \json_encode([$storeForDevice->getKey() => $encoded]));
        }

        $response
            ->addCookie($storeForDevice->getKey() . '_legacy', $encoded, (new \DateTime($expire))->getTimestamp(), '/', $cookieDomain, ('https' == $protocol), true, null)
            ->addCookie($storeForDevice->getKey(), $encoded, (new \DateTime($expire))->getTimestamp(), '/', $cookieDomain, ('https' == $protocol), true, Config::getParam('cookieSamesite'))
            ->setStatusCode(Response::STATUS_CODE_CREATED);

        $device = AuthDevice::present($dbForProject, $authorization, $locale, $device);
        $device->setAttribute('secret', $encoded);

        $queueForEvents
            ->setParam('deviceId', $device->getId())
            ->setPayload($response->output($device, Response::MODEL_DEVICE), sensitive: ['secret']);

        $response->dynamic($device, Response::MODEL_DEVICE);
    }

    /**
     * The session the caller proved by sending its secret, or null when they proved none.
     *
     * Deliberately not the injected `session` resource: that one also resolves from the
     * sessionId claim inside an x-appwrite-jwt header, which names a session without proving
     * possession of its secret. A JWT is a bounded delegation that function runtimes and app
     * backends routinely hold, and adopting the session it names would let any holder trade
     * the token for the device secret and then, through the device session endpoint, for a
     * fresh long-lived session secret with the session's MFA factors already satisfied.
     * A JWT caller still gets a device, it just comes back holding no session.
     *
     * Admin mode proves nothing adoptable either: there the secret belongs to a console
     * session in the platform database, not the project database this device is written to.
     */
    private function proven(User $user, Store $storeForSession, ProofsToken $proofForToken, Project $project, string $mode): ?Document
    {
        if ($mode === APP_MODE_ADMIN) {
            return null;
        }

        $sessionId = $user->sessionVerify(
            $storeForSession->getProperty('secret', ''),
            $proofForToken,
            $project->getAttribute('auths', [])['sessionsInvalidatedAt'] ?? null
        );

        if (!$sessionId) {
            return null;
        }

        return \array_find(
            $user->getAttribute('sessions', []),
            static fn (Document $session): bool => $session->getId() === $sessionId
        );
    }

    /**
     * A session can join a new device when it is on no device, or when the device it points at is gone.
     */
    private function isAdoptable(Database $dbForProject, Authorization $authorization, Document $session): bool
    {
        $deviceId = (string) $session->getAttribute('deviceId', '');
        if (empty($deviceId)) {
            return true;
        }

        return $authorization->skip(fn () => $dbForProject->getDocument('devices', $deviceId))->isEmpty();
    }
}
