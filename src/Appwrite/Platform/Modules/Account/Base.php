<?php

namespace Appwrite\Platform\Modules\Account;

use Ahc\Jwt\JWT;
use Appwrite\Auth\AuthDevice;
use Appwrite\Auth\EncryptionKey;
use Appwrite\Auth\MFA\Type;
use Appwrite\Bus\Events\SessionCreated;
use Appwrite\Detector\Detector;
use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Extend\Exception;
use Appwrite\Geo\Geo;
use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Auth\Proofs\Code as ProofsCode;
use Utopia\Auth\Proofs\Password as ProofsPassword;
use Utopia\Auth\Proofs\Token as ProofsToken;
use Utopia\Auth\Store;
use Utopia\Bus\Bus;
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
use Utopia\Emails\Email;
use Utopia\Locale\Locale;

abstract class Base extends Action
{
    protected const OAUTH_DEFAULT_SUCCESS = '/auth/oauth2/success';
    protected const OAUTH_DEFAULT_FAILURE = '/auth/oauth2/failure';

    protected function createSession(string $userId, string $secret, Request $request, Response $response, User $user, Database $dbForProject, Document $project, array $platform, Locale $locale, Geo $geo, Event $queueForEvents, Bus $bus, Store $store, ProofsToken $proofForToken, ProofsCode $proofForCode, bool $domainVerification, ?string $cookieDomain, Authorization $authorization, Mqtt $mqtt, Realtime $queueForRealtime, ?Document $authDevice, DeletePublisher $publisherForDeletes): void
    {
        // Attempt to decode secret as a JWT (used by OAuth2 token flow to carry provider info)
        $oauthProvider = null;
        try {
            $payload = EncryptionKey::decode($secret, maxAge: 60);

            if (empty($payload['provider'])) {
                throw new Exception(Exception::USER_INVALID_TOKEN);
            }

            $oauthProvider = $payload['provider'];
            $secret = $payload['secret'];
        } catch (\Ahc\Jwt\JWTException) {
            // Not a JWT — use secret as-is (non-OAuth flows)
        }

        /** @var User $userFromRequest */
        $userFromRequest = $authorization->skip(fn () => $dbForProject->getDocument('users', $userId));

        if ($userFromRequest->isEmpty()) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        $verifiedToken = $userFromRequest->tokenVerify(null, $secret, $proofForToken)
            ?: $userFromRequest->tokenVerify(null, $secret, $proofForCode);

        if (!$verifiedToken) {
            // Could mean invalid/expired JWT, or expired secret
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        // OAuth2 tokens must have a provider from the JWT
        if ($verifiedToken->getAttribute('type') === TOKEN_TYPE_OAUTH2 && $oauthProvider === null) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        $user->setAttributes($userFromRequest->getArrayCopy());

        $duration = $project->getAttribute('auths', [])['duration'] ?? TOKEN_EXPIRATION_LOGIN_LONG;
        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $sessionSecret = $proofForToken->generate();

        $factor = (match ($verifiedToken->getAttribute('type')) {
            TOKEN_TYPE_MAGIC_URL,
            TOKEN_TYPE_OAUTH2,
            TOKEN_TYPE_EMAIL => Type::EMAIL,
            TOKEN_TYPE_PHONE => Type::PHONE,
            TOKEN_TYPE_GENERIC => 'token',
            TOKEN_TYPE_PASSKEY => Type::PASSKEY,
            default => throw new Exception(Exception::USER_INVALID_TOKEN)
        });

        $provider = match ($verifiedToken->getAttribute('type')) {
            TOKEN_TYPE_MAGIC_URL => SESSION_PROVIDER_MAGIC_URL,
            TOKEN_TYPE_PHONE => SESSION_PROVIDER_PHONE,
            TOKEN_TYPE_OAUTH2 => $oauthProvider,
            TOKEN_TYPE_PASSKEY => SESSION_PROVIDER_PASSKEY,
            default => SESSION_PROVIDER_TOKEN,
        };

        // For OAuth2 tokens, carry the provider credentials from the identity onto the session,
        // mirroring the cookie-based createOAuth2Session flow.
        $providerCredentials = [];
        if ($verifiedToken->getAttribute('type') === TOKEN_TYPE_OAUTH2) {
            $identity = $authorization->skip(fn () => $dbForProject->findOne('identities', [
                Query::equal('userInternalId', [$user->getSequence()]),
                Query::equal('provider', [$oauthProvider]),
            ]));

            if (!$identity->isEmpty()) {
                $providerCredentials = [
                    'providerUid' => $identity->getAttribute('providerUid'),
                    'providerAccessToken' => $identity->getAttribute('providerAccessToken'),
                    'providerRefreshToken' => $identity->getAttribute('providerRefreshToken'),
                    'providerAccessTokenExpiry' => $identity->getAttribute('providerAccessTokenExpiry'),
                ];
            }
        }

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
                'secret' => $proofForToken->hash($sessionSecret), // One way hash encryption to protect DB leak
                'userAgent' => $request->getUserAgent('UNKNOWN'),
                'ip' => $request->getIP(),
                'factors' => [$factor],
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
                'expire' => DateTime::addSeconds(new \DateTime(), $duration)
            ],
            $providerCredentials,
            $detector->getOS(),
            $detector->getClient(),
            $detector->getDevice()
        ));

        if ($verifiedToken->getAttribute('type') === TOKEN_TYPE_OAUTH2) {
            $identity = $authorization->skip(fn () => $dbForProject->findOne('identities', [
                Query::equal('provider', [$oauthProvider]),
                Query::equal('userInternalId', [$user->getSequence()]),
            ]));

            if ($identity->isEmpty()) {
                throw new Exception(Exception::USER_INVALID_TOKEN);
            }

            $session
                ->setAttribute('providerUid', $identity->getAttribute('providerUid'))
                ->setAttribute('providerRefreshToken', $identity->getAttribute('providerRefreshToken'))
                ->setAttribute('providerAccessToken', $identity->getAttribute('providerAccessToken'))
                ->setAttribute('providerAccessTokenExpiry', $identity->getAttribute('providerAccessTokenExpiry'))
                ->setAttribute('factors', \array_merge($session->getAttribute('factors', []), ['oauth2']));
        }

        // A user-verified passkey proves possession and a biometric or PIN, so it counts as both factors
        if ($verifiedToken->getAttribute('type') === TOKEN_TYPE_PASSKEY) {
            $session
                ->setAttribute('factors', [Type::PASSKEY, Type::USER_VERIFICATION])
                ->setAttribute('mfaUpdatedAt', DateTime::now());
        }

        $authorization->addRole(Role::user($user->getId())->toString());

        // Magic URL and email OTP prove the email, a phone OTP proves the phone
        $verification = match ($verifiedToken->getAttribute('type')) {
            TOKEN_TYPE_MAGIC_URL, TOKEN_TYPE_EMAIL => 'emailVerification',
            TOKEN_TYPE_PHONE => 'phoneVerification',
            default => null,
        };

        // Claim the token and issue the session together: of concurrent exchanges only the one that deletes the token
        // gets a session, and a failed insert rolls the claim back so the token can be retried. The user row lock
        // orders this against an address change, which deletes the token and resets the verification it proves
        $claimed = false;
        $session = $dbForProject->withTransaction(function () use ($dbForProject, $authorization, $verifiedToken, $session, $user, $verification, $project, &$claimed, $mqtt) {
            $claimed = false; // A retried attempt starts over
            /** @var User $locked */
            $locked = $authorization->skip(fn () => $dbForProject->getDocument('users', $user->getId(), forUpdate: true));

            if (!$authorization->skip(fn () => $dbForProject->deleteDocument('tokens', $verifiedToken->getId()))) {
                throw new Exception(Exception::USER_INVALID_TOKEN);
            }

            // Proving an unverified email or phone claims the account, so whoever registered it keeps no other way in.
            // MFA in force is left alone: the address alone must not discard a second factor, so the session stays short of
            // factors and the address stays unverified until the account is signed into with that factor
            $guarded = $locked->getMinimumFactors() > 1;
            if ($verification !== null && !$locked->getAttribute($verification, false) && !$guarded) {
                $proven = $verification === 'phoneVerification' ? MESSAGE_TYPE_SMS : MESSAGE_TYPE_EMAIL;
                $authorization->skip(fn () => User::revokeCredentials($dbForProject, $user, $project, $proven, $mqtt));
                $claimed = true;
            }

            $session = $dbForProject->createDocument('sessions', $session
                ->setAttribute('$permissions', [
                    Permission::read(Role::user($user->getId())),
                    Permission::update(Role::user($user->getId())),
                    Permission::delete(Role::user($user->getId())),
                ]));

            if ($verification !== null && !$guarded) {
                $dbForProject->updateDocument('users', $user->getId(), new Document([$verification => true]));
                $user->setAttribute($verification, true);
            }

            return $session;
        });

        $dbForProject->purgeCachedDocument('users', $user->getId());

        if ($claimed) {
            try {
                $mqtt->revoke($project->getId(), $user->getId());
            } catch (\Throwable $error) {
                Console::error('Failed to revoke MQTT connections: ' . $error->getMessage());
            }

            // Sent before the request's own event, which would otherwise reach the registrant with the claimant's data
            (clone $queueForRealtime)
                ->reset()
                ->setProject($project)
                ->setEvent('users.[userId].sessions.delete')
                ->setParam('userId', $user->getId())
                ->trigger();
        }

        $bus->dispatch(new SessionCreated(
            user: $user->getArrayCopy(),
            project: $project->getArrayCopy(),
            session: $session->getArrayCopy(),
            locale: $locale->default,
        ));

        $encoded = $store
            ->setProperty('id', $user->getId())
            ->setProperty('secret', $sessionSecret)
            ->encode();

        if (!$domainVerification) {
            $response->addHeader('X-Fallback-Cookies', \json_encode([$store->getKey() => $encoded]));
        }

        $expire = DateTime::addSeconds(new \DateTime(), $duration);
        $protocol = $request->getProtocol();

        $response
            ->addCookie($store->getKey() . '_legacy', $encoded, (new \DateTime($expire))->getTimestamp(), '/', $cookieDomain, ('https' == $protocol), true, null)
            ->addCookie($store->getKey(), $encoded, (new \DateTime($expire))->getTimestamp(), '/', $cookieDomain, ('https' == $protocol), true, Config::getParam('cookieSamesite'))
            ->setStatusCode(Response::STATUS_CODE_CREATED);

        // The session joins the account switching device and becomes its active one, before the
        // response goes out so the device reads consistently on the client's next request
        if ($authDevice !== null) {
            $authDevice = AuthDevice::attach($dbForProject, $authorization, $authDevice, $session, $publisherForDeletes, $project, $mqtt);
            AuthDevice::refreshCookie($request, $response, $project, $authDevice, $protocol, $domainVerification, $cookieDomain);
        }

        $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

        $session
            ->setAttribute('current', true)
            ->setAttribute('countryName', $countryName)
            ->setAttribute('userName', $user->getAttribute('name', ''))
            ->setAttribute('expire', $expire)
            ->setAttribute('secret', $encoded)
        ;

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('sessionId', $session->getId())
            ->setPayload($response->output($session, Response::MODEL_SESSION), sensitive: ['secret', 'providerAccessToken', 'providerRefreshToken']);

        $response->dynamic($session, Response::MODEL_SESSION);
    }


    /**
     * Resolve the ID token to a user: an existing identity wins, then a
     * verified-email match adopts the account, otherwise a new user is
     * created. Mutates `$user` in place. Shared by every external identity sign-in; mirrors the browser OAuth2 flow.
     *
     * @return array{?Document, ?Document} the user and email target created by
     *     this request, if any, so they can be rolled back later
     */
    protected function resolveUser(User $user, string $provider, string $sub, string $providerEmail, bool $isVerified, string $name, Database $dbForProject, Document $project, array $plan, ProofsPassword $proofForPassword, Authorization $authorization, Mqtt $mqtt, Realtime $queueForRealtime): array
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
                if (!$user->getAttribute('emailVerification', false)) {
                    $authorization->skip(fn () => User::revokeCredentials($dbForProject, $user, $project, mqtt: $mqtt));
                    $dbForProject->purgeCachedDocument('users', $user->getId());

                    try {
                        $mqtt->revoke($project->getId(), $user->getId());
                    } catch (\Throwable $error) {
                        Console::error('Failed to revoke MQTT connections: ' . $error->getMessage());
                    }

                    // Sent before the request's own event, which would otherwise reach the registrant with the claimant's data
                    (clone $queueForRealtime)
                        ->reset()
                        ->setProject($project)
                        ->setEvent('users.[userId].sessions.delete')
                        ->setParam('userId', $user->getId())
                        ->trigger();
                }
                // The provider verified this address, or its canonical form on the same domain
                if ((new Email($user->getAttribute('email')))->getDomain() === (new Email($providerEmail))->getDomain()) {
                    $user->setAttribute('emailVerification', true);
                }
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
            $userId = Id::unique();
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
     * Create the (provider, sub) identity for the user with the given attributes, or
     * apply the changes to the one that exists. Guards against attaching an email
     * already bound to another user's identity.
     *
     * @param array<string, mixed> $attributes Provider-specific attributes stored with a new identity
     * @param array<string, mixed> $changes Attributes refreshed on an existing identity
     */
    protected function upsertIdentity(User $user, string $provider, string $sub, string $providerEmail, array $attributes, array $changes, Database $dbForProject, Authorization $authorization, ?Document $newUser, ?Document $newTarget): void
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
                $dbForProject->createDocument('identities', new Document(\array_merge([
                    '$id' => Id::unique(),
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
                ], $attributes)));
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
            return;
        }

        if ($changes !== []) {
            $dbForProject->updateDocument('identities', $identity->getId(), new Document($changes));
        }
    }

    /**
     * Parse and optionally canonicalize the provider email, producing the
     * email to store, the list of equivalent emails to match against, and the
     * email metadata attributes.
     *
     * @return array{string, string[], array<string, mixed>, bool}
     */
    protected function parseEmail(string $providerEmail, bool $isVerified, Document $project, array $plan): array
    {
        try {
            $parsedEmail = new Email($providerEmail);
            $canonical = $parsedEmail->getCanonical();
            $canonicalize = (
                $project->getId() === 'console'
                || empty($plan)
                || ($plan['supportsCanonicalEmailValidation'] ?? false)
            )
                && ($project->getAttribute('auths', [])['canonicalEmails'] ?? false)
                && $isVerified;
            // Keep the provider domain for delivery (e.g. live.com must
            // not become outlook.com). Still canonicalize the local part.
            if ($canonicalize) {
                $canonicalLocal = \explode('@', $canonical, 2)[0];
                $providerDomain = \explode('@', \mb_strtolower($providerEmail), 2)[1] ?? '';
                $email = $canonicalLocal . '@' . $providerDomain;
            } else {
                $email = $providerEmail;
            }
            // Match existing users by the provider's own address only, never by a cross-domain canonical form
            $emails = \array_values(\array_unique(\array_filter([$email, $providerEmail, \mb_strtolower($providerEmail)])));
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
    protected function assertEmailPolicy(array $emailMetadata, string $email, bool $canonicalize, Document $project, array $plan): void
    {
        if (empty($email)) {
            return;
        }

        if ((($project->getId() === 'console') || empty($plan) || ($plan['supportsDisposableEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['disposableEmails'] ?? false) && $emailMetadata['emailIsDisposable']) {
            throw new Exception(Exception::USER_EMAIL_DISPOSABLE);
        }

        // When $canonicalize is true we already applied delivery-safe
        // local-part normalization while preserving the provider domain.
        if ((($project->getId() === 'console') || empty($plan) || ($plan['supportsCanonicalEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['canonicalEmails'] ?? false) && $emailMetadata['emailIsCanonical'] === false && !$canonicalize) {
            throw new Exception(Exception::USER_EMAIL_NOT_CANONICAL);
        }

        if ((($project->getId() === 'console') || empty($plan) || ($plan['supportsFreeEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['freeEmails'] ?? false) && $emailMetadata['emailIsFree']) {
            throw new Exception(Exception::USER_EMAIL_FREE);
        }

        if ((($project->getId() === 'console') || empty($plan) || ($plan['supportsCorporateEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['corporateEmails'] ?? false) && !$emailMetadata['emailIsCorporate']) {
            throw new Exception(Exception::USER_EMAIL_NOT_CORPORATE);
        }
    }
}
