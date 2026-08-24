<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions\IdToken;

use Appwrite\Auth\MFA\Type;
use Appwrite\Auth\OIDC\IdTokenVerifier;
use Appwrite\Auth\OIDC\Jwks;
use Appwrite\Auth\OIDC\JwksException;
use Appwrite\Auth\OIDC\Profiles;
use Appwrite\Auth\OIDC\VerificationException;
use Appwrite\Bus\Events\SessionCreated;
use Appwrite\Detector\Detector;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Locale\GeoRecord;
use Appwrite\Platform\Action;
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
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Emails\Email;
use Utopia\Locale\Locale;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createIdTokenSession';
    }

    public function __construct()
    {
        $providers = Config::getParam('oAuthProviders', []);
        $idTokenProviders = \array_keys(\array_filter($providers, fn ($node) => ($node['idToken'] ?? false) && !($node['mock'] ?? false)));

        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/sessions/id-token')
            ->desc('Create ID token session')
            ->groups(['api', 'account', 'session'])
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
            ->label('abuse-limit', 10)
            ->label('abuse-key', 'url:{url},ip:{ip}')
            ->label('abuse-reset', [201])
            ->param('provider', '', new WhiteList(\array_keys($providers), true), 'OAuth2 provider that issued the ID token. Currently, supported providers are: ' . \implode(', ', $idTokenProviders) . '.', enum: new Enum(name: 'OAuthProvider', exclude: ['mock', 'mock-unverified']))
            ->param('idToken', '', new Text(8192, 0), 'OpenID Connect ID token (JWT) obtained natively from the provider, for example via Google Credential Manager or Sign in with Apple.')
            ->param('nonce', '', new Text(256, 0), 'Raw nonce used when requesting the ID token. Required for Apple, and whenever the token contains a nonce claim.', true)
            ->param('accessToken', '', new Text(4096, 0), 'Provider access token to store alongside the session for calling provider APIs. Never used for authentication.', true)
            ->param('name', '', new Text(128, 0), 'User name. Only used when creating a new user and the ID token has no name claim, such as on the first Sign in with Apple authorization.', true)
            ->inject('request')
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('locale')
            ->inject('geoRecord')
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
            ->callback($this->action(...));
    }

    public function action(
        string $provider,
        string $idToken,
        string $nonce,
        string $accessToken,
        string $name,
        Request $request,
        Response $response,
        User $user,
        Database $dbForProject,
        Document $project,
        Locale $locale,
        GeoRecord $geoRecord,
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
    ): void {
        $profile = Profiles::get($provider);
        if ($profile === null || !(Config::getParam('oAuthProviders', [])[$provider]['idToken'] ?? false)) {
            throw new Exception(Exception::PROJECT_PROVIDER_UNSUPPORTED, 'This provider does not support ID token sign-in.');
        }

        $oAuthProviders = $project->getAttribute('oAuthProviders', []);

        $providerEnabled = $oAuthProviders[$provider . 'Enabled'] ?? false;
        if (!$providerEnabled) {
            throw new Exception(Exception::PROJECT_PROVIDER_DISABLED, 'This provider is disabled. Please enable the provider from your ' . APP_NAME . ' console to continue.');
        }

        $allowedAudiences = \array_values(\array_filter(\array_merge(
            [$oAuthProviders[$provider . 'Appid'] ?? ''],
            $oAuthProviders[$provider . 'ClientIds'] ?? [],
        )));
        if (empty($allowedAudiences)) {
            throw new Exception(Exception::PROJECT_PROVIDER_DISABLED, 'Configure a client ID or native client IDs for this provider to accept ID tokens.');
        }

        try {
            $claims = (new IdTokenVerifier(new Jwks($cache)))
                ->verify($profile, $idToken, $allowedAudiences, $nonce !== '' ? $nonce : null);
        } catch (VerificationException $error) {
            throw new Exception(Exception::USER_OAUTH2_TOKEN_INVALID, $error->getMessage());
        } catch (JwksException) {
            throw new Exception(Exception::USER_OAUTH2_PROVIDER_ERROR, 'Failed to fetch the provider signing keys. Please try again.');
        }

        $sub = $claims['sub'];
        $providerEmail = \is_string($claims['email'] ?? null) ? $claims['email'] : '';
        $email = $providerEmail;

        // Apple attests the claim as the string "true"; Google as a boolean
        $isVerified = \filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Apple never puts the name inside the ID token; it is delivered to the
        // client once, on the first authorization, and forwarded via the param
        $name = (\is_string($claims['name'] ?? null) && $claims['name'] !== '') ? $claims['name'] : $name;

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

        $current = $user->sessionVerify($store->getProperty('secret', ''), $proofForToken);

        if ($user->isEmpty()) {
            $session = $dbForProject->findOne('sessions', [ // Get user by provider id
                Query::equal('provider', [$provider]),
                Query::equal('providerUid', [$sub]),
            ]);
            if (!$session->isEmpty()) {
                $user->setAttributes($dbForProject->getDocument('users', $session->getAttribute('userId'))->getArrayCopy());
            }
        }

        $newUser = null;
        $newTarget = null;
        if ($user->isEmpty()) {
            [$newUser, $newTarget] = $this->resolveUser($user, $provider, $sub, $providerEmail, $isVerified, $name, $dbForProject, $project, $plan, $proofForPassword, $authorization);
        }

        $authorization->addRole(Role::user($user->getId())->toString());
        $authorization->addRole(Role::users()->toString());

        if (false === $user->getAttribute('status')) { // Account is blocked
            throw new Exception(Exception::USER_BLOCKED);
        }

        if (empty($user->getAttribute('email')) && !empty($providerEmail)) {
            $this->backfillEmail($user, $providerEmail, $isVerified, $dbForProject, $project, $plan, $authorization);
        }

        $this->upsertIdentity($user, $provider, $sub, $providerEmail, $accessToken, $dbForProject, $authorization, $newUser, $newTarget);

        if (empty($user->getAttribute('name'))) {
            $user->setAttribute('name', $name);
        }

        $user->setAttribute('status', true);

        $dbForProject->updateDocument('users', $user->getId(), $user);

        if ($current) { // Replace the current session only now that linking succeeded
            $currentDocument = $dbForProject->getDocument('sessions', $current);
            if (!$currentDocument->isEmpty()) {
                $dbForProject->deleteDocument('sessions', $currentDocument->getId());
                $dbForProject->purgeCachedDocument('users', $user->getId());
            }
        }

        $duration = $project->getAttribute('auths', [])['duration'] ?? TOKEN_EXPIRATION_LOGIN_LONG;
        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $secret = $proofForToken->generate();
        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), $duration));

        $session = new Document(array_merge(
            [
                '$id' => ID::unique(),
                'userId' => $user->getId(),
                'userInternalId' => $user->getSequence(),
                'provider' => $provider,
                'providerUid' => $sub,
                'providerAccessToken' => $accessToken,
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

        $session = $dbForProject->createDocument('sessions', $session->setAttribute('$permissions', [
            Permission::read(Role::user($user->getId())),
            Permission::update(Role::user($user->getId())),
            Permission::delete(Role::user($user->getId())),
        ]));

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

        $dbForProject->purgeCachedDocument('users', $user->getId());

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

        $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

        $session
            ->setAttribute('current', true)
            ->setAttribute('countryName', $countryName)
            ->setAttribute('secret', $encoded)
        ;

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('sessionId', $session->getId())
            ->setPayload($response->output($session, Response::MODEL_SESSION))
        ;

        $bus->dispatch(new SessionCreated(
            user: $user->getArrayCopy(),
            project: $project->getArrayCopy(),
            session: $session->getArrayCopy(),
            locale: $locale->default,
        ));

        $response->dynamic($session, Response::MODEL_SESSION);
    }

    /**
     * Resolve the ID token to a user: an existing identity wins, then a
     * verified-email match adopts the account, otherwise a new user is
     * created. Mutates `$user` in place. Mirrors the browser OAuth2 flow.
     *
     * @return array{?Document, ?Document} the user and email target created by
     *     this request, if any, so they can be rolled back later
     */
    private function resolveUser(User $user, string $provider, string $sub, string $providerEmail, bool $isVerified, string $name, Database $dbForProject, Document $project, array $plan, ProofsPassword $proofForPassword, Authorization $authorization): array
    {
        $identity = $dbForProject->findOne('identities', [
            Query::equal('provider', [$provider]),
            Query::equal('providerUid', [$sub]),
        ]);

        if (!$identity->isEmpty()) {
            $user->setAttributes($dbForProject->getDocument('users', $identity->getAttribute('userId'))->getArrayCopy());
        }

        $email = $providerEmail;
        $emails = [$email];
        $emailMetadata = [
            'emailCanonical' => null,
            'emailIsCanonical' => null,
            'emailIsCorporate' => null,
            'emailIsDisposable' => null,
            'emailIsFree' => null,
        ];
        $canonicalize = false;
        if ($user->isEmpty() && !empty($providerEmail)) {
            [$email, $emails, $emailMetadata, $canonicalize] = $this->parseEmail($providerEmail, $isVerified, $project, $plan);
        }

        // If user is not found, check if there is a user with the same email
        if ($user->isEmpty() && !empty($email)) {
            $userWithEmail = $dbForProject->findOne('users', [
                Query::equal('email', $emails),
            ]);
            if (!$userWithEmail->isEmpty()) {
                if (!$isVerified) {
                    throw new Exception(Exception::GENERAL_BAD_REQUEST);
                }
                $user->setAttributes($userWithEmail->getArrayCopy());
            }
        }

        // If user is not found, check if there is an identity with the same email
        if ($user->isEmpty() && !empty($providerEmail)) {
            $identityWithMatchingEmail = $dbForProject->findOne('identities', [
                Query::equal('providerEmail', [$providerEmail]),
            ]);
            if (!$identityWithMatchingEmail->isEmpty()) {
                if (!$isVerified) {
                    throw new Exception(Exception::GENERAL_BAD_REQUEST);
                }
                $user->setAttributes($dbForProject->getDocument('users', $identityWithMatchingEmail->getAttribute('userId'))->getArrayCopy());
            }
        }

        if (!$user->isEmpty()) {
            return [null, null];
        }

        // Last option -> create the user
        $limit = $project->getAttribute('auths', [])['limit'] ?? 0;
        if ($limit !== 0) {
            $total = $dbForProject->count('users', max: APP_LIMIT_USERS);
            if ($total >= $limit) {
                throw new Exception(Exception::USER_COUNT_EXCEEDED);
            }
        }

        $this->assertEmailPolicy($emailMetadata, $email, $canonicalize, $project, $plan);

        try {
            $userId = ID::unique();
            $user->setAttributes([
                '$id' => $userId,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::user($userId)),
                    Permission::delete(Role::user($userId)),
                ],
                'email' => $email ?: null,
                'emailVerification' => !empty($email) && $isVerified, // Trust the provider's attestation, not the mere fact an email was returned
                'status' => true,
                'password' => null,
                'hash' => $proofForPassword->getHash()->getName(),
                'hashOptions' => $proofForPassword->getHash()->getOptions(),
                'passwordUpdate' => null,
                'registration' => DateTime::now(),
                'reset' => false,
                'name' => $name,
                'mfa' => false,
                'prefs' => new \stdClass(),
                'sessions' => null,
                'tokens' => null,
                'memberships' => null,
                'authenticators' => null,
                'search' => implode(' ', \array_filter([$userId, $email, $name])),
                'accessedAt' => DateTime::now(),
                'emailCanonical' => $emailMetadata['emailCanonical'],
                'emailIsCanonical' => $emailMetadata['emailIsCanonical'],
                'emailIsCorporate' => $emailMetadata['emailIsCorporate'],
                'emailIsDisposable' => $emailMetadata['emailIsDisposable'],
                'emailIsFree' => $emailMetadata['emailIsFree'],
            ]);

            $user->removeAttribute('$sequence');
            $userDoc = $authorization->skip(fn () => $dbForProject->createDocument('users', $user));
            $newTarget = null;
            if (!empty($email)) {
                $newTarget = $dbForProject->createDocument('targets', new Document([
                    '$permissions' => [
                        Permission::read(Role::user($user->getId())),
                        Permission::update(Role::user($user->getId())),
                        Permission::delete(Role::user($user->getId())),
                    ],
                    'userId' => $userDoc->getId(),
                    'userInternalId' => $userDoc->getSequence(),
                    'providerType' => MESSAGE_TYPE_EMAIL,
                    'identifier' => $email,
                ]));
            }

            return [$userDoc, $newTarget];
        } catch (Duplicate) {
            throw new Exception(Exception::USER_ALREADY_EXISTS);
        }
    }

    /**
     * Attach the provider email to a user created without one (e.g. an
     * anonymous account being linked). Never downgrades an already-verified
     * user. Mutates `$user` in place; persisted by the caller.
     */
    private function backfillEmail(User $user, string $providerEmail, bool $isVerified, Database $dbForProject, Document $project, array $plan, Authorization $authorization): void
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

        try {
            $dbForProject->createDocument('targets', new Document([
                '$permissions' => [
                    Permission::read(Role::user($user->getId())),
                    Permission::update(Role::user($user->getId())),
                    Permission::delete(Role::user($user->getId())),
                ],
                'userId' => $user->getId(),
                'userInternalId' => $user->getSequence(),
                'providerType' => MESSAGE_TYPE_EMAIL,
                'identifier' => $email,
            ]));
        } catch (Duplicate) {
            // The identifier unique index spans all users. Persisting the email while another
            // user owns the target would leave this user unreachable by email messaging.
            $existingTarget = $authorization->skip(fn () => $dbForProject->findOne('targets', [
                Query::equal('identifier', [$email]),
            ]));
            if ($existingTarget->isEmpty() || $existingTarget->getAttribute('userInternalId') !== $user->getSequence()) {
                throw new Exception(Exception::USER_ALREADY_EXISTS);
            }
        }
    }

    /**
     * Create the (provider, sub) identity for the user, or refresh its stored
     * access token. Guards against attaching an email already bound to
     * another user's identity.
     */
    private function upsertIdentity(User $user, string $provider, string $sub, string $providerEmail, string $accessToken, Database $dbForProject, Authorization $authorization, ?Document $newUser, ?Document $newTarget): void
    {
        $identity = $dbForProject->findOne('identities', [
            Query::equal('userInternalId', [$user->getSequence()]),
            Query::equal('provider', [$provider]),
            Query::equal('providerUid', [$sub]),
        ]);

        if ($identity->isEmpty()) {
            // Before creating the identity, check if the email is already associated with another user
            if (!empty($providerEmail)) {
                $identitiesWithMatchingEmail = $dbForProject->find('identities', [
                    Query::equal('providerEmail', [$providerEmail]),
                    Query::notEqual('userInternalId', $user->getSequence()),
                ]);
                if (!empty($identitiesWithMatchingEmail)) {
                    throw new Exception(Exception::GENERAL_BAD_REQUEST);
                    /** Return a generic bad request to prevent exposing existing accounts */
                }
            }

            try {
                $dbForProject->createDocument('identities', new Document([
                    '$id' => ID::unique(),
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::update(Role::user($user->getId())),
                        Permission::delete(Role::user($user->getId())),
                    ],
                    'userInternalId' => $user->getSequence(),
                    'userId' => $user->getId(),
                    'provider' => $provider,
                    'providerUid' => $sub,
                    'providerEmail' => $providerEmail,
                    'providerAccessToken' => $accessToken,
                ]));
            } catch (Duplicate) {
                // The (provider, providerUid) unique index guards the same identity being connected to two users.
                // A request that lost the race must not leave behind the user it just created.
                if ($newUser !== null) {
                    $authorization->skip(function () use ($dbForProject, $newUser, $newTarget) {
                        if ($newTarget !== null) {
                            $dbForProject->deleteDocument('targets', $newTarget->getId());
                        }
                        $dbForProject->deleteDocument('users', $newUser->getId());
                    });
                }
                throw new Exception(Exception::USER_ALREADY_EXISTS);
            }
        } elseif (!empty($accessToken)) {
            $dbForProject->updateDocument('identities', $identity->getId(), new Document([
                'providerAccessToken' => $accessToken,
            ]));
        }
    }

    /**
     * Parse and optionally canonicalize the provider email, producing the
     * email to store, the list of equivalent emails to match against, and the
     * email metadata attributes.
     *
     * @return array{string, string[], array<string, mixed>, bool}
     */
    private function parseEmail(string $providerEmail, bool $isVerified, Document $project, array $plan): array
    {
        try {
            $parsedEmail = new Email($providerEmail);
            $canonical = $parsedEmail->getCanonical();
            $canonicalize = (
                $project->getId() === 'console'
                || ($plan['supportsCanonicalEmailValidation'] ?? false)
            )
                && ($project->getAttribute('auths', [])['canonicalEmails'] ?? false)
                && $isVerified;
            // Keep the provider domain for delivery (e.g. live.com must
            // not become outlook.com). Still canonicalize the local part
            // and include the full provider canonical in collision lookups.
            if ($canonicalize) {
                $canonicalLocal = \explode('@', $canonical, 2)[0];
                $providerDomain = \explode('@', \mb_strtolower($providerEmail), 2)[1] ?? '';
                $email = $canonicalLocal . '@' . $providerDomain;
            } else {
                $email = $providerEmail;
            }
            $emails = \array_values(\array_unique(\array_filter([$email, $providerEmail, $canonical])));
            $emailMetadata = [
                'emailCanonical' => $canonical,
                'emailIsCanonical' => \mb_strtolower($email) === $canonical,
                'emailIsCorporate' => $parsedEmail->isCorporate(),
                'emailIsDisposable' => $parsedEmail->isDisposable(),
                'emailIsFree' => $parsedEmail->isFree(),
            ];

            return [$email, $emails, $emailMetadata, $canonicalize];
        } catch (\Throwable) {
            throw new Exception(Exception::GENERAL_INVALID_EMAIL);
        }
    }

    /**
     * Enforce the project's email policies (disposable, canonical, free,
     * corporate), each gated on plan support.
     */
    private function assertEmailPolicy(array $emailMetadata, string $email, bool $canonicalize, Document $project, array $plan): void
    {
        if (empty($email)) {
            return;
        }

        if ((($project->getId() === 'console') || ($plan['supportsDisposableEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['disposableEmails'] ?? false) && $emailMetadata['emailIsDisposable']) {
            throw new Exception(Exception::USER_EMAIL_DISPOSABLE);
        }

        // When $canonicalize is true we already applied delivery-safe
        // local-part normalization while preserving the provider domain.
        if ((($project->getId() === 'console') || ($plan['supportsCanonicalEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['canonicalEmails'] ?? false) && $emailMetadata['emailIsCanonical'] === false && !$canonicalize) {
            throw new Exception(Exception::USER_EMAIL_NOT_CANONICAL);
        }

        if ((($project->getId() === 'console') || ($plan['supportsFreeEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['freeEmails'] ?? false) && $emailMetadata['emailIsFree']) {
            throw new Exception(Exception::USER_EMAIL_FREE);
        }

        if ((($project->getId() === 'console') || ($plan['supportsCorporateEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['corporateEmails'] ?? false) && !$emailMetadata['emailIsCorporate']) {
            throw new Exception(Exception::USER_EMAIL_NOT_CORPORATE);
        }
    }
}
