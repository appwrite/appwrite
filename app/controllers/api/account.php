<?php

use Ahc\Jwt\JWT;
use Appwrite\Auth\Key;
use Appwrite\Auth\MFA\Type;
use Appwrite\Auth\OTPToken;
use Appwrite\Auth\Validator\Password;
use Appwrite\Auth\Validator\PasswordDictionary;
use Appwrite\Auth\Validator\PasswordHistory;
use Appwrite\Auth\Validator\PasswordPwned;
use Appwrite\Auth\Validator\PasswordStrength;
use Appwrite\Auth\Validator\PersonalData;
use Appwrite\Auth\Validator\Phone;
use Appwrite\Bus\Events\SessionCreated;
use Appwrite\Detector\Detector;
use Appwrite\Event\Event;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Message\Mail as MailMessage;
use Appwrite\Event\Message\Messaging as MessagingMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Event\Publisher\Mail as MailPublisher;
use Appwrite\Event\Publisher\Messaging as MessagingPublisher;
use Appwrite\Event\Realtime;
use Appwrite\Extend\Exception;
use Appwrite\Geo\Geo;
use Appwrite\Hooks\Hooks;
use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Network\Validator\Redirect;
use Appwrite\Presences\State as PresenceState;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\SDK\Specification\Validator\PasswordFormat;
use Appwrite\Template\Template;
use Appwrite\Usage\Context;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Database\Validator\Queries\Identities;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Auth\Proofs\Code as ProofsCode;
use Utopia\Auth\Proofs\Password as ProofsPassword;
use Utopia\Auth\Proofs\Phrase;
use Utopia\Auth\Proofs\Token as ProofsToken;
use Utopia\Auth\Store;
use Utopia\Bus\Bus;
use Utopia\Client\Client;
use Utopia\Config\Config;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
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
use Utopia\Emails\Validator\Email as EmailValidator;
use Utopia\Http\Http;
use Utopia\Locale\Locale;
use Utopia\Messaging\Adapter\SMS\GEOSMS\CallingCode;
use Utopia\Storage\Validator\FileName;
use Utopia\System\System;
use Utopia\Validator\AllOf;
use Utopia\Validator\Assoc;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;
use Utopia\Validator\Validator;
use Utopia\Validator\WhiteList;

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

Http::patch('/v1/account/prefs')
    ->desc('Update preferences')
    ->groups(['api', 'account'])
    ->label('event', 'users.[userId].update.prefs')
    ->label('scope', 'account')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('sdk', new Method(
        namespace: 'account',
        group: 'account',
        name: 'updatePrefs',
        description: '/docs/references/account/update-prefs.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_USER,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('prefs', [], new Assoc(), 'Prefs key-value JSON object.', example: '{"language":"en","timezone":"UTC","darkTheme":true}')
    ->inject('requestTimestamp')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (array $prefs, ?\DateTime $requestTimestamp, Response $response, Document $user, Database $dbForProject, Event $queueForEvents) {

        $user->setAttribute('prefs', $prefs);

        $user = $dbForProject->updateDocument('users', $user->getId(), $user);

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_ACCOUNT);
    });

Http::patch('/v1/account/status')
    ->desc('Update status')
    ->groups(['api', 'account'])
    ->label('event', 'users.[userId].update.status')
    ->label('scope', 'account')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('sdk', new Method(
        namespace: 'account',
        group: 'account',
        name: 'updateStatus',
        description: '/docs/references/account/update-status.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_USER,
            )
        ],
        contentType: ContentType::JSON,
    ))
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('store')
    ->inject('domainVerification')
    ->inject('cookieDomain')
    ->inject('project')
    ->inject('mqtt')
    ->inject('queueForRealtime')
    ->inject('usage')
    ->action(function (Request $request, Response $response, Document $user, Database $dbForProject, Event $queueForEvents, Store $store, bool $domainVerification, ?string $cookieDomain, Document $project, Mqtt $mqtt, Realtime $queueForRealtime, Context $usage) {
        $user = $dbForProject->updateDocument('users', $user->getId(), new Document(['status' => false]));

        try {
            $mqtt->revoke($project->getId(), $user->getId());
        } catch (\Throwable $error) {
            Console::error('Failed to revoke MQTT connections: ' . $error->getMessage());
        }

        (new PresenceState())->deleteForUser($dbForProject, $project, $user, $queueForRealtime, $usage);

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setPayload($response->output($user, Response::MODEL_ACCOUNT));

        if (!$domainVerification) {
            $response->addHeader('X-Fallback-Cookies', \json_encode([]));
        }

        $secure = $request->getProtocol() === 'https';
        $response
            ->addCookie($store->getKey() . '_legacy', '', \time() - 3600, '/', $cookieDomain, $secure, true, null)
            ->addCookie($store->getKey(), '', \time() - 3600, '/', $cookieDomain, $secure, true, Config::getParam('cookieSamesite'))
        ;

        $response->dynamic($user, Response::MODEL_ACCOUNT);
    });

Http::post('/v1/account/recovery')
    ->desc('Create password recovery')
    ->groups(['api', 'account'])
    ->label('scope', 'sessions.write')
    ->label('event', 'users.[userId].recovery.[tokenId].create')
    ->label('audits.event', 'recovery.create')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('audits.userId', '{response.userId}')
    ->label('sdk', new Method(
        namespace: 'account',
        group: 'recovery',
        name: 'createRecovery',
        description: '/docs/references/account/create-recovery.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_TOKEN,
            )
        ],
        contentType: ContentType::JSON,
    ))
    ->label('abuse-limit', 10)
    ->label('abuse-key', ['url:{url},email:{param-email}', 'url:{url},ip:{ip}'])
    ->param('email', '', new EmailValidator(), 'User email.')
    ->param('url', '', fn ($redirectValidator) => $redirectValidator, 'URL to redirect the user back to your app from the recovery email. Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', false, ['redirectValidator'])
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('platform')
    ->inject('locale')
    ->inject('publisherForMails')
    ->inject('queueForEvents')
    ->inject('proofForToken')
    ->inject('authorization')
    ->inject('apiKey')
    ->action(function (string $email, string $url, Request $request, Response $response, Database $dbForProject, Document $project, array $platform, Locale $locale, MailPublisher $publisherForMails, Event $queueForEvents, ProofsToken $proofForToken, Authorization $authorization, ?Key $apiKey) {

        if (empty(System::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception(Exception::GENERAL_SMTP_DISABLED, 'SMTP Disabled');
        }

        $email = \strtolower($email);

        $profile = $dbForProject->findOne('users', [
            Query::equal('email', [$email]),
        ]);


        $deliverable = !$profile->isEmpty() && $profile->getAttribute('status') !== false;

        $userId = $deliverable ? $profile->getId() : Id::unique();

        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), TOKEN_EXPIRATION_RECOVERY));

        $secret = $proofForToken->generate();
        $recovery = new Document([
            '$id' => Id::unique(),
            'userId' => $userId,
            'userInternalId' => $deliverable ? $profile->getSequence() : Id::unique(),
            'type' => TOKEN_TYPE_RECOVERY,
            'secret' => $proofForToken->hash($secret), // One way hash encryption to protect DB leak
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        $recovery->setAttribute('$permissions', [
            Permission::read(Role::user($userId)),
            Permission::update(Role::user($userId)),
            Permission::delete(Role::user($userId)),
        ]);

        if ($deliverable) {
            $created = $authorization->skip(fn () => $dbForProject->withTransaction(function () use ($dbForProject, $profile, $email, $recovery) {
                $current = $dbForProject->getDocument('users', $profile->getId(), forUpdate: true);
                if (
                    $current->isEmpty()
                    || $current->getAttribute('status') === false
                    || \strtolower((string) $current->getAttribute('email')) !== $email
                ) {
                    return new Document();
                }

                return $dbForProject->createDocument('tokens', $recovery);
            }));

            if ($created->isEmpty()) {
                $deliverable = false;
                $dummyUserId = Id::unique();
                $recovery
                    ->setAttribute('userId', $dummyUserId)
                    ->setAttribute('userInternalId', Id::unique())
                    ->setAttribute('$permissions', [
                        Permission::read(Role::user($dummyUserId)),
                        Permission::update(Role::user($dummyUserId)),
                        Permission::delete(Role::user($dummyUserId)),
                    ]);
                $recovery = $authorization->skip(fn () => $dbForProject->createDocument('tokens', $recovery));
            } else {
                $recovery = $created;
            }
        } else {
            $recovery = $authorization->skip(fn () => $dbForProject->createDocument('tokens', $recovery));
        }

        if ($deliverable) {
            $dbForProject->purgeCachedDocument('users', $profile->getId());
        } else {
            $authorization->skip(fn () => $dbForProject->deleteDocument('tokens', $recovery->getId()));
        }

        $url = Template::parseURL($url);
        $url['query'] = Template::mergeQuery(((isset($url['query'])) ? $url['query'] : ''), ['userId' => $userId, 'secret' => $secret, 'expire' => $expire]);
        $url = Template::unParseURL($url);

        $projectName = $project->isEmpty()
            ? 'Console'
            : $project->getAttribute('name', '[APP-NAME]');

        if ($project->getId() === 'console') {
            $projectName = $platform['platformName'];
        }

        $body = $locale->getText("emails.recovery.body");
        $subject = $locale->getText("emails.recovery.subject");
        $preview = $locale->getText("emails.recovery.preview");
        $customTemplate =
            $project->getAttribute('templates', [])['email.recovery-' . $locale->default] ??
            $project->getAttribute('templates', [])['email.recovery-' . $locale->fallback] ?? [];
        $smtpBaseTemplate = $project->getAttribute('smtpBaseTemplate', 'email-base');

        $validator = new FileName();
        if (!$validator->isValid($smtpBaseTemplate)) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Invalid template path');
        }

        $bodyTemplate = __DIR__ . '/../../config/locale/templates/' . $smtpBaseTemplate . '.tpl';

        $message = Template::fromFile(__DIR__ . '/../../config/locale/templates/email-inner-base.tpl');
        $message
            ->setParam('{{body}}', $body, escapeHtml: false)
            ->setParam('{{hello}}', $locale->getText("emails.recovery.hello"))
            ->setParam('{{footer}}', $locale->getText("emails.recovery.footer"))
            ->setParam('{{thanks}}', $locale->getText("emails.recovery.thanks"))
            ->setParam('{{buttonText}}', $locale->getText("emails.recovery.buttonText"))
            ->setParam('{{signature}}', $locale->getText("emails.recovery.signature"));
        $body = $message->render();

        $smtp = $project->getAttribute('smtp', []);
        $smtpEnabled = $smtp['enabled'] ?? false;

        $senderEmail = System::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);
        $senderName = System::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server');
        $replyToEmail = '';
        $replyToName = '';
        $smtpConfig = [];

        if ($smtpEnabled) {
            if (!empty($smtp['senderEmail'])) {
                $senderEmail = $smtp['senderEmail'];
            }
            if (!empty($smtp['senderName'])) {
                $senderName = $smtp['senderName'];
            }
            // Includes backwards compatibility: fall back to legacy `replyTo` key
            $smtpReplyToEmail = $smtp['replyToEmail'] ?? $smtp['replyTo'] ?? '';
            if (!empty($smtpReplyToEmail)) {
                $replyToEmail = $smtpReplyToEmail;
            }
            if (!empty($smtp['replyToName'])) {
                $replyToName = $smtp['replyToName'];
            }

            if (!empty($customTemplate)) {
                if (!empty($customTemplate['senderEmail'])) {
                    $senderEmail = $customTemplate['senderEmail'];
                }
                if (!empty($customTemplate['senderName'])) {
                    $senderName = $customTemplate['senderName'];
                }
                // Includes backwards compatibility: fall back to legacy `replyTo` key
                $customReplyToEmail = $customTemplate['replyToEmail'] ?? $customTemplate['replyTo'] ?? '';
                if (!empty($customReplyToEmail)) {
                    $replyToEmail = $customReplyToEmail;
                }
                if (!empty($customTemplate['replyToName'])) {
                    $replyToName = $customTemplate['replyToName'];
                }

                $body = $customTemplate['message'] ?? '';
                $subject = $customTemplate['subject'] ?? $subject;
            }

            $smtpConfig = [
                'host' => $smtp['host'] ?? '',
                'port' => $smtp['port'] ?? '',
                'username' => $smtp['username'] ?? '',
                'password' => $smtp['password'] ?? '',
                'secure' => $smtp['secure'] ?? '',
                'replyToEmail' => $replyToEmail,
                'replyToName' => $replyToName,
                'senderEmail' => $senderEmail,
                'senderName' => $senderName,
            ];
        }

        $emailVariables = [
            'direction' => $locale->getText('settings.direction'),
            // {{user}}, {{redirect}} and {{project}} are required in default and custom templates
            'user' => $deliverable ? $profile->getAttribute('name') : '',
            'redirect' => $url,
            'project' => $projectName,
            // TODO: remove unnecessary team variable from this email
            'team' => ''
        ];

        if ($smtpBaseTemplate === APP_BRANDED_EMAIL_BASE_TEMPLATE) {
            $emailVariables = [
                ...$emailVariables,
                'accentColor' => $platform['accentColor'],
                'logoUrl' => $platform['logoUrl'],
                'twitter' => $platform['twitterUrl'],
                'discord' => $platform['discordUrl'],
                'github' => $platform['githubUrl'],
                'terms' => $platform['termsUrl'],
                'privacy' => $platform['privacyUrl'],
                'platform' => $platform['platformName'],
            ];
        }

        if ($deliverable) {
            $publisherForMails->enqueue(new MailMessage(
                project: $project,
                recipient: $profile->getAttribute('email', ''),
                name: $profile->getAttribute('name', ''),
                subject: $subject,
                template: MAIL_TEMPLATE_RECOVERY,
                bodyTemplate: $bodyTemplate,
                body: $body,
                preview: $preview,
                smtp: $smtpConfig,
                variables: $emailVariables,
                customMailOptions: $project->getId() === 'console' ? ['senderName' => $platform['emailSenderName']] : [],
                platform: $platform,
            ));
        }

        $recovery->setAttribute('secret', $secret);

        $queueForEvents
            ->setParam('userId', $userId)
            ->setParam('tokenId', $recovery->getId())
            ->setUser($deliverable ? $profile : new Document())
            ->setPayload($response->showSensitive(fn () => $response->output($recovery, Response::MODEL_TOKEN)), sensitive: ['secret']);

        // The secret proves control of the inbox or phone; only keys that can already mint tokens through users.write may read it back.
        if ($apiKey !== null && !\in_array('users.write', $apiKey->getScopes())) {
            $recovery->setAttribute('secret', '');
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($recovery, Response::MODEL_TOKEN);
    });

Http::put('/v1/account/recovery')
    ->desc('Update password recovery (confirmation)')
    ->groups(['api', 'account'])
    ->label('scope', 'sessions.write')
    ->label('event', 'users.[userId].recovery.[tokenId].update')
    ->label('audits.event', 'recovery.update')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('audits.userId', '{response.userId}')
    ->label('sdk', new Method(
        namespace: 'account',
        group: 'recovery',
        name: 'updateRecovery',
        description: '/docs/references/account/update-recovery.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_TOKEN,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{param-userId}')
    ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'User ID.', false, ['dbForProject'])
    ->param('secret', '', new Text(256), 'Valid reset token.')
    ->param('password', '', fn ($project, $passwordsDictionary) => new PasswordFormat(new AllOf([new PasswordStrength($project->getAttribute('auths', [])['passwordStrength'] ?? []), new PasswordDictionary($passwordsDictionary, enabled: $project->getAttribute('auths', [])['passwordDictionary'] ?? false, denyList: $project->getAttribute('auths', [])['passwordDictionaryDenyList'] ?? [])], Validator::TYPE_STRING)), 'New user password. Must be between 8 and 256 chars.', false, ['project', 'passwordsDictionary'])
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('queueForEvents')
    ->inject('hooks')
    ->inject('proofForPassword')
    ->inject('proofForToken')
->inject('authorization')
    ->inject('pwnedPasswords')
    ->inject('mqtt')
    ->inject('queueForRealtime')
    ->action(function (string $userId, string $secret, string $password, Response $response, User $user, Database $dbForProject, Document $project, Event $queueForEvents, Hooks $hooks, ProofsPassword $proofForPassword, ProofsToken $proofForToken, Authorization $authorization, PasswordPwned $pwnedPasswords, Mqtt $mqtt, Realtime $queueForRealtime) {
        /** @var Appwrite\Utopia\Database\Documents\User $profile */
        $profile = $dbForProject->getDocument('users', $userId);

        if ($profile->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $verifiedToken = $profile->tokenVerify(TOKEN_TYPE_RECOVERY, $secret, $proofForToken);

        if (!$verifiedToken) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        if ($project->getAttribute('auths', [])['personalDataCheck'] ?? false) {
            $personalDataValidator = new PersonalData($profile->getId(), $profile->getAttribute('email'), $profile->getAttribute('name'), $profile->getAttribute('phone'), denyList: $project->getAttribute('auths', [])['personalDataDenyList'] ?? []);
            if (!$personalDataValidator->isValid($password)) {
                throw new Exception(Exception::USER_PASSWORD_PERSONAL_DATA);
            }
        }

        $authorization->addRole(Role::user($profile->getId())->toString());

        $newPassword = $proofForPassword->hash($password);

        $hash = ProofsPassword::createHash($profile->getAttribute('hash'), $profile->getAttribute('hashOptions'));
        $historyLimit = $project->getAttribute('auths', [])['passwordHistory'] ?? 0;
        $history = $profile->getAttribute('passwordHistory', []);

        if ($historyLimit > 0) {
            $validator = new PasswordHistory($history, $hash);
            if (!$validator->isValid($password)) {
                throw new Exception(Exception::USER_PASSWORD_RECENTLY_USED);
            }

            $history[] = $newPassword;
            $history = array_slice($history, (count($history) - $historyLimit), $historyLimit);
        }

        // null when the policy did not look, false when it looked and found nothing
        $pwnedPolicy = $project->getAttribute('auths', [])['passwordPwned'] ?? [];
        $passwordPwned = ($pwnedPolicy['enabled'] ?? true)
            ? !$pwnedPasswords->isValid($password)
            : null;
        if ($passwordPwned && ($pwnedPolicy['users'] ?? false)) {
            throw new Exception(Exception::USER_PASSWORD_PWNED);
        }

        $hooks->trigger('passwordValidator', [$dbForProject, $project, $password, &$user, true]);

        $sessions = $profile->getAttribute('sessions', []);

        $claimed = false;
        $recoveryDocument = $dbForProject->withTransaction(function () use ($dbForProject, $authorization, $verifiedToken, $profile, $project, $newPassword, $history, $passwordPwned, $proofForPassword, &$claimed, $mqtt) {
            $claimed = false; // A retried attempt starts over
            $document = $dbForProject->getDocument('tokens', $verifiedToken->getId());
            if (!$dbForProject->deleteDocument('tokens', $verifiedToken->getId())) {
                return;
            }

            // Recovery proves an unverified email, so whoever registered it keeps no other way in.
            // MFA in force is left alone, so the new password still needs the second factor to sign in
            /** @var User $locked */
            $locked = $authorization->skip(fn () => $dbForProject->getDocument('users', $profile->getId(), forUpdate: true));
            $guarded = $locked->getMinimumFactors() > 1;
            if (!$locked->getAttribute('emailVerification', false) && !$guarded) {
                $authorization->skip(fn () => User::revokeCredentials($dbForProject, $profile, $project, mqtt: $mqtt));
                $claimed = true;
            }

            $updates = [
                'password' => $newPassword,
                'passwordHistory' => $history,
                'passwordPwned' => $passwordPwned,
                'passwordUpdate' => DateTime::now(),
                'hash' => $proofForPassword->getHash()->getName(),
                'hashOptions' => $proofForPassword->getHash()->getOptions(),
            ];
            if (!$guarded) {
                $updates['emailVerification'] = true;
            }
            $dbForProject->updateDocument('users', $profile->getId(), new Document($updates));

            return $document;
        });

        // Thrown outside the transaction, which would otherwise retry the reuse as a transient failure.
        if ($recoveryDocument === null) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        $profile = $dbForProject->getDocument('users', $profile->getId());
        $profile->setAttribute('sessions', $sessions);
        $user->setAttributes($profile->getArrayCopy());

        $invalidate = $project->getAttribute('auths', default: [])['invalidateSessions'] ?? true;
        if ($invalidate) {
            User::invalidateAuthentication($dbForProject, $profile);
        }

        $dbForProject->purgeCachedDocument('users', $profile->getId());

        try {
            $mqtt->revoke($project->getId(), $profile->getId());
        } catch (\Throwable $error) {
            Console::error('Failed to revoke MQTT connections: ' . $error->getMessage());
        }

        if ($claimed) {
            // Sent before the request's own event, which would otherwise reach the registrant with the claimant's data
            (clone $queueForRealtime)
                ->reset()
                ->setProject($project)
                ->setEvent('users.[userId].sessions.delete')
                ->setParam('userId', $profile->getId())
                ->trigger();
        }

        $queueForEvents
            ->setParam('userId', $profile->getId())
            ->setParam('tokenId', $recoveryDocument->getId())
            ->setPayload($response->showSensitive(fn () => $response->output($recoveryDocument, Response::MODEL_TOKEN)), sensitive: ['secret']);

        $response->dynamic($recoveryDocument, Response::MODEL_TOKEN);
    });

Http::post('/v1/account/recovery/otp')
    ->desc('Create password recovery (OTP)')
    ->groups(['api', 'account'])
    ->label('scope', 'sessions.write')
    ->label('event', 'users.[userId].recovery.[tokenId].create')
    ->label('audits.event', 'recovery.create')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('audits.userId', '{response.userId}')
    ->label('sdk', new Method(
        namespace: 'account',
        group: 'recovery',
        name: 'createRecoveryOTP',
        description: '/docs/references/account/create-recovery-otp.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_TOKEN,
            )
        ],
        contentType: ContentType::JSON,
    ))
    ->label('abuse-limit', 10)
    ->label('abuse-key', ['url:{url},email:{param-email}', 'url:{url},ip:{ip}'])
    ->param('email', '', new EmailValidator(), 'User email.')
    ->param('phrase', false, new Boolean(), 'Toggle for security phrase. If enabled, email will be sent with a randomly generated phrase and the phrase will also be included in the response. Confirming phrases match increases the security of your authentication flow.', true)
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('platform')
    ->inject('locale')
    ->inject('publisherForMails')
    ->inject('queueForEvents')
    ->inject('proofForCode')
    ->inject('authorization')
    ->inject('apiKey')
    ->action(function (string $email, bool $phrase, Request $request, Response $response, Database $dbForProject, Document $project, array $platform, Locale $locale, MailPublisher $publisherForMails, Event $queueForEvents, ProofsCode $proofForCode, Authorization $authorization, ?Key $apiKey) {
        if (empty(System::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception(Exception::GENERAL_SMTP_DISABLED, 'SMTP Disabled');
        }

        $email = \strtolower($email);

        if ($phrase === true) {
            $phrase = (new Phrase())->generate();
        }

        $profile = $dbForProject->findOne('users', [
            Query::equal('email', [$email]),
        ]);

        $deliverable = !$profile->isEmpty() && $profile->getAttribute('status') !== false;

        $userId = $deliverable ? $profile->getId() : Id::unique();

        $secret = $proofForCode->generate();
        $expire = DateTime::addSeconds(new \DateTime(), TOKEN_EXPIRATION_OTP);

        $recovery = new Document([
            '$id' => Id::unique(),
            'userId' => $userId,
            'userInternalId' => $deliverable ? $profile->getSequence() : Id::unique(),
            'type' => TOKEN_TYPE_RECOVERY_OTP,
            'secret' => $proofForCode->hash($secret),
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        $recovery->setAttribute('$permissions', [
            Permission::read(Role::user($userId)),
            Permission::update(Role::user($userId)),
            Permission::delete(Role::user($userId)),
        ]);

        if ($deliverable) {
            $created = $authorization->skip(fn () => $dbForProject->withTransaction(function () use ($dbForProject, $profile, $email, $recovery) {
                $current = $dbForProject->getDocument('users', $profile->getId(), forUpdate: true);
                if (
                    $current->isEmpty()
                    || $current->getAttribute('status') === false
                    || \strtolower((string) $current->getAttribute('email')) !== $email
                ) {
                    return new Document();
                }

                return $dbForProject->createDocument('tokens', $recovery);
            }));

            if ($created->isEmpty()) {
                $deliverable = false;
                $dummyUserId = Id::unique();
                $recovery
                    ->setAttribute('userId', $dummyUserId)
                    ->setAttribute('userInternalId', Id::unique())
                    ->setAttribute('$permissions', [
                        Permission::read(Role::user($dummyUserId)),
                        Permission::update(Role::user($dummyUserId)),
                        Permission::delete(Role::user($dummyUserId)),
                    ]);
                $recovery = $authorization->skip(fn () => $dbForProject->createDocument('tokens', $recovery));
            } else {
                $recovery = $created;
            }
        } else {
            $recovery = $authorization->skip(fn () => $dbForProject->createDocument('tokens', $recovery));
        }

        if ($deliverable) {
            $dbForProject->purgeCachedDocument('users', $profile->getId());
        } else {
            $authorization->skip(fn () => $dbForProject->deleteDocument('tokens', $recovery->getId()));
        }

        if ($deliverable) {
            $subject = $locale->getText('emails.otpRecovery.subject');
            $preview = $locale->getText('emails.otpRecovery.preview');
            $heading = $locale->getText('emails.otpRecovery.heading');

            $customTemplate =
                $project->getAttribute('templates', [])['email.otpRecovery-' . $locale->default] ??
                $project->getAttribute('templates', [])['email.otpRecovery-' . $locale->fallback] ?? [];
            $smtpBaseTemplate = $project->getAttribute('smtpBaseTemplate', 'email-base');

            $validator = new FileName();
            if (!$validator->isValid($smtpBaseTemplate)) {
                throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Invalid template path');
            }

            $bodyTemplate = __DIR__ . '/../../config/locale/templates/' . $smtpBaseTemplate . '.tpl';

            $detector = new Detector($request->getUserAgent('UNKNOWN'));
            $agentOs = $detector->getOS();
            $agentClient = $detector->getClient();
            $agentDevice = $detector->getDevice();

            $message = Template::fromFile(__DIR__ . '/../../config/locale/templates/email-otp.tpl');
            $message
                ->setParam('{{hello}}', $locale->getText('emails.otpRecovery.hello'))
                ->setParam('{{description}}', $locale->getText('emails.otpRecovery.description'))
                ->setParam('{{clientInfo}}', $locale->getText('emails.otpRecovery.clientInfo'))
                ->setParam('{{thanks}}', $locale->getText('emails.otpRecovery.thanks'))
                ->setParam('{{signature}}', $locale->getText('emails.otpRecovery.signature'));

            if (!empty($phrase)) {
                $message->setParam('{{securityPhrase}}', $locale->getText('emails.otpRecovery.securityPhrase'));
            } else {
                $message->setParam('{{securityPhrase}}', '');
            }

            $body = $message->render();

            $smtp = $project->getAttribute('smtp', []);
            $smtpEnabled = $smtp['enabled'] ?? false;

            $senderEmail = System::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);
            $senderName = System::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server');
            $replyToEmail = '';
            $replyToName = '';
            $smtpConfig = [];

            if ($smtpEnabled) {
                if (!empty($smtp['senderEmail'])) {
                    $senderEmail = $smtp['senderEmail'];
                }
                if (!empty($smtp['senderName'])) {
                    $senderName = $smtp['senderName'];
                }
                $smtpReplyToEmail = $smtp['replyToEmail'] ?? $smtp['replyTo'] ?? '';
                if (!empty($smtpReplyToEmail)) {
                    $replyToEmail = $smtpReplyToEmail;
                }
                if (!empty($smtp['replyToName'])) {
                    $replyToName = $smtp['replyToName'];
                }

                if (!empty($customTemplate)) {
                    if (!empty($customTemplate['senderEmail'])) {
                        $senderEmail = $customTemplate['senderEmail'];
                    }
                    if (!empty($customTemplate['senderName'])) {
                        $senderName = $customTemplate['senderName'];
                    }
                    $customReplyToEmail = $customTemplate['replyToEmail'] ?? $customTemplate['replyTo'] ?? '';
                    if (!empty($customReplyToEmail)) {
                        $replyToEmail = $customReplyToEmail;
                    }
                    if (!empty($customTemplate['replyToName'])) {
                        $replyToName = $customTemplate['replyToName'];
                    }

                    $body = $customTemplate['message'] ?? '';
                    $subject = $customTemplate['subject'] ?? $subject;
                }

                $smtpConfig = [
                    'host' => $smtp['host'] ?? '',
                    'port' => $smtp['port'] ?? '',
                    'username' => $smtp['username'] ?? '',
                    'password' => $smtp['password'] ?? '',
                    'secure' => $smtp['secure'] ?? '',
                    'replyToEmail' => $replyToEmail,
                    'replyToName' => $replyToName,
                    'senderEmail' => $senderEmail,
                    'senderName' => $senderName,
                ];
            }

            $projectName = $project->isEmpty()
                ? 'Console'
                : $project->getAttribute('name', '[APP-NAME]');

            if ($project->getId() === 'console') {
                $projectName = $platform['platformName'];
            }

            $emailVariables = [
                'heading' => $heading,
                'direction' => $locale->getText('settings.direction'),
                'user' => $profile->getAttribute('name'),
                'project' => $projectName,
                'otp' => $secret,
                'agentDevice' => $agentDevice['deviceBrand'] ?? 'UNKNOWN',
                'agentClient' => $agentClient['clientName'] ?? 'UNKNOWN',
                'agentOs' => $agentOs['osName'] ?? 'UNKNOWN',
                'phrase' => !empty($phrase) ? $phrase : '',
                'team' => '',
            ];

            if ($smtpBaseTemplate === APP_BRANDED_EMAIL_BASE_TEMPLATE) {
                $emailVariables = array_merge($emailVariables, [
                    'accentColor' => $platform['accentColor'],
                    'logoUrl' => $platform['logoUrl'],
                    'twitter' => $platform['twitterUrl'],
                    'discord' => $platform['discordUrl'],
                    'github' => $platform['githubUrl'],
                    'terms' => $platform['termsUrl'],
                    'privacy' => $platform['privacyUrl'],
                    'platform' => $platform['platformName'],
                ]);
            }

            $publisherForMails->enqueue(new MailMessage(
                project: $project,
                recipient: $profile->getAttribute('email', ''),
                name: $profile->getAttribute('name', ''),
                subject: $subject,
                template: MAIL_TEMPLATE_OTP_RECOVERY,
                bodyTemplate: $bodyTemplate,
                body: $body,
                preview: $preview,
                smtp: $smtpConfig,
                variables: $emailVariables,
                customMailOptions: $project->getId() === 'console' ? ['senderName' => $platform['emailSenderName']] : [],
                platform: $platform,
                headers: OTPToken::from($secret, $request->getOrigin())?->toHeaders() ?? [],
            ));
        }

        $recovery->setAttribute('secret', $secret);

        if (!empty($phrase)) {
            $recovery->setAttribute('phrase', $phrase);
        }

        $queueForEvents
            ->setParam('userId', $userId)
            ->setParam('tokenId', $recovery->getId())
            ->setUser($deliverable ? $profile : new Document())
            ->setPayload($response->showSensitive(fn () => $response->output($recovery, Response::MODEL_TOKEN)), sensitive: ['secret']);

        // The secret proves control of the inbox or phone; only keys that can already mint tokens through users.write may read it back.
        if ($apiKey !== null && !\in_array('users.write', $apiKey->getScopes())) {
            $recovery->setAttribute('secret', '');
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($recovery, Response::MODEL_TOKEN);
    });

Http::put('/v1/account/recovery/otp')
    ->desc('Update password recovery (OTP)')
    ->groups(['api', 'account'])
    ->label('scope', 'sessions.write')
    ->label('event', 'users.[userId].recovery.[tokenId].update')
    ->label('audits.event', 'recovery.update')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('audits.userId', '{response.userId}')
    ->label('sdk', new Method(
        namespace: 'account',
        group: 'recovery',
        name: 'updateRecoveryOTP',
        description: '/docs/references/account/update-recovery-otp.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_TOKEN,
            )
        ],
        contentType: ContentType::JSON,
    ))
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{param-userId}')
    ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'User ID.', false, ['dbForProject'])
    ->param('secret', '', new Text(256), 'Valid recovery OTP code.')
    ->param('password', '', fn ($project, $passwordsDictionary) => new PasswordFormat(new AllOf([new PasswordStrength($project->getAttribute('auths', [])['passwordStrength'] ?? []), new PasswordDictionary($passwordsDictionary, enabled: $project->getAttribute('auths', [])['passwordDictionary'] ?? false, denyList: $project->getAttribute('auths', [])['passwordDictionaryDenyList'] ?? [])], Validator::TYPE_STRING)), 'New user password. Must be between 8 and 256 chars.', false, ['project', 'passwordsDictionary'])
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('queueForEvents')
    ->inject('hooks')
    ->inject('proofForPassword')
    ->inject('proofForCode')
    ->inject('authorization')
    ->inject('pwnedPasswords')
    ->inject('mqtt')
    ->inject('queueForRealtime')
    ->action(function (string $userId, string $secret, string $password, Response $response, User $user, Database $dbForProject, Document $project, Event $queueForEvents, Hooks $hooks, ProofsPassword $proofForPassword, ProofsCode $proofForCode, Authorization $authorization, PasswordPwned $pwnedPasswords, Mqtt $mqtt, Realtime $queueForRealtime) {
        /** @var Appwrite\Utopia\Database\Documents\User $profile */
        $profile = $dbForProject->getDocument('users', $userId);

        if ($profile->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $verifiedToken = $profile->tokenVerify(TOKEN_TYPE_RECOVERY_OTP, $secret, $proofForCode);

        if (!$verifiedToken) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        if ($project->getAttribute('auths', [])['personalDataCheck'] ?? false) {
            $personalDataValidator = new PersonalData($profile->getId(), $profile->getAttribute('email'), $profile->getAttribute('name'), $profile->getAttribute('phone'), denyList: $project->getAttribute('auths', [])['personalDataDenyList'] ?? []);
            if (!$personalDataValidator->isValid($password)) {
                throw new Exception(Exception::USER_PASSWORD_PERSONAL_DATA);
            }
        }

        $authorization->addRole(Role::user($profile->getId())->toString());

        $newPassword = $proofForPassword->hash($password);

        $hash = ProofsPassword::createHash($profile->getAttribute('hash'), $profile->getAttribute('hashOptions'));
        $historyLimit = $project->getAttribute('auths', [])['passwordHistory'] ?? 0;
        $history = $profile->getAttribute('passwordHistory', []);

        if ($historyLimit > 0) {
            $validator = new PasswordHistory($history, $hash);
            if (!$validator->isValid($password)) {
                throw new Exception(Exception::USER_PASSWORD_RECENTLY_USED);
            }

            $history[] = $newPassword;
            $history = array_slice($history, (count($history) - $historyLimit), $historyLimit);
        }

        // null when the policy did not look, false when it looked and found nothing
        $pwnedPolicy = $project->getAttribute('auths', [])['passwordPwned'] ?? [];
        $passwordPwned = ($pwnedPolicy['enabled'] ?? true)
            ? !$pwnedPasswords->isValid($password)
            : null;
        if ($passwordPwned && ($pwnedPolicy['users'] ?? false)) {
            throw new Exception(Exception::USER_PASSWORD_PWNED);
        }

        $hooks->trigger('passwordValidator', [$dbForProject, $project, $password, &$user, true]);

        $sessions = $profile->getAttribute('sessions', []);

        $claimed = false;
        $recoveryDocument = $dbForProject->withTransaction(function () use ($dbForProject, $authorization, $verifiedToken, $profile, $project, $newPassword, $history, $passwordPwned, $proofForPassword, &$claimed, $mqtt) {
            $claimed = false; // A retried attempt starts over
            $document = $dbForProject->getDocument('tokens', $verifiedToken->getId());
            if (!$dbForProject->deleteDocument('tokens', $verifiedToken->getId())) {
                return;
            }

            // Recovery proves an unverified email, so whoever registered it keeps no other way in.
            // MFA in force is left alone, so the new password still needs the second factor to sign in
            /** @var User $locked */
            $locked = $authorization->skip(fn () => $dbForProject->getDocument('users', $profile->getId(), forUpdate: true));
            $guarded = $locked->getMinimumFactors() > 1;
            if (!$locked->getAttribute('emailVerification', false) && !$guarded) {
                $authorization->skip(fn () => User::revokeCredentials($dbForProject, $profile, $project, mqtt: $mqtt));
                $claimed = true;
            }

            $updates = [
                'password' => $newPassword,
                'passwordHistory' => $history,
                'passwordPwned' => $passwordPwned,
                'passwordUpdate' => DateTime::now(),
                'hash' => $proofForPassword->getHash()->getName(),
                'hashOptions' => $proofForPassword->getHash()->getOptions(),
            ];
            if (!$guarded) {
                $updates['emailVerification'] = true;
            }
            $dbForProject->updateDocument('users', $profile->getId(), new Document($updates));

            return $document;
        });

        // Thrown outside the transaction, which would otherwise retry the reuse as a transient failure.
        if ($recoveryDocument === null) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        $profile = $dbForProject->getDocument('users', $profile->getId());
        $profile->setAttribute('sessions', $sessions);
        $user->setAttributes($profile->getArrayCopy());

        $invalidate = $project->getAttribute('auths', default: [])['invalidateSessions'] ?? true;
        if ($invalidate) {
            User::invalidateAuthentication($dbForProject, $profile);
        }

        $dbForProject->purgeCachedDocument('users', $profile->getId());

        try {
            $mqtt->revoke($project->getId(), $profile->getId());
        } catch (\Throwable $error) {
            Console::error('Failed to revoke MQTT connections: ' . $error->getMessage());
        }

        if ($claimed) {
            // Sent before the request's own event, which would otherwise reach the registrant with the claimant's data
            (clone $queueForRealtime)
                ->reset()
                ->setProject($project)
                ->setEvent('users.[userId].sessions.delete')
                ->setParam('userId', $profile->getId())
                ->trigger();
        }

        $queueForEvents
            ->setParam('userId', $profile->getId())
            ->setParam('tokenId', $recoveryDocument->getId())
            ->setPayload($response->showSensitive(fn () => $response->output($recoveryDocument, Response::MODEL_TOKEN)), sensitive: ['secret']);

        $response->dynamic($recoveryDocument, Response::MODEL_TOKEN);
    });

Http::post('/v1/account/verifications/email')
    ->alias('/v1/account/verification')
    ->desc('Create email verification')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('event', 'users.[userId].verification.[tokenId].create')
    ->label('audits.event', 'verification.create')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('sdk', [
        new Method(
            namespace: 'account',
            group: 'verification',
            name: 'createEmailVerification',
            description: '/docs/references/account/create-email-verification.md',
            auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_CREATED,
                    model: Response::MODEL_TOKEN,
                )
            ],
            contentType: ContentType::JSON,
        ),
        new Method(
            namespace: 'account',
            group: 'verification',
            name: 'createVerification',
            description: '/docs/references/account/create-email-verification.md',
            auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_CREATED,
                    model: Response::MODEL_TOKEN,
                )
            ],
            contentType: ContentType::JSON,
            deprecated: new Deprecated(
                since: '1.8.0',
                replaceWith: 'account.createEmailVerification'
            ),
            public: false,
        )
    ])
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{userId}')
    ->param('url', '', fn ($redirectValidator) => $redirectValidator, 'URL to redirect the user back to your app from the verification email. Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', false, ['redirectValidator']) // TODO add built-in confirm page
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('platform')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('queueForEvents')
    ->inject('publisherForMails')
    ->inject('proofForToken')
    ->inject('authorization')
    ->action(function (string $url, Request $request, Response $response, Document $project, array $platform, User $user, Database $dbForProject, Locale $locale, Event $queueForEvents, MailPublisher $publisherForMails, ProofsToken $proofForToken, Authorization $authorization) {

        if (empty(System::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception(Exception::GENERAL_SMTP_DISABLED, 'SMTP Disabled');
        }

        $email = \strtolower((string) $user->getAttribute('email'));
        if ($email === '') {
            throw new Exception(Exception::USER_EMAIL_NOT_FOUND);
        }

        if ($user->getAttribute('emailVerification')) {
            throw new Exception(Exception::USER_EMAIL_ALREADY_VERIFIED);
        }

        $verificationSecret = $proofForToken->generate();
        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), TOKEN_EXPIRATION_CONFIRM));

        $verification = new Document([
            '$id' => Id::unique(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getSequence(),
            'type' => TOKEN_TYPE_VERIFICATION,
            'secret' => $proofForToken->hash($verificationSecret), // One way hash encryption to protect DB leak
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        $authorization->addRole(Role::user($user->getId())->toString());

        $verification->setAttribute('$permissions', [
            Permission::read(Role::user($user->getId())),
            Permission::update(Role::user($user->getId())),
            Permission::delete(Role::user($user->getId())),
        ]);

        $verification = $authorization->skip(fn () => $dbForProject->withTransaction(function () use ($dbForProject, $user, $email, $verification) {
            $current = $dbForProject->getDocument('users', $user->getId(), forUpdate: true);
            if (
                $current->isEmpty()
                || $current->getAttribute('emailVerification')
                || \strtolower((string) $current->getAttribute('email')) !== $email
            ) {
                return new Document();
            }

            return $dbForProject->createDocument('tokens', $verification);
        }));

        if ($verification->isEmpty()) {
            $current = $authorization->skip(fn () => $dbForProject->getDocument('users', $user->getId()));
            if (!$current->isEmpty() && $current->getAttribute('emailVerification')) {
                throw new Exception(Exception::USER_EMAIL_ALREADY_VERIFIED);
            }
            throw new Exception(Exception::USER_EMAIL_NOT_FOUND);
        }

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $url = Template::parseURL($url);
        $url['query'] = Template::mergeQuery(((isset($url['query'])) ? $url['query'] : ''), ['userId' => $user->getId(), 'secret' => $verificationSecret, 'expire' => $expire]);
        $url = Template::unParseURL($url);

        $projectName = $project->isEmpty()
            ? 'Console'
            : $project->getAttribute('name', '[APP-NAME]');

        if ($project->getId() === 'console') {
            $projectName = $platform['platformName'];
        }


        $body = $locale->getText("emails.verification.body");
        $preview = $locale->getText("emails.verification.preview");
        $subject = $locale->getText("emails.verification.subject");
        $heading = $locale->getText("emails.verification.heading");

        $customTemplate =
            $project->getAttribute('templates', [])['email.verification-' . $locale->default] ??
            $project->getAttribute('templates', [])['email.verification-' . $locale->fallback] ?? [];
        $smtpBaseTemplate = $project->getAttribute('smtpBaseTemplate', 'email-base');

        $validator = new FileName();
        if (!$validator->isValid($smtpBaseTemplate)) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Invalid template path');
        }

        $bodyTemplate = __DIR__ . '/../../config/locale/templates/' . $smtpBaseTemplate . '.tpl';

        $message = Template::fromFile(__DIR__ . '/../../config/locale/templates/email-inner-base.tpl');
        $message
            ->setParam('{{body}}', $body, escapeHtml: false)
            ->setParam('{{hello}}', $locale->getText("emails.verification.hello"))
            ->setParam('{{footer}}', $locale->getText("emails.verification.footer"))
            ->setParam('{{thanks}}', $locale->getText("emails.verification.thanks"))
            ->setParam('{{buttonText}}', $locale->getText("emails.verification.buttonText"))
            ->setParam('{{signature}}', $locale->getText("emails.verification.signature"));

        $body = $message->render();

        $smtp = $project->getAttribute('smtp', []);
        $smtpEnabled = $smtp['enabled'] ?? false;

        $senderEmail = System::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);
        $senderName = System::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server');
        $replyToEmail = '';
        $replyToName = '';
        $smtpConfig = [];

        if ($smtpEnabled) {
            if (!empty($smtp['senderEmail'])) {
                $senderEmail = $smtp['senderEmail'];
            }
            if (!empty($smtp['senderName'])) {
                $senderName = $smtp['senderName'];
            }
            // Includes backwards compatibility: fall back to legacy `replyTo` key
            $smtpReplyToEmail = $smtp['replyToEmail'] ?? $smtp['replyTo'] ?? '';
            if (!empty($smtpReplyToEmail)) {
                $replyToEmail = $smtpReplyToEmail;
            }
            if (!empty($smtp['replyToName'])) {
                $replyToName = $smtp['replyToName'];
            }

            if (!empty($customTemplate)) {
                if (!empty($customTemplate['senderEmail'])) {
                    $senderEmail = $customTemplate['senderEmail'];
                }
                if (!empty($customTemplate['senderName'])) {
                    $senderName = $customTemplate['senderName'];
                }
                // Includes backwards compatibility: fall back to legacy `replyTo` key
                $customReplyToEmail = $customTemplate['replyToEmail'] ?? $customTemplate['replyTo'] ?? '';
                if (!empty($customReplyToEmail)) {
                    $replyToEmail = $customReplyToEmail;
                }
                if (!empty($customTemplate['replyToName'])) {
                    $replyToName = $customTemplate['replyToName'];
                }

                $body = $customTemplate['message'] ?? '';
                $subject = $customTemplate['subject'] ?? $subject;
            }

            $smtpConfig = [
                'host' => $smtp['host'] ?? '',
                'port' => $smtp['port'] ?? '',
                'username' => $smtp['username'] ?? '',
                'password' => $smtp['password'] ?? '',
                'secure' => $smtp['secure'] ?? '',
                'replyToEmail' => $replyToEmail,
                'replyToName' => $replyToName,
                'senderEmail' => $senderEmail,
                'senderName' => $senderName,
            ];
        }

        $emailVariables = [
            'heading' => $heading,
            'direction' => $locale->getText('settings.direction'),
            // {{user}}, {{redirect}} and {{project}} are required in default and custom templates
            'user' => $user->getAttribute('name'),
            'redirect' => $url,
            'project' => $projectName,
            // TODO: remove unnecessary team variable from this email
            'team' => '',
        ];

        if ($smtpBaseTemplate === APP_BRANDED_EMAIL_BASE_TEMPLATE) {
            $emailVariables = array_merge($emailVariables, [
                'accentColor' => $platform['accentColor'],
                'logoUrl' => $platform['logoUrl'],
                'twitter' => $platform['twitterUrl'],
                'discord' => $platform['discordUrl'],
                'github' => $platform['githubUrl'],
                'terms' => $platform['termsUrl'],
                'privacy' => $platform['privacyUrl'],
                'platform' => $platform['platformName'],
            ]);
        }

        $publisherForMails->enqueue(new MailMessage(
            project: $project,
            recipient: $user->getAttribute('email'),
            name: $user->getAttribute('name') ?? '',
            subject: $subject,
            template: MAIL_TEMPLATE_VERIFICATION,
            bodyTemplate: $bodyTemplate,
            body: $body,
            preview: $preview,
            smtp: $smtpConfig,
            variables: $emailVariables,
            customMailOptions: $project->getId() === 'console' ? ['senderName' => $platform['emailSenderName']] : [],
            platform: $platform,
        ));

        $verification->setAttribute('secret', $verificationSecret);

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('tokenId', $verification->getId())
            ->setPayload($response->showSensitive(fn () => $response->output($verification, Response::MODEL_TOKEN)), sensitive: ['secret']);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($verification, Response::MODEL_TOKEN);
    });

Http::put('/v1/account/verifications/email')
    ->alias('/v1/account/verification')
    ->desc('Update email verification (confirmation)')
    ->groups(['api', 'account'])
    ->label('scope', 'public')
    ->label('event', 'users.[userId].verification.[tokenId].update')
    ->label('audits.event', 'verification.update')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('sdk', [
        new Method(
            namespace: 'account',
            group: 'verification',
            name: 'updateEmailVerification',
            description: '/docs/references/account/update-email-verification.md',
            auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_TOKEN,
                )
            ],
            contentType: ContentType::JSON
        ),
        new Method(
            namespace: 'account',
            group: 'verification',
            name: 'updateVerification',
            description: '/docs/references/account/update-email-verification.md',
            auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_TOKEN,
                )
            ],
            contentType: ContentType::JSON,
            deprecated: new Deprecated(
                since: '1.8.0',
                replaceWith: 'account.updateEmailVerification'
            ),
            public: false,
        )
    ])
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{param-userId}')
    ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'User ID.', false, ['dbForProject'])
    ->param('secret', '', new Text(256), 'Valid verification token.')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('proofForToken')
    ->inject('authorization')
    ->action(function (string $userId, string $secret, Response $response, User $user, Database $dbForProject, Event $queueForEvents, ProofsToken $proofForToken, Authorization $authorization) {
        /** @var Appwrite\Utopia\Database\Documents\User $profile */
        $profile = $authorization->skip(fn () => $dbForProject->getDocument('users', $userId));

        if ($profile->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $verifiedToken = $profile->tokenVerify(TOKEN_TYPE_VERIFICATION, $secret, $proofForToken);

        if (!$verifiedToken) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        $authorization->addRole(Role::user($profile->getId())->toString());

        $profile = $dbForProject->updateDocument('users', $profile->getId(), new Document(['emailVerification' => true]));

        $user->setAttributes($profile->getArrayCopy());

        $verification = $dbForProject->getDocument('tokens', $verifiedToken->getId());

        /**
         * We act like we're updating and validating
         *  the verification token but actually we don't need it anymore.
         */
        $dbForProject->deleteDocument('tokens', $verifiedToken->getId());
        $dbForProject->purgeCachedDocument('users', $profile->getId());

        $queueForEvents
            ->setParam('userId', $userId)
            ->setParam('tokenId', $verification->getId())
            ->setPayload($response->showSensitive(fn () => $response->output($verification, Response::MODEL_TOKEN)), sensitive: ['secret']);

        $response->dynamic($verification, Response::MODEL_TOKEN);
    });

Http::post('/v1/account/verifications/phone')
    ->alias('/v1/account/verification/phone')
    ->desc('Create phone verification')
    ->groups(['api', 'account', 'auth'])
    ->label('scope', 'account')
    ->label('auth.type', 'phone')
    ->label('event', 'users.[userId].verification.[tokenId].create')
    ->label('audits.event', 'verification.create')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('sdk', new Method(
        namespace: 'account',
        group: 'verification',
        name: 'createPhoneVerification',
        description: '/docs/references/account/create-phone-verification.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_TOKEN,
            )
        ],
        contentType: ContentType::JSON,
    ))
    ->label('abuse-limit', 10)
    ->label('abuse-key', ['url:{url},userId:{userId}', 'url:{url},ip:{ip}'])
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('publisherForMessaging')
    ->inject('project')
    ->inject('locale')
    ->inject('usage')
    ->inject('plan')
    ->inject('proofForCode')
                ->inject('authorization')
    ->action(function (Request $request, Response $response, User $user, Database $dbForProject, Event $queueForEvents, MessagingPublisher $publisherForMessaging, Document $project, Locale $locale, Context $usage, array $plan, ProofsCode $proofForCode, Authorization $authorization) {
        if (empty(System::getEnv('_APP_SMS_PROVIDER'))) {
            throw new Exception(Exception::GENERAL_PHONE_DISABLED, 'Phone provider not configured');
        }

        $phone = $user->getAttribute('phone');
        if (empty($phone)) {
            throw new Exception(Exception::USER_PHONE_NOT_FOUND);
        }

        if ($user->getAttribute('phoneVerification')) {
            throw new Exception(Exception::USER_PHONE_ALREADY_VERIFIED);
        }

        $secret = null;
        $sendSMS = true;
        $mockNumbers = $project->getAttribute('auths', [])['mockNumbers'] ?? [];
        foreach ($mockNumbers as $mockNumber) {
            if ($mockNumber['phone'] === $phone) {
                $secret = $mockNumber['otp'];
                $sendSMS = false;
                break;
            }
        }

        $secret ??= $proofForCode->generate();
        $expire = DateTime::addSeconds(new \DateTime(), TOKEN_EXPIRATION_CONFIRM);

        $verification = new Document([
            '$id' => Id::unique(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getSequence(),
            'type' => TOKEN_TYPE_PHONE,
            'secret' => $proofForCode->hash($secret),
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        $authorization->addRole(Role::user($user->getId())->toString());

        $verification->setAttribute('$permissions', [
            Permission::read(Role::user($user->getId())),
            Permission::update(Role::user($user->getId())),
            Permission::delete(Role::user($user->getId())),
        ]);

        $sms = null;
        if ($sendSMS) {
            $message = Template::fromFile(__DIR__ . '/../../config/locale/templates/sms-base.tpl');

            $messageContent = Template::fromString($locale->getText("sms.verification.body"));
            $messageContent
                ->setParam('{{project}}', $project->getAttribute('name'))
                ->setParam('{{secret}}', $secret);
            $messageContent = \strip_tags($messageContent->render());
            $message = $message->setParam('{{token}}', $messageContent);

            $message = $message->render();

            $messageDoc = new Document([
                '$id' => $verification->getId(),
                'data' => [
                    'content' => $message,
                ],
            ]);

            $sms = new MessagingMessage(
                type: MESSAGE_SEND_TYPE_INTERNAL,
                project: $project,
                message: $messageDoc,
                recipients: [$user->getAttribute('phone')],
                providerType: MESSAGE_TYPE_SMS,
            );
        }

        // Store the token and queue its SMS under the user lock the phone update takes, so a code is never issued or sent for a number that just changed
        $verification = $dbForProject->withTransaction(function () use ($dbForProject, $authorization, $user, $phone, $verification, $sms, $publisherForMessaging) {
            $current = $authorization->skip(fn () => $dbForProject->getDocument('users', $user->getId(), forUpdate: true));
            if ($current->getAttribute('phone') !== $phone) {
                throw new Exception(Exception::USER_PHONE_NOT_FOUND);
            }

            $verification = $dbForProject->createDocument('tokens', $verification);
            if ($sms !== null) {
                $publisherForMessaging->enqueue($sms);
            }

            return $verification;
        });

        $dbForProject->purgeCachedDocument('users', $user->getId());

        if ($sendSMS) {
            $countryCode = CallingCode::fromPhoneNumber($phone);
            if (!empty($countryCode)) {
                $usage->addMetric(str_replace('{countryCode}', $countryCode, METRIC_AUTH_METHOD_PHONE_COUNTRY_CODE), 1);
            }
            $usage->addMetric(METRIC_AUTH_METHOD_PHONE, 1);
        }

        $verification->setAttribute('secret', $secret);

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('tokenId', $verification->getId())
            ->setPayload($response->output($verification, Response::MODEL_TOKEN), sensitive: ['secret']);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($verification, Response::MODEL_TOKEN);
    });

Http::put('/v1/account/verifications/phone')
    ->alias('/v1/account/verification/phone')
    ->desc('Update phone verification (confirmation)')
    ->groups(['api', 'account'])
    ->label('scope', 'public')
    ->label('event', 'users.[userId].verification.[tokenId].update')
    ->label('audits.event', 'verification.update')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('sdk', new Method(
        namespace: 'account',
        group: 'verification',
        name: 'updatePhoneVerification',
        description: '/docs/references/account/update-phone-verification.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_TOKEN,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'userId:{param-userId}')
    ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'User ID.', false, ['dbForProject'])
    ->param('secret', '', new Text(256), 'Valid verification token.')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('proofForCode')
    ->inject('authorization')
    ->action(function (string $userId, string $secret, Response $response, User $user, Database $dbForProject, Event $queueForEvents, ProofsCode $proofForCode, Authorization $authorization) {
        /** @var Appwrite\Utopia\Database\Documents\User  $profile */
        $profile = $authorization->skip(fn () => $dbForProject->getDocument('users', $userId));

        if ($profile->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $verifiedToken = $profile->tokenVerify(TOKEN_TYPE_PHONE, $secret, $proofForCode);

        if (!$verifiedToken) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        $authorization->addRole(Role::user($profile->getId())->toString());

        $verificationDocument = $dbForProject->getDocument('tokens', $verifiedToken->getId());

        // Claim the token under the user row lock a phone change takes, which deletes tokens sent to the old number
        $profile = $dbForProject->withTransaction(function () use ($dbForProject, $authorization, $profile, $verifiedToken) {
            $authorization->skip(fn () => $dbForProject->getDocument('users', $profile->getId(), forUpdate: true));

            if (!$dbForProject->deleteDocument('tokens', $verifiedToken->getId())) {
                throw new Exception(Exception::USER_INVALID_TOKEN);
            }

            return $dbForProject->updateDocument('users', $profile->getId(), new Document(['phoneVerification' => true]));
        });

        $user->setAttributes($profile->getArrayCopy());
        $dbForProject->purgeCachedDocument('users', $profile->getId());

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('tokenId', $verificationDocument->getId())
        ;

        $response->dynamic($verificationDocument, Response::MODEL_TOKEN);
    });

Http::post('/v1/account/targets/push')
    ->desc('Create push target')
    ->groups(['api', 'account'])
    ->label('scope', 'targets.write')
    ->label('audits.event', 'target.create')
    ->label('audits.resource', 'target/response.$id')
    ->label('event', 'users.[userId].targets.[targetId].create')
    ->label('sdk', new Method(
        namespace: 'account',
        group: 'pushTargets',
        name: 'createPushTarget',
        description: '/docs/references/account/create-push-target.md',
        auth: [AuthType::ADMIN, AuthType::SESSION],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_TARGET,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('targetId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getMaxUidLength()), 'Target ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForProject'])
    ->param('identifier', '', new Text(Database::LENGTH_KEY), 'The target identifier (token, email, phone etc.)')
    ->param('providerId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Provider ID. Message will be sent to this target from the specified provider ID. If no provider ID is set the first setup provider will be used.', true, ['dbForProject'])
    ->param('bundleId', '', new Text(256), 'App bundle ID of the device registering an APNs token. Without `providerId`, routes the target to the enabled APNs provider for this bundle ID and `environment`.', true)
    ->param('environment', '', new WhiteList(['sandbox', 'production'], true), 'APNs environment the token belongs to, `sandbox` or `production`. Used with `bundleId`.', true)
    ->inject('queueForEvents')
    ->inject('user')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('authorization')
    ->inject('session')
    ->action(function (string $targetId, string $identifier, string $providerId, string $bundleId, string $environment, Event $queueForEvents, User $user, Request $request, Response $response, Database $dbForProject, Authorization $authorization, ?Document $current) {
        $targetId = $targetId == 'unique()' ? Id::unique() : $targetId;

        $provider = $authorization->skip(fn () => $dbForProject->getDocument('providers', $providerId));

        // Apple's SDK registers its APNs token with the app's bundle ID and APNs environment, not a provider
        // ID. Route it to the oldest enabled APNs provider for that app and environment; no match keeps the
        // target on the default provider, as before.
        $routed = false;
        if (empty($providerId) && $bundleId !== '') {
            $candidates = $authorization->skip(fn () => $dbForProject->find('providers', [
                Query::equal('provider', ['apns']),
                Query::equal('type', [MESSAGE_TYPE_PUSH]),
                Query::equal('enabled', [true]),
                Query::orderAsc('$sequence'),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]));

            foreach ($candidates as $candidate) {
                $sandbox = (bool) ($candidate->getAttribute('options', [])['sandbox'] ?? false);
                // A legacy `bundle` key holds the bundle ID set by an earlier provider update and wins over `bundleId`.
                $credentials = $candidate->getAttribute('credentials', []);
                if (($credentials['bundle'] ?? $credentials['bundleId'] ?? '') === $bundleId
                    && ($environment === '' || $sandbox === ($environment === 'sandbox'))) {
                    $provider = $candidate;
                    $providerId = $candidate->getId();
                    $routed = true;
                    break;
                }
            }
        }

        $target = $authorization->skip(fn () => $dbForProject->getDocument('targets', $targetId));

        if (!$target->isEmpty()) {
            throw new Exception(Exception::USER_TARGET_ALREADY_EXISTS);
        }

        $detector = new Detector($request->getUserAgent());
        $detector->skipBotDetection(); // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)

        $device = $detector->getDevice();

        $session = $dbForProject->getDocument('sessions', $current?->getId() ?? '');
        $name = "{$device['deviceBrand']} {$device['deviceModel']}";

        // A session is one device install holding one push token per provider. Re-registering a rotated
        // token instead of updating used to leave the superseded one live, so messages arrived twice.
        $siblings = $session->isEmpty()
            ? []
            : $authorization->skip(fn () => $dbForProject->find('targets', [
                Query::equal('userInternalId', [$user->getSequence()]),
                Query::equal('sessionInternalId', [$session->getSequence()]),
                Query::equal('providerType', [MESSAGE_TYPE_PUSH]),
                match (true) {
                    empty($providerId) => Query::isNull('providerId'),
                    // Before routing, this install registered the same token providerless; adopt that target.
                    $routed => Query::or([Query::isNull('providerId'), Query::equal('providerId', [$providerId])]),
                    default => Query::equal('providerId', [$providerId]),
                },
                Query::orderAsc('$sequence'),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]));

        // A sibling already holding this token has to be the one reused, or the update below collides
        // with it on the unique identifier index. Otherwise the oldest wins, since a client that
        // subscribes to topics only on first registration left its subscriptions there.
        $current = null;

        foreach ($siblings as $key => $sibling) {
            if ($sibling->getAttribute('identifier') === $identifier) {
                $current = $sibling;
                unset($siblings[$key]);
                break;
            }
        }

        $current ??= \array_shift($siblings);

        try {
            $target = $current === null
                ? $dbForProject->createDocument('targets', new Document([
                    '$id' => $targetId,
                    '$permissions' => [
                        Permission::read(Role::user($user->getId())),
                        Permission::update(Role::user($user->getId())),
                        Permission::delete(Role::user($user->getId())),
                    ],
                    'providerId' => !empty($providerId) ? $providerId : null,
                    'providerInternalId' => !empty($providerId) ? $provider->getSequence() : null,
                    'providerType' => MESSAGE_TYPE_PUSH,
                    'userId' => $user->getId(),
                    'userInternalId' => $user->getSequence(),
                    'sessionId' => $session->getId(),
                    'sessionInternalId' => $session->getSequence(),
                    'identifier' => $identifier,
                    'name' => $name
                ]))
                : $dbForProject->updateDocument('targets', $current->getId(), new Document(\array_merge([
                    'identifier' => $identifier,
                    'expired' => false,
                    'name' => $name,
                ], $routed ? [
                    'providerId' => $providerId,
                    'providerInternalId' => $provider->getSequence(),
                ] : [])));
        } catch (Duplicate) {
            throw new Exception(Exception::USER_TARGET_ALREADY_EXISTS);
        }

        // Anything left holds a token the client just told us it no longer uses. Expiring rather than
        // deleting hands it to the maintenance sweep, which also releases its subscriptions and the
        // topic counters those hold.
        foreach ($siblings as $sibling) {
            $dbForProject->updateDocument('targets', $sibling->getId(), new Document([
                'expired' => true,
            ]));
        }

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('targetId', $target->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($target, Response::MODEL_TARGET);
    });

Http::put('/v1/account/targets/:targetId/push')
    ->desc('Update push target')
    ->groups(['api', 'account'])
    ->label('scope', 'targets.write')
    ->label('audits.event', 'target.update')
    ->label('audits.resource', 'target/response.$id')
    ->label('event', 'users.[userId].targets.[targetId].update')
    ->label('sdk', new Method(
        namespace: 'account',
        group: 'pushTargets',
        name: 'updatePushTarget',
        description: '/docs/references/account/update-push-target.md',
        auth: [AuthType::ADMIN, AuthType::SESSION],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_TARGET,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('targetId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Target ID.', false, ['dbForProject'])
    ->param('identifier', '', new Text(Database::LENGTH_KEY), 'The target identifier (token, email, phone etc.)')
    ->param('bundleId', '', new Text(256), 'App bundle ID of the device registering an APNs token. Routes an APNs target to the enabled APNs provider for this bundle ID and `environment`.', true)
    ->param('environment', '', new WhiteList(['sandbox', 'production'], true), 'APNs environment the token belongs to, `sandbox` or `production`. Used with `bundleId`.', true)
    ->inject('queueForEvents')
    ->inject('user')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('authorization')
    ->action(function (string $targetId, string $identifier, string $bundleId, string $environment, Event $queueForEvents, Document $user, Request $request, Response $response, Database $dbForProject, Authorization $authorization) {

        $target = $authorization->skip(fn () => $dbForProject->getDocument('targets', $targetId));

        if ($target->isEmpty()) {
            throw new Exception(Exception::USER_TARGET_NOT_FOUND);
        }

        if ($user->getId() !== $target->getAttribute('userId')) {
            throw new Exception(Exception::USER_TARGET_NOT_FOUND);
        }

        if ($identifier) {
            $target
                ->setAttribute('identifier', $identifier)
                ->setAttribute('expired', false);
        }

        $detector = new Detector($request->getUserAgent());
        $detector->skipBotDetection(); // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)

        $device = $detector->getDevice();

        $target->setAttribute('name', "{$device['deviceBrand']} {$device['deviceModel']}");

        $changes = new Document([
            'identifier' => $target->getAttribute('identifier'),
            'expired' => $target->getAttribute('expired'),
            'name' => $target->getAttribute('name'),
        ]);

        // Re-route an APNs target when its build changes (a debug build moving to TestFlight). Only a target
        // on no provider or an APNs one moves, so an explicitly chosen provider of another kind stays put.
        if ($bundleId !== '') {
            $current = empty($target->getAttribute('providerId'))
                ? new Document()
                : $authorization->skip(fn () => $dbForProject->getDocument('providers', $target->getAttribute('providerId')));

            if ($current->isEmpty() || $current->getAttribute('provider') === 'apns') {
                $candidates = $authorization->skip(fn () => $dbForProject->find('providers', [
                    Query::equal('provider', ['apns']),
                    Query::equal('type', [MESSAGE_TYPE_PUSH]),
                    Query::equal('enabled', [true]),
                    Query::orderAsc('$sequence'),
                    Query::limit(APP_LIMIT_SUBQUERY),
                ]));

                foreach ($candidates as $candidate) {
                    $sandbox = (bool) ($candidate->getAttribute('options', [])['sandbox'] ?? false);
                    // A legacy `bundle` key holds the bundle ID set by an earlier provider update and wins over `bundleId`.
                    $credentials = $candidate->getAttribute('credentials', []);
                    if (($credentials['bundle'] ?? $credentials['bundleId'] ?? '') === $bundleId
                        && ($environment === '' || $sandbox === ($environment === 'sandbox'))) {
                        if ($candidate->getId() !== $target->getAttribute('providerId')) {
                            $changes
                                ->setAttribute('providerId', $candidate->getId())
                                ->setAttribute('providerInternalId', $candidate->getSequence());
                        }
                        break;
                    }
                }
            }
        }

        try {
            $target = $dbForProject->updateDocument('targets', $target->getId(), $changes);
        } catch (Duplicate) {
            throw new Exception(Exception::USER_TARGET_ALREADY_EXISTS);
        }

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('targetId', $target->getId());

        $response
            ->dynamic($target, Response::MODEL_TARGET);
    });

Http::delete('/v1/account/targets/:targetId/push')
    ->desc('Delete push target')
    ->groups(['api', 'account'])
    ->label('scope', 'targets.write')
    ->label('audits.event', 'target.delete')
    ->label('audits.resource', 'target/{request.targetId}')
    ->label('event', 'users.[userId].targets.[targetId].delete')
    ->label('sdk', new Method(
        namespace: 'account',
        group: 'pushTargets',
        name: 'deletePushTarget',
        description: '/docs/references/account/delete-push-target.md',
        auth: [AuthType::ADMIN, AuthType::SESSION],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('targetId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Target ID.', false, ['dbForProject'])
    ->inject('queueForEvents')
    ->inject('publisherForDeletes')
    ->inject('user')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('authorization')
    ->action(function (string $targetId, Event $queueForEvents, DeletePublisher $publisherForDeletes, Document $user, Request $request, Response $response, Database $dbForProject, Authorization $authorization) {
        $target = $authorization->skip(fn () => $dbForProject->getDocument('targets', $targetId));

        if ($target->isEmpty()) {
            throw new Exception(Exception::USER_TARGET_NOT_FOUND);
        }

        if ($user->getSequence() !== $target->getAttribute('userInternalId')) {
            throw new Exception(Exception::USER_TARGET_NOT_FOUND);
        }

        $dbForProject->deleteDocument('targets', $target->getId());

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $publisherForDeletes->enqueue(new DeleteMessage(
            project: $queueForEvents->getProject(),
            type: DELETE_TYPE_TARGET,
            document: $target,
        ));

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('targetId', $target->getId())
            ->setPayload($response->output($target, Response::MODEL_TARGET));

        $response->noContent();
    });
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

Http::post('/v1/account/verifications/email/otp')
    ->desc('Create email verification (OTP)')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('event', 'users.[userId].verification.[tokenId].create')
    ->label('audits.event', 'verification.create')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('sdk', new Method(
        namespace: 'account',
        group: 'verification',
        name: 'createEmailVerificationOTP',
        description: '/docs/references/account/create-email-verification-otp.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_TOKEN,
            )
        ],
        contentType: ContentType::JSON,
    ))
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{userId}')
    ->param('phrase', false, new Boolean(), 'Toggle for security phrase. If enabled, email will be sent with a randomly generated phrase and the phrase will also be included in the response. Confirming phrases match increases the security of your authentication flow.', true)
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('platform')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('queueForEvents')
    ->inject('publisherForMails')
    ->inject('proofForCode')
    ->inject('authorization')
    ->action(function (bool $phrase, Request $request, Response $response, Document $project, array $platform, User $user, Database $dbForProject, Locale $locale, Event $queueForEvents, MailPublisher $publisherForMails, ProofsCode $proofForCode, Authorization $authorization) {
        if (empty(System::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception(Exception::GENERAL_SMTP_DISABLED, 'SMTP Disabled');
        }

        $email = \strtolower((string) $user->getAttribute('email'));
        if ($email === '') {
            throw new Exception(Exception::USER_EMAIL_NOT_FOUND);
        }

        if ($user->getAttribute('emailVerification')) {
            throw new Exception(Exception::USER_EMAIL_ALREADY_VERIFIED);
        }

        if ($phrase === true) {
            $phrase = (new Phrase())->generate();
        }

        $secret = $proofForCode->generate();
        $expire = DateTime::addSeconds(new \DateTime(), TOKEN_EXPIRATION_OTP);

        $verification = new Document([
            '$id' => Id::unique(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getSequence(),
            'type' => TOKEN_TYPE_VERIFICATION_OTP,
            'secret' => $proofForCode->hash($secret),
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        $authorization->addRole(Role::user($user->getId())->toString());

        $verification->setAttribute('$permissions', [
            Permission::read(Role::user($user->getId())),
            Permission::update(Role::user($user->getId())),
            Permission::delete(Role::user($user->getId())),
        ]);

        $verification = $authorization->skip(fn () => $dbForProject->withTransaction(function () use ($dbForProject, $user, $email, $verification) {
            $current = $dbForProject->getDocument('users', $user->getId(), forUpdate: true);
            if (
                $current->isEmpty()
                || $current->getAttribute('emailVerification')
                || \strtolower((string) $current->getAttribute('email')) !== $email
            ) {
                return new Document();
            }

            return $dbForProject->createDocument('tokens', $verification);
        }));

        if ($verification->isEmpty()) {
            $current = $authorization->skip(fn () => $dbForProject->getDocument('users', $user->getId()));
            if (!$current->isEmpty() && $current->getAttribute('emailVerification')) {
                throw new Exception(Exception::USER_EMAIL_ALREADY_VERIFIED);
            }
            throw new Exception(Exception::USER_EMAIL_NOT_FOUND);
        }

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $subject = $locale->getText('emails.otpVerification.subject');
        $preview = $locale->getText('emails.otpVerification.preview');
        $heading = $locale->getText('emails.otpVerification.heading');

        $customTemplate =
            $project->getAttribute('templates', [])['email.otpVerification-' . $locale->default] ??
            $project->getAttribute('templates', [])['email.otpVerification-' . $locale->fallback] ?? [];
        $smtpBaseTemplate = $project->getAttribute('smtpBaseTemplate', 'email-base');

        $validator = new FileName();
        if (!$validator->isValid($smtpBaseTemplate)) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Invalid template path');
        }

        $bodyTemplate = __DIR__ . '/../../config/locale/templates/' . $smtpBaseTemplate . '.tpl';

        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $agentOs = $detector->getOS();
        $agentClient = $detector->getClient();
        $agentDevice = $detector->getDevice();

        $message = Template::fromFile(__DIR__ . '/../../config/locale/templates/email-otp.tpl');
        $message
            ->setParam('{{hello}}', $locale->getText('emails.otpVerification.hello'))
            ->setParam('{{description}}', $locale->getText('emails.otpVerification.description'))
            ->setParam('{{clientInfo}}', $locale->getText('emails.otpVerification.clientInfo'))
            ->setParam('{{thanks}}', $locale->getText('emails.otpVerification.thanks'))
            ->setParam('{{signature}}', $locale->getText('emails.otpVerification.signature'));

        if (!empty($phrase)) {
            $message->setParam('{{securityPhrase}}', $locale->getText('emails.otpVerification.securityPhrase'));
        } else {
            $message->setParam('{{securityPhrase}}', '');
        }

        $body = $message->render();

        $smtp = $project->getAttribute('smtp', []);
        $smtpEnabled = $smtp['enabled'] ?? false;

        $senderEmail = System::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);
        $senderName = System::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server');
        $replyToEmail = '';
        $replyToName = '';
        $smtpConfig = [];

        if ($smtpEnabled) {
            if (!empty($smtp['senderEmail'])) {
                $senderEmail = $smtp['senderEmail'];
            }
            if (!empty($smtp['senderName'])) {
                $senderName = $smtp['senderName'];
            }
            $smtpReplyToEmail = $smtp['replyToEmail'] ?? $smtp['replyTo'] ?? '';
            if (!empty($smtpReplyToEmail)) {
                $replyToEmail = $smtpReplyToEmail;
            }
            if (!empty($smtp['replyToName'])) {
                $replyToName = $smtp['replyToName'];
            }

            if (!empty($customTemplate)) {
                if (!empty($customTemplate['senderEmail'])) {
                    $senderEmail = $customTemplate['senderEmail'];
                }
                if (!empty($customTemplate['senderName'])) {
                    $senderName = $customTemplate['senderName'];
                }
                $customReplyToEmail = $customTemplate['replyToEmail'] ?? $customTemplate['replyTo'] ?? '';
                if (!empty($customReplyToEmail)) {
                    $replyToEmail = $customReplyToEmail;
                }
                if (!empty($customTemplate['replyToName'])) {
                    $replyToName = $customTemplate['replyToName'];
                }

                $body = $customTemplate['message'] ?? '';
                $subject = $customTemplate['subject'] ?? $subject;
            }

            $smtpConfig = [
                'host' => $smtp['host'] ?? '',
                'port' => $smtp['port'] ?? '',
                'username' => $smtp['username'] ?? '',
                'password' => $smtp['password'] ?? '',
                'secure' => $smtp['secure'] ?? '',
                'replyToEmail' => $replyToEmail,
                'replyToName' => $replyToName,
                'senderEmail' => $senderEmail,
                'senderName' => $senderName,
            ];
        }

        $projectName = $project->getAttribute('name');
        if ($project->getId() === 'console') {
            $projectName = $platform['platformName'];
        }

        $emailVariables = [
            'heading' => $heading,
            'direction' => $locale->getText('settings.direction'),
            'user' => $user->getAttribute('name'),
            'project' => $projectName,
            'otp' => $secret,
            'agentDevice' => $agentDevice['deviceBrand'] ?? 'UNKNOWN',
            'agentClient' => $agentClient['clientName'] ?? 'UNKNOWN',
            'agentOs' => $agentOs['osName'] ?? 'UNKNOWN',
            'phrase' => !empty($phrase) ? $phrase : '',
            'team' => '',
        ];

        if ($smtpBaseTemplate === APP_BRANDED_EMAIL_BASE_TEMPLATE) {
            $emailVariables = array_merge($emailVariables, [
                'accentColor' => $platform['accentColor'],
                'logoUrl' => $platform['logoUrl'],
                'twitter' => $platform['twitterUrl'],
                'discord' => $platform['discordUrl'],
                'github' => $platform['githubUrl'],
                'terms' => $platform['termsUrl'],
                'privacy' => $platform['privacyUrl'],
                'platform' => $platform['platformName'],
            ]);
        }

        $publisherForMails->enqueue(new MailMessage(
            project: $project,
            recipient: $user->getAttribute('email'),
            name: $user->getAttribute('name') ?? '',
            subject: $subject,
            template: MAIL_TEMPLATE_OTP_VERIFICATION,
            bodyTemplate: $bodyTemplate,
            body: $body,
            preview: $preview,
            smtp: $smtpConfig,
            variables: $emailVariables,
            customMailOptions: $project->getId() === 'console' ? ['senderName' => $platform['emailSenderName']] : [],
            platform: $platform,
            headers: OTPToken::from($secret, $request->getOrigin())?->toHeaders() ?? [],
        ));

        $verification->setAttribute('secret', $secret);

        if (!empty($phrase)) {
            $verification->setAttribute('phrase', $phrase);
        }

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('tokenId', $verification->getId())
            ->setPayload($response->showSensitive(fn () => $response->output($verification, Response::MODEL_TOKEN)), sensitive: ['secret']);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($verification, Response::MODEL_TOKEN);
    });

Http::put('/v1/account/verifications/email/otp')
    ->desc('Update email verification (OTP)')
    ->groups(['api', 'account'])
    ->label('scope', 'public')
    ->label('event', 'users.[userId].verification.[tokenId].update')
    ->label('audits.event', 'verification.update')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('sdk', new Method(
        namespace: 'account',
        group: 'verification',
        name: 'updateEmailVerificationOTP',
        description: '/docs/references/account/update-email-verification-otp.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_TOKEN,
            )
        ],
        contentType: ContentType::JSON,
    ))
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{param-userId}')
    ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'User ID.', false, ['dbForProject'])
    ->param('secret', '', new Text(256), 'Valid verification OTP code.')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('proofForCode')
    ->inject('authorization')
    ->action(function (string $userId, string $secret, Response $response, User $user, Database $dbForProject, Event $queueForEvents, ProofsCode $proofForCode, Authorization $authorization) {
        /** @var \Appwrite\Utopia\Database\Documents\User $profile */
        $profile = $authorization->skip(fn () => $dbForProject->getDocument('users', $userId));

        if ($profile->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $verifiedToken = $profile->tokenVerify(TOKEN_TYPE_VERIFICATION_OTP, $secret, $proofForCode);

        if (!$verifiedToken) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        $authorization->addRole(Role::user($profile->getId())->toString());

        $profile = $dbForProject->updateDocument('users', $profile->getId(), new Document(['emailVerification' => true]));

        $user->setAttributes($profile->getArrayCopy());

        $verificationDocument = $dbForProject->getDocument('tokens', $verifiedToken->getId());

        $dbForProject->deleteDocument('tokens', $verifiedToken->getId());
        $dbForProject->purgeCachedDocument('users', $profile->getId());

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('tokenId', $verificationDocument->getId())
            ->setPayload($response->showSensitive(fn () => $response->output($verificationDocument, Response::MODEL_TOKEN)), sensitive: ['secret']);

        $response->dynamic($verificationDocument, Response::MODEL_TOKEN);
    });
