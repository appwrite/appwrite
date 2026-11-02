<?php

use Ahc\Jwt\JWT;
use Appwrite\Auth\MFA\Type;
use Appwrite\Auth\Validator\Phone;
use Appwrite\Bus\Events\SessionCreated;
use Appwrite\Detector\Detector;
use Appwrite\Event\Event;
use Appwrite\Event\Realtime;
use Appwrite\Extend\Exception;
use Appwrite\Geo\Geo;
use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Database\Validator\Queries\Identities;
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
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\UID;
use Utopia\Emails\Email;
use Utopia\Http\Http;
use Utopia\Locale\Locale;
use Utopia\System\System;
use Utopia\Validator\Boolean;

$oauthDefaultSuccess = '/auth/oauth2/success';
$oauthDefaultFailure = '/auth/oauth2/failure';

$createSession = function (string $userId, string $secret, Request $request, Response $response, User $user, Database $dbForProject, Document $project, array $platform, Locale $locale, Geo $geo, Event $queueForEvents, Bus $bus, Store $store, ProofsToken $proofForToken, ProofsCode $proofForCode, bool $domainVerification, ?string $cookieDomain, Authorization $authorization, Mqtt $mqtt, Realtime $queueForRealtime) {

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

    /** @var Appwrite\Utopia\Database\Documents\User $userFromRequest */
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
};

Http::get('/v1/account/identities')
    ->desc('List identities')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('sdk', new Method(
        namespace: 'account',
        group: 'identities',
        name: 'listIdentities',
        description: '/docs/references/account/list-identities.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_IDENTITY_LIST,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('queries', [], new Identities(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Identities::ALLOWED_ATTRIBUTES), true)
    ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
    ->inject('response')
    ->inject('targetUser')
    ->inject('dbForProject')
    ->action(function (array $queries, bool $includeTotal, Response $response, User $targetUser, Database $dbForProject) {

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $queries[] = Query::equal('userInternalId', [$targetUser->getSequence()]);

        $cursor = Query::getCursorQueries($queries, false);
        $cursor = \reset($cursor);

        if ($cursor !== false) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $identityId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('identities', $identityId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Identity '{$identityId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)->filters;
        try {
            $results = $dbForProject->find('identities', $queries);
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        }
        $total = $includeTotal ? $dbForProject->count('identities', $filterQueries, APP_LIMIT_COUNT) : 0;

        $response->dynamic(new Document([
            'identities' => $results,
            'total' => $total,
        ]), Response::MODEL_IDENTITY_LIST);
    });

Http::delete('/v1/account/identities/:identityId')
    ->desc('Delete identity')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('event', 'users.[userId].identities.[identityId].delete')
    ->label('audits.event', 'identity.delete')
    ->label('audits.resource', 'identity/{request.$identityId}')
    ->label('audits.userId', '{user.$id}')
    ->label('sdk', new Method(
        namespace: 'account',
        group: 'identities',
        name: 'deleteIdentity',
        description: '/docs/references/account/delete-identity.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('identityId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Identity ID.', false, ['dbForProject'])
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $identityId, Response $response, Database $dbForProject, Event $queueForEvents) {

        $identity = $dbForProject->getDocument('identities', $identityId);

        if ($identity->isEmpty()) {
            throw new Exception(Exception::USER_IDENTITY_NOT_FOUND);
        }

        $dbForProject->deleteDocument('identities', $identityId);

        $queueForEvents
            ->setParam('userId', $identity->getAttribute('userId'))
            ->setParam('identityId', $identity->getId())
            ->setPayload($response->output($identity, Response::MODEL_IDENTITY));

        $response->noContent();
    });
