<?php

namespace Appwrite\Auth;

use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Utopia\Database\Documents\Project;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Auth\Proofs\Token as ProofsToken;
use Utopia\Auth\Store;
use Utopia\Config\Config;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Locale\Locale;

/**
 * Account switching device: the container, carried in its own cookie, that holds several
 * sessions on one browser and tracks which of them is active.
 *
 * The `authDevice` request resource resolves the device; this class holds what the session
 * and device endpoints do to it, so every session creation path attaches the session the
 * same way, before the response is sent.
 */
class AuthDevice
{
    /**
     * Transient attribute holding the encoded cookie value to issue: what the request arrived
     * with, or the rotated one `attach()` minted. Never written to the database.
     */
    public const string ATTRIBUTE_COOKIE = 'cookie';

    /**
     * Make $session the active session of $device.
     *
     * A device holds at most one session per user: an older session of the same user is signed
     * out and replaced, so signing in again refreshes an account instead of listing it twice.
     * The device lives as long as its newest session, so its expiry moves out to the session's.
     *
     * The device secret is rotated, so the caller must issue the new cookie value carried on the
     * returned device, with `refreshCookie()` or its own `Set-Cookie`.
     *
     * The session must already carry the device's id (set when it was created, or by the caller).
     * Call before the response is sent, so the device reads consistently on the next request.
     *
     * @return Document The device with the new active pointer, expiry and cookie value, holding its sessions.
     */
    public static function attach(Database $dbForProject, Authorization $authorization, ProofsToken $proofForToken, Document $device, Document $session, DeletePublisher $publisherForDeletes, Document $project, Mqtt $mqtt): Document
    {
        $userId = $session->getAttribute('userId', '');
        $replaced = false;

        foreach ($device->getAttribute('sessions', []) as $held) {
            /** @var Document $held */
            if ($held->getId() === $session->getId() || $held->getAttribute('userInternalId') !== $session->getAttribute('userInternalId')) {
                continue;
            }

            // Replaced, not signed out by the caller: the request's own event is the new session's
            // create, so like the session-limit prune this fires no delete event for the old one.
            $authorization->skip(fn () => $dbForProject->deleteDocument('sessions', $held->getId()));
            $replaced = true;

            $publisherForDeletes->enqueue(new DeleteMessage(
                project: $project,
                type: DELETE_TYPE_SESSION_TARGETS,
                document: $held,
            ));
        }

        if ($replaced && !empty($userId)) {
            // A realtime connection opened on the replaced session must not outlive it, as with deleteSession
            try {
                $mqtt->revoke($project->getId(), $userId);
            } catch (\Throwable $error) {
                Console::error('Failed to revoke MQTT connections: ' . $error->getMessage());
            }
        }

        $deviceId = $device->getId();

        // Joining a session is a privilege change: the device now has authority over the account
        // that just signed in. Mint a new device secret, so a copy of the credential taken before
        // the sign-in -- planted in this browser, or kept elsewhere -- stops working, the same way
        // a session secret is replaced at sign-in.
        $secret = $proofForToken->generate();

        $authorization->skip(fn () => $dbForProject->updateDocument('devices', $deviceId, new Document([
            'secret' => $proofForToken->hash($secret), // One way hash encryption to protect DB leak
            'activeSessionId' => $session->getId(),
            'activeSessionInternalId' => $session->getSequence(),
            'expire' => self::latestExpire($device, $session),
        ])));

        if (!empty($userId)) {
            $dbForProject->purgeCachedDocument('users', $userId);
        }
        $dbForProject->purgeCachedDocument('devices', $deviceId);

        // Read back so the held sessions reflect the new one and any replaced ones
        $device = $authorization->skip(fn () => $dbForProject->getDocument('devices', $deviceId));

        $store = new Store();
        $store
            ->setKey(self::key($project))
            ->setProperty('id', $deviceId)
            ->setProperty('secret', $secret);

        $device->setAttribute(self::ATTRIBUTE_COOKIE, $store->encode());

        return $device;
    }

    /**
     * Keep the device alive for a session it holds whose expiry moved out, for example after
     * updateSession extended it. Returns the device when it exists, with the transient cookie
     * value carried over from $authDevice when that is the same device, so the caller can
     * re-issue the cookie; null when the session is not on a device or the device is gone.
     */
    public static function extend(Database $dbForProject, Authorization $authorization, Document $session, ?Document $authDevice): ?Document
    {
        $deviceId = (string) $session->getAttribute('deviceId', '');
        if (empty($deviceId)) {
            return null;
        }

        $device = $authorization->skip(fn () => $dbForProject->getDocument('devices', $deviceId));
        if ($device->isEmpty()) {
            return null;
        }

        $expire = self::latestExpire($device, $session);
        if ($expire !== $device->getAttribute('expire')) {
            $authorization->skip(fn () => $dbForProject->updateDocument('devices', $deviceId, new Document([
                'expire' => $expire,
            ])));
            $dbForProject->purgeCachedDocument('devices', $deviceId);
            $device->setAttribute('expire', $expire);
        }

        if ($authDevice !== null && $authDevice->getId() === $deviceId) {
            $device->setAttribute(self::ATTRIBUTE_COOKIE, $authDevice->getAttribute(self::ATTRIBUTE_COOKIE, ''));
        }

        return $device;
    }

    /**
     * The later of the device's and the session's expiry: a device lives as long as its newest session.
     */
    private static function latestExpire(Document $device, Document $session): ?string
    {
        $expire = $device->getAttribute('expire');
        $sessionExpire = $session->getAttribute('expire');

        if (!empty($sessionExpire) && (empty($expire) || new \DateTime($sessionExpire) > new \DateTime($expire))) {
            return $sessionExpire;
        }

        return $expire;
    }

    /**
     * The name of the cookie, and of the X-Fallback-Cookies entry, that carries the device.
     */
    private static function key(Document $project): string
    {
        return ($project instanceof Project ? $project : new Project($project->getArrayCopy()))->getDeviceKey();
    }

    /**
     * Issue the device cookie, with the device's current expiry and whatever secret it now
     * carries, so the browser keeps the device for as long as the server does. A device that
     * carries no transient cookie value has nothing to issue, so it is skipped.
     */
    public static function refreshCookie(Request $request, Response $response, Document $project, Document $device, string $protocol, bool $domainVerification, ?string $cookieDomain): void
    {
        $encoded = $device->getAttribute(self::ATTRIBUTE_COOKIE, '');
        if (empty($encoded) || empty($device->getAttribute('expire'))) {
            return;
        }

        $key = self::key($project);
        $expire = (new \DateTime($device->getAttribute('expire')))->getTimestamp();

        if (!$domainVerification) {
            // A client on fallback cookies replaces its whole set with this header, so it must keep
            // carrying what the client already holds (its session entry, when this response sets
            // none) plus whatever the caller added to the response, with the device entry on top.
            $fallback = [];
            $incoming = \json_decode($request->getHeaderLine('x-fallback-cookies', ''), true);
            if (\is_array($incoming)) {
                $fallback = $incoming;
            }
            foreach ($response->getHeaders()['x-fallback-cookies'] ?? [] as $value) {
                $decoded = \json_decode($value, true);
                if (\is_array($decoded)) {
                    $fallback = \array_merge($fallback, $decoded);
                }
            }
            $fallback[$key] = $encoded;

            $response
                ->removeHeader('X-Fallback-Cookies')
                ->addHeader('X-Fallback-Cookies', \json_encode($fallback));
        }

        $response
            ->addCookie($key . '_legacy', $encoded, $expire, '/', $cookieDomain, ('https' == $protocol), true, null)
            ->addCookie($key, $encoded, $expire, '/', $cookieDomain, ('https' == $protocol), true, Config::getParam('cookieSamesite'));
    }

    /**
     * Prepare the device's sessions for a response: mark the active one, resolve the country name,
     * and name the user each session belongs to, so a switcher can label its accounts.
     */
    public static function present(Database $dbForProject, Authorization $authorization, Locale $locale, Document $device): Document
    {
        $activeSessionId = $device->getAttribute('activeSessionId', '');
        $sessions = $device->getAttribute('sessions', []);

        $names = self::userNames($dbForProject, $authorization, $sessions);

        foreach ($sessions as $key => $session) {
            /** @var Document $session */
            $session
                ->setAttribute('countryName', $locale->getText('countries.' . strtolower($session->getAttribute('countryCode', '')), $locale->getText('locale.country.unknown')))
                ->setAttribute('current', $session->getId() === $activeSessionId)
                ->setAttribute('secret', $session->getAttribute('secret', ''))
                // A device holds other users' sessions; their provider credentials are not the caller's
                ->setAttribute('providerAccessToken', '')
                ->setAttribute('providerRefreshToken', '')
                ->setAttribute('userName', $names[$session->getAttribute('userId', '')] ?? '');

            $sessions[$key] = $session;
        }

        return $device->setAttribute('sessions', $sessions);
    }

    /**
     * Names of the users the given sessions belong to, keyed by user id.
     *
     * @param array<Document> $sessions
     * @return array<string, string>
     */
    public static function userNames(Database $dbForProject, Authorization $authorization, array $sessions): array
    {
        $userIds = \array_values(\array_unique(\array_filter(\array_map(
            fn (Document $session) => (string) $session->getAttribute('userId', ''),
            $sessions
        ))));

        if (empty($userIds)) {
            return [];
        }

        $users = $authorization->skip(fn () => $dbForProject->find('users', [
            Query::equal('$id', $userIds),
            Query::limit(\count($userIds)),
        ]));

        $names = [];
        foreach ($users as $user) {
            /** @var Document $user */
            $names[$user->getId()] = (string) $user->getAttribute('name', '');
        }

        return $names;
    }
}
