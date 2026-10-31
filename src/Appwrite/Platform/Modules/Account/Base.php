<?php

namespace Appwrite\Platform\Modules\Account;

use Ahc\Jwt\JWT;
use Appwrite\Auth\MFA\Type;
use Appwrite\Bus\Events\SessionCreated;
use Appwrite\Detector\Detector;
use Appwrite\Event\Event;
use Appwrite\Event\Realtime;
use Appwrite\Extend\Exception;
use Appwrite\Geo\Geo;
use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Auth\Proofs\Code as ProofsCode;
use Utopia\Auth\Proofs\Token as ProofsToken;
use Utopia\Auth\Store;
use Utopia\Bus\Bus;
use Utopia\Config\Config;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Locale\Locale;
use Utopia\System\System;

abstract class Base extends Action
{
    protected const OAUTH_DEFAULT_SUCCESS = '/auth/oauth2/success';
    protected const OAUTH_DEFAULT_FAILURE = '/auth/oauth2/failure';

    protected function createSession(string $userId, string $secret, Request $request, Response $response, User $user, Database $dbForProject, Document $project, array $platform, Locale $locale, Geo $geo, Event $queueForEvents, Bus $bus, Store $store, ProofsToken $proofForToken, ProofsCode $proofForCode, bool $domainVerification, ?string $cookieDomain, Authorization $authorization, Mqtt $mqtt, Realtime $queueForRealtime): void
    {
        // Attempt to decode secret as a JWT (used by OAuth2 token flow to carry provider info)
        $oauthProvider = null;
        try {
            $jwtDecoder = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 60, 0);
            $payload = $jwtDecoder->decode($secret);

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
        $session = $dbForProject->withTransaction(function () use ($dbForProject, $authorization, $verifiedToken, $session, $user, $verification, $project, &$claimed) {
            $claimed = false; // A retried attempt starts over
            $locked = $authorization->skip(fn () => $dbForProject->getDocument('users', $user->getId(), forUpdate: true));

            if (!$authorization->skip(fn () => $dbForProject->deleteDocument('tokens', $verifiedToken->getId()))) {
                throw new Exception(Exception::USER_INVALID_TOKEN);
            }

            // Proving an unverified email or phone claims the account, so whoever registered it keeps no other way in
            if ($verification !== null && !$locked->getAttribute($verification, false)) {
                $proven = $verification === 'phoneVerification' ? MESSAGE_TYPE_SMS : MESSAGE_TYPE_EMAIL;
                $authorization->skip(fn () => User::revokeCredentials($dbForProject, $user, $project, $proven));
                $claimed = true;
            }

            $session = $dbForProject->createDocument('sessions', $session
                ->setAttribute('$permissions', [
                    Permission::read(Role::user($user->getId())),
                    Permission::update(Role::user($user->getId())),
                    Permission::delete(Role::user($user->getId())),
                ]));

            if ($verification !== null) {
                $dbForProject->updateDocument('users', $user->getId(), new Document([$verification => true]));
            }

            return $session;
        });

        if ($verification !== null) {
            $user->setAttribute($verification, true);
        }

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

        $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

        $session
            ->setAttribute('current', true)
            ->setAttribute('countryName', $countryName)
            ->setAttribute('expire', $expire)
            ->setAttribute('secret', $encoded)
        ;

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('sessionId', $session->getId())
            ->setPayload($response->output($session, Response::MODEL_SESSION), sensitive: ['secret', 'providerAccessToken', 'providerRefreshToken']);

        $response->dynamic($session, Response::MODEL_SESSION);
    }
}
