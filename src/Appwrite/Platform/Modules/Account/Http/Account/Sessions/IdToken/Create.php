<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions\IdToken;

use Appwrite\Abuse\Limit;
use Appwrite\Auth\AuthDevice;
use Appwrite\Auth\MFA\Type;
use Appwrite\Auth\OIDC\IdTokenVerifier;
use Appwrite\Auth\OIDC\Jwks;
use Appwrite\Bus\Events\EmailVerified;
use Appwrite\Bus\Events\SessionCreated;
use Appwrite\Detector\Detector;
use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Extend\Exception;
use Appwrite\Geo\Geo;
use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Account\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Auth\Proofs\Password as ProofsPassword;
use Utopia\Auth\Proofs\Token as ProofsToken;
use Utopia\Auth\Store;
use Utopia\Bus\Bus;
use Utopia\Cache\Cache;
use Utopia\Client\Client;
use Utopia\Config\Config;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Locale\Locale;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'createIdTokenSession';
    }

    public function __construct()
    {
        $providers = Config::getParam('oAuthProviders', []);
        $idTokenProviders = \array_keys(\array_filter($providers, fn ($node) => !empty($node['idToken'])));
        $documentedProviders = \array_filter($idTokenProviders, fn (string $provider) => empty($providers[$provider]['mock']));

        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/sessions/id-token')
            ->desc('Create ID token session')
            ->groups(['api', 'account', 'session'])
            ->label('session.allowActive', true)
            ->label('event', 'users.[userId].sessions.[sessionId].create')
            ->label('scope', 'sessions.write')
            ->label('audits.event', 'session.create')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('audits.userId', '{response.userId}')
            ->label('usage.metric', 'sessions.{scope}.requests.create')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'sessions',
                name: 'createIdTokenSession',
                description: '/docs/references/account/create-session-id-token.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_SESSION,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->label('abuse', [Limit::failures(['projectId', 'url', 'ip'], 10)])
            ->param('provider', '', new WhiteList($idTokenProviders, true), 'OAuth2 provider that issued the ID token. Currently, supported providers are: ' . \implode(', ', $documentedProviders) . '.', enum: new Enum(name: 'IdTokenProvider', exclude: ['mock', 'mock-unverified']))
            ->param('idToken', '', new Text(8192, 0), 'OpenID Connect ID token (JWT) obtained natively from the provider, for example via Google Credential Manager or Sign in with Apple.')
            ->param('nonce', '', new Text(256, 0), 'Raw nonce used when requesting the ID token. Required for Apple, and whenever the token carries a nonce claim, which must match it. Ignored when the provider issued the token without a nonce.', true)
            ->param('accessToken', '', new Text(4096, 0), 'Provider access token to store alongside the session for calling provider APIs. Never used for authentication.', true)
            ->param('accessTokenExpiry', 0, new Range(0, 31536000), 'Seconds until the provider access token expires, as reported by the provider. Stored so clients can tell when the stored token goes stale.', true)
            ->param('name', '', new Text(128, 0), 'User name. Only used when creating a new user and the ID token has no name claim, such as on the first Sign in with Apple authorization.', true)
            ->inject('request')
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('locale')
            ->inject('geo')
            ->inject('queueForEvents')
            ->inject('store')
            ->inject('proofForToken')
            ->inject('proofForPassword')
            ->inject('plan')
            ->inject('domainVerification')
            ->inject('cookieDomain')
            ->inject('authorization')
            ->inject('cache')
            ->inject('bus')
            ->inject('clientForOAuth2')
            ->inject('mqtt')
            ->inject('queueForRealtime')
            ->inject('authDevice')
            ->inject('publisherForDeletes')
            ->callback($this->action(...));
    }

    public function action(
        string $provider,
        string $idToken,
        string $nonce,
        string $accessToken,
        int $accessTokenExpiry,
        string $name,
        Request $request,
        Response $response,
        User $user,
        Database $dbForProject,
        Document $project,
        Locale $locale,
        Geo $geo,
        Event $queueForEvents,
        Store $store,
        ProofsToken $proofForToken,
        ProofsPassword $proofForPassword,
        array $plan,
        bool $domainVerification,
        ?string $cookieDomain,
        Authorization $authorization,
        Cache $cache,
        Bus $bus,
        Client $clientForOAuth2,
        Mqtt $mqtt,
        Realtime $queueForRealtime,
        ?Document $authDevice,
        DeletePublisher $publisherForDeletes,
    ): void {
        $profile = new Document(Config::getParam('oAuthProviders', [])[$provider]['idToken'] ?? []);
        if ($profile->isEmpty()) {
            throw new Exception(Exception::PROJECT_PROVIDER_UNSUPPORTED, 'This provider does not support ID token sign-in.');
        }

        $oAuthProviders = $project->getAttribute('oAuthProviders', []);

        // Its own switch, separate from the browser flow's `enabled`: the two
        // need different credentials, so they are enabled independently.
        if (!($oAuthProviders[$provider . 'NativeEnabled'] ?? false)) {
            throw new Exception(Exception::PROJECT_PROVIDER_DISABLED, 'Native sign-in is disabled for this provider. Enable it from your ' . APP_NAME . ' console to continue.');
        }

        // Enabling validates this too; re-checked in case the list was cleared afterwards.
        $allowedAudiences = \array_values(\array_filter(\array_merge(
            [$oAuthProviders[$provider . 'Appid'] ?? ''],
            $oAuthProviders[$provider . 'ClientIds'] ?? [],
        )));
        if (empty($allowedAudiences)) {
            throw new Exception(Exception::PROJECT_PROVIDER_DISABLED, 'Configure a client ID or native client IDs for this provider to accept ID tokens.');
        }

        $claims = (new IdTokenVerifier(new Jwks($cache, $clientForOAuth2)))
            ->verify($profile, $idToken, $allowedAudiences, $nonce !== '' ? $nonce : null);

        $sub = $claims['sub'];
        $providerEmail = \is_string($claims['email'] ?? null) ? $claims['email'] : '';
        $email = $providerEmail;

        // Apple attests the claim as the string "true"; Google as a boolean
        $isVerified = \filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Apple never puts the name inside the ID token; it is delivered to the
        // client once, on the first authorization, and forwarded via the param
        $name = (\is_string($claims['name'] ?? null) && $claims['name'] !== '') ? $claims['name'] : $name;

        // Google exposes the avatar as a `picture` claim, so unlike the browser
        // flow there is no getUserPhoto() call to make. Apple never returns one.
        $photo = \is_string($claims['picture'] ?? null) ? $claims['picture'] : '';

        // Check if this identity is connected to a different user
        $sessionUpgrade = false;
        if (!$user->isEmpty()) {
            $identityWithMatchingUid = $dbForProject->findOne('identities', [
                Query::equal('provider', [$provider]),
                Query::equal('providerUid', [$sub]),
                Query::notEqual('userInternalId', $user->getSequence()),
            ]);
            if (!$identityWithMatchingUid->isEmpty()) {
                throw new Exception(Exception::USER_ALREADY_EXISTS);
            }

            if (!empty($providerEmail)) {
                $identityWithMatchingEmail = $dbForProject->findOne('identities', [
                    Query::equal('providerEmail', [$providerEmail]),
                    Query::notEqual('userInternalId', $user->getSequence()),
                ]);
                if (!$identityWithMatchingEmail->isEmpty()) {
                    throw new Exception(Exception::USER_ALREADY_EXISTS);
                }

                $userWithMatchingEmail = $dbForProject->find('users', [
                    Query::equal('email', [$email]),
                    Query::notEqual('$id', $user->getId()),
                ]);
                if (!empty($userWithMatchingEmail)) {
                    throw new Exception(Exception::USER_ALREADY_EXISTS);
                }
            }

            $sessionUpgrade = true;
        }

        $current = $user->sessionVerify($store->getProperty('secret', ''), $proofForToken, $project->getAttribute('auths', [])['sessionsInvalidatedAt'] ?? null);

        // What the request got into the account through, which must still exist when the sign-in is persisted
        $grounds = $current ? ['sessions' => $current] : [];

        if ($user->isEmpty()) {
            $session = $dbForProject->findOne('sessions', [ // Get user by provider id
                Query::equal('provider', [$provider]),
                Query::equal('providerUid', [$sub]),
            ]);
            if (!$session->isEmpty()) {
                $user->setAttributes($dbForProject->getDocument('users', $session->getAttribute('userId'))->getArrayCopy());
                $grounds = ['sessions' => $session->getId()];
            }
        }

        $newUser = null;
        $newTarget = null;
        if ($user->isEmpty()) {
            [$newUser, $newTarget, $ground] = $this->resolveUser($user, $provider, $sub, $providerEmail, $isVerified, $name, $dbForProject, $project, $plan, $proofForPassword, $authorization, $mqtt, $queueForRealtime, $bus);
            $grounds = $ground === null ? [] : ['identities' => $ground->getId()];
        }

        $authorization->addRole(Role::user($user->getId())->toString());
        $authorization->addRole(Role::users()->toString());

        if (false === $user->getAttribute('status')) { // Account is blocked
            throw new Exception(Exception::USER_BLOCKED);
        }

        // The account as the request authenticated against it, before this sign-in changes anything on it
        $snapshot = new Document($user->getArrayCopy());

        // Only a user this sign-in created and an address it fills in turn verified from here, and both are
        // saved below; a claim already dispatched EmailVerified and left the account verified
        $wasVerified = $newUser === null && $user->getAttribute('emailVerification', false);

        $backfilled = empty($user->getAttribute('email')) && !empty($providerEmail);
        $backfillEmail = $backfilled ? $this->backfillEmail($user, $providerEmail, $isVerified, $dbForProject, $project, $plan) : null;

        // Mirrors the browser OAuth2 flow, which turns the provider's `expires_in`
        // into an absolute timestamp. Unknown expiry stays null rather than
        // claiming the token is already stale.
        $accessTokenExpiresAt = ($accessToken !== '' && $accessTokenExpiry > 0)
            ? DateTime::addSeconds(new \DateTime(), $accessTokenExpiry)
            : null;

        // The token is verified and required, so it always supersedes the stored
        // one: its claims would otherwise go stale, and it expires within the hour.
        $refreshed = ['providerIdToken' => $idToken];

        // Native sign-in often carries no access token at all, so only overwrite
        // the stored credentials when the client actually supplied one.
        if (!empty($accessToken)) {
            $refreshed['providerAccessToken'] = $accessToken;
            $refreshed['providerAccessTokenExpiry'] = $accessTokenExpiresAt;
        }

        // Refresh the photo URL on every login so expired CDN links self-heal.
        if ($photo !== '') {
            $refreshed['photo'] = $photo;
        }

        // Only what this request changed: its copy predates any claim or password change that landed since
        $changes = ['status' => true];

        if (empty($user->getAttribute('name'))) {
            $user->setAttribute('name', $name);
            $changes['name'] = $name;
        }

        if ($backfilled) {
            $changes += \array_intersect_key($user->getArrayCopy(), \array_flip(['email', 'emailVerification', 'emailCanonical', 'emailIsCanonical', 'emailIsCorporate', 'emailIsDisposable', 'emailIsFree']));
        }

        $user->setAttribute('status', true);

        $duration = $project->getAttribute('auths', [])['duration'] ?? TOKEN_EXPIRATION_LOGIN_LONG;
        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $secret = $proofForToken->generate();
        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), $duration));
        $geoRecord = $geo->get($request->getIP());

        $session = new Document(array_merge(
            [
                '$id' => Id::unique(),
                'userId' => $user->getId(),
                'userInternalId' => $user->getSequence(),
                // The account switching device the session joins, when the browser holds one
                'deviceId' => $authDevice?->getId() ?? '',
                'deviceInternalId' => $authDevice?->getSequence() ?? '',
                'provider' => $provider,
                'providerUid' => $sub,
                'providerAccessToken' => $accessToken,
                // Native sign-in never yields a refresh token: the provider hands the
                // client an ID token and, at most, a short-lived access token. Stored
                // explicitly so the session shape matches the browser OAuth2 flow.
                'providerRefreshToken' => null,
                'providerAccessTokenExpiry' => $accessTokenExpiresAt,
                'secret' => $proofForToken->hash($secret), // One way hash encryption to protect DB leak
                'userAgent' => $request->getUserAgent('UNKNOWN'),
                'ip' => $request->getIP(),
                'factors' => [Type::EMAIL, 'oauth2'], // include a special oauth2 factor to bypass MFA checks
                'countryCode' => \strtolower($geoRecord->getCountryCode()),
                'continentCode' => $geoRecord->getContinentCode() === '--' ? null : $geoRecord->getContinentCode(),
                'latitude' => $geoRecord->getLatitude(),
                'longitude' => $geoRecord->getLongitude(),
                'timeZone' => $geoRecord->getTimeZone(),
                'weatherCode' => $geoRecord->getWeatherCode(),
                'postalCode' => $geoRecord->getPostalCode(),
                'autonomousSystemNumber' => $geoRecord->getAutonomousSystemNumber(),
                'autonomousSystemOrganization' => $geoRecord->getAutonomousSystemOrganization(),
                'connectionType' => $geoRecord->getConnectionType(),
                'connectionUsageType' => $geoRecord->getConnectionUsageType(),
                'connectionOrganization' => $geoRecord->getConnectionOrganization(),
                'isp' => $geoRecord->getIsp(),
                'expire' => DateTime::addSeconds(new \DateTime(), $duration),
            ],
            $detector->getOS(),
            $detector->getClient(),
            $detector->getDevice()
        ));

        // Persist the sign-in under the user row lock, so it is ordered against a claim of the account: one that committed
        // since the request read the account fails it, and one that comes later revokes what it wrote
        $conflict = null;
        $linked = null;
        $target = null;
        try {
            $session = $dbForProject->withTransaction(function () use ($dbForProject, $snapshot, $grounds, $user, $provider, $sub, $providerEmail, $accessToken, $accessTokenExpiresAt, $idToken, $photo, $refreshed, $changes, $session, $backfillEmail, &$conflict, &$linked, &$target) {
                $conflict = null; // A retried attempt starts over
                $linked = null;
                $target = null;

                if (!User::lockUnchanged($dbForProject, $snapshot, $grounds)) {
                    throw new Exception(Exception::GENERAL_BAD_REQUEST);
                }

                $linked = $this->writeIdentity($user, $provider, $sub, $providerEmail, [
                    'providerAccessToken' => $accessToken,
                    'providerRefreshToken' => null,
                    'providerAccessTokenExpiry' => $accessTokenExpiresAt,
                    'providerIdToken' => $idToken,
                    'photo' => $photo ?: null,
                ], $refreshed, $dbForProject, $conflict);

                if ($conflict !== null) {
                    return;
                }

                if ($backfillEmail !== null) {
                    $target = User::reserveEmailTarget($dbForProject, $user, $backfillEmail);
                }

                $dbForProject->updateDocument('users', $user->getId(), new Document($changes));

                return $dbForProject->createDocument('sessions', $session->setAttribute('$permissions', [
                    Permission::read(Role::user($user->getId())),
                    Permission::update(Role::user($user->getId())),
                    Permission::delete(Role::user($user->getId())),
                ]));
            });
        } catch (Duplicate) {
            // The (provider, providerUid) unique index guards the same identity being connected to two users, and
            // the identifier index the same address being a target of two
            $conflict = Exception::USER_ALREADY_EXISTS;
        }

        // The link's email check could not see another user's link that had not committed, so it is made again now
        if ($conflict === null && $linked !== null && !User::confirmIdentity($dbForProject, $linked)) {
            // Under the user row lock: put back what the sign-in changed on the account and the address it reserved
            $authorization->skip(fn () => $dbForProject->withTransaction(function () use ($dbForProject, $snapshot, $changes, $target, $session) {
                User::revertSignIn($dbForProject, $snapshot, $changes, $target);
                $dbForProject->deleteDocument('sessions', $session->getId());
            }));
            $conflict = Exception::GENERAL_BAD_REQUEST;
        }

        if ($conflict !== null) {
            $this->dropNewUser($dbForProject, $authorization, $newUser, $newTarget);
            throw new Exception($conflict);
        }

        if (!$wasVerified && $user->getAttribute('emailVerification', false)) {
            $bus->dispatch(new EmailVerified(user: $user->getArrayCopy(), project: $project->getArrayCopy()));
        }

        if ($sessionUpgrade) {
            foreach ($user->getAttribute('targets', []) as $target) {
                if ($target->getAttribute('providerType') !== MESSAGE_TYPE_PUSH) {
                    continue;
                }

                $target
                    ->setAttribute('sessionId', $session->getId())
                    ->setAttribute('sessionInternalId', $session->getSequence());

                $dbForProject->updateDocument('targets', $target->getId(), new Document([
                    'sessionId' => $target->getAttribute('sessionId'),
                    'sessionInternalId' => $target->getAttribute('sessionInternalId'),
                ]));
            }
        }

        $encoded = $store
            ->setProperty('id', $user->getId())
            ->setProperty('secret', $secret)
            ->encode();

        if (!$domainVerification) {
            $response->addHeader('X-Fallback-Cookies', \json_encode([$store->getKey() => $encoded]));
        }

        $protocol = $request->getProtocol();

        $response
            ->addCookie($store->getKey() . '_legacy', $encoded, (new \DateTime($expire))->getTimestamp(), '/', $cookieDomain, ('https' == $protocol), true, null)
            ->addCookie($store->getKey(), $encoded, (new \DateTime($expire))->getTimestamp(), '/', $cookieDomain, ('https' == $protocol), true, Config::getParam('cookieSamesite'))
            ->setStatusCode(Response::STATUS_CODE_CREATED)
        ;

        // The session joins the account switching device and becomes its active one, before the
        // response goes out so the device reads consistently on the client's next request
        if ($authDevice !== null) {
            $authDevice = AuthDevice::attach($dbForProject, $authorization, $proofForToken, $authDevice, $session, $publisherForDeletes, $project, $mqtt);
            AuthDevice::refreshCookie($request, $response, $project, $authDevice, $protocol, $domainVerification, $cookieDomain);
        }

        $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

        $session
            ->setAttribute('current', true)
            ->setAttribute('countryName', $countryName)
            ->setAttribute('userName', $user->getAttribute('name', ''))
            ->setAttribute('secret', $encoded)
        ;

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('sessionId', $session->getId())
            ->setPayload($response->output($session, Response::MODEL_SESSION), sensitive: ['secret', 'providerAccessToken', 'providerRefreshToken'])
        ;

        $bus->dispatch(new SessionCreated(
            user: $user->getArrayCopy(),
            project: $project->getArrayCopy(),
            session: $session->getArrayCopy(),
            locale: $locale->default,
        ));

        $response->dynamic($session, Response::MODEL_SESSION);

        // The response has been sent by now, so the caller already holds the
        // replacement session. Only then is the replaced one removed: a failure
        // here is logged, but it can no longer log the caller out.
        if ($current) {
            $currentDocument = $dbForProject->getDocument('sessions', $current);
            if (!$currentDocument->isEmpty()) {
                $dbForProject->deleteDocument('sessions', $currentDocument->getId());
            }
        }

        $dbForProject->purgeCachedDocument('users', $user->getId());

        if ($current) {
            try {
                $mqtt->revoke($project->getId(), $user->getId());
            } catch (\Throwable $error) {
                Console::error('Failed to revoke MQTT connections: ' . $error->getMessage());
            }
        }
    }

    /**
     * Attach the provider email to a user created without one (e.g. an
     * anonymous account being linked). Never downgrades an already-verified
     * user. Mutates `$user` in place and returns the address; the caller persists it and reserves its email
     * target with the rest of the sign-in, under the user row lock.
     */
    private function backfillEmail(User $user, string $providerEmail, bool $isVerified, Database $dbForProject, Document $project, array $plan): string
    {
        [$email, $emails, $emailMetadata, $canonicalize] = $this->parseEmail($providerEmail, $isVerified, $project, $plan);

        $userWithMatchingEmail = $dbForProject->find('users', [
            Query::equal('email', $emails),
            Query::notEqual('$id', $user->getId()),
        ]);
        if (!empty($userWithMatchingEmail)) {
            throw new Exception(Exception::USER_ALREADY_EXISTS);
        }

        $this->assertEmailPolicy($emailMetadata, $email, $canonicalize, $project, $plan);

        $user->setAttribute('email', $email);
        // Never downgrade an already-verified user; only ever promote to verified
        $user->setAttribute('emailVerification', $user->getAttribute('emailVerification', false) || $isVerified);
        $user->setAttribute('emailCanonical', $emailMetadata['emailCanonical']);
        $user->setAttribute('emailIsCanonical', $emailMetadata['emailIsCanonical']);
        $user->setAttribute('emailIsCorporate', $emailMetadata['emailIsCorporate']);
        $user->setAttribute('emailIsDisposable', $emailMetadata['emailIsDisposable']);
        $user->setAttribute('emailIsFree', $emailMetadata['emailIsFree']);

        return $email;
    }
}
