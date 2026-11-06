<?php

namespace Appwrite\Auth;

use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Utopia\Database\Documents\Project;
use Appwrite\Utopia\Response;
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
     * Transient attribute set by the `authDevice` resource: the encoded cookie value the request
     * arrived with. Re-issued when the device expiry moves. Never written to the database.
     */
    public const string ATTRIBUTE_COOKIE = 'cookie';

    /**
     * Make $session the active session of $device.
     *
     * A device holds at most one session per user: an older session of the same user is signed
     * out and replaced, so signing in again refreshes an account instead of listing it twice.
     * The device lives as long as its newest session, so its expiry moves out to the session's.
     *
     * The session must already carry the device's id (set when it was created, or by the caller).
     * Call before the response is sent, so the device reads consistently on the next request.
     *
     * @return Document The device with the new active pointer and expiry, holding its sessions.
     */
    public static function attach(Database $dbForProject, Authorization $authorization, Document $device, Document $session, DeletePublisher $publisherForDeletes, Document $project, Mqtt $mqtt): Document
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

        $cookie = $device->getAttribute(self::ATTRIBUTE_COOKIE, '');
        $deviceId = $device->getId();

        $authorization->skip(fn () => $dbForProject->updateDocument('devices', $deviceId, new Document([
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
        $device->setAttribute(self::ATTRIBUTE_COOKIE, $cookie);

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
     * Re-issue the device cookie with the device's current expiry, so the browser keeps the
     * device for as long as the server does. A device created in this request already had its
     * cookie written by the create action and carries no transient cookie value, so it is skipped.
     */
    public static function refreshCookie(Response $response, Document $project, Document $device, string $protocol, bool $domainVerification, ?string $cookieDomain): void
    {
        $encoded = $device->getAttribute(self::ATTRIBUTE_COOKIE, '');
        if (empty($encoded) || empty($device->getAttribute('expire'))) {
            return;
        }

        $key = ($project instanceof Project ? $project : new Project($project->getArrayCopy()))->getDeviceKey();
        $expire = (new \DateTime($device->getAttribute('expire')))->getTimestamp();

        if (!$domainVerification) {
            // The session cookie's fallback entry was already added by the caller; merge rather than replace it.
            $fallback = [];
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
