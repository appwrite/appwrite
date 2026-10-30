<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions\Email;

use Appwrite\Auth\Validator\Password;
use Appwrite\Auth\Validator\PasswordPwned;
use Appwrite\Bus\Events\SessionCreated;
use Appwrite\Detector\Detector;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Geo\Geo;
use Appwrite\Hooks\Hooks;
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
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Emails\Validator\Email as EmailValidator;
use Utopia\Locale\Locale;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createEmailPasswordSession';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/sessions/email')
            ->httpAlias('/v1/account/sessions')
            ->desc('Create email password session')
            ->groups(['api', 'account', 'auth', 'session'])
            ->label('event', 'users.[userId].sessions.[sessionId].create')
            ->label('scope', 'sessions.write')
            ->label('auth.type', 'email-password')
            ->label('audits.event', 'session.create')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('audits.userId', '{response.userId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'sessions',
                name: 'createEmailPasswordSession',
                description: '/docs/references/account/create-session-email-password.md',
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
            ->label('abuse-key', 'projectId:{projectId},url:{url},email:{param-email}')
            ->label('abuse-reset', [201])
            ->param('email', '', new EmailValidator(), 'User email.')
            ->param('password', '', new Password(), 'User password. Must be at least 8 chars.')
            ->param('duration', null, new Nullable(new Range(60, TOKEN_EXPIRATION_LOGIN_LONG)), 'Session length in seconds. Minimum is 60 seconds, and it cannot exceed the project maximum session length. Defaults to the project maximum session length.', true)
            ->inject('request')
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('platform')
            ->inject('locale')
            ->inject('geo')
            ->inject('queueForEvents')
            ->inject('bus')
            ->inject('hooks')
            ->inject('store')
            ->inject('proofForPassword')
            ->inject('proofForToken')
            ->inject('domainVerification')
            ->inject('cookieDomain')
            ->inject('authorization')
            ->inject('pwnedPasswords')
            ->callback($this->action(...));
    }

    public function action(
        string $email,
        string $password,
        ?int $duration,
        Request $request,
        Response $response,
        User $user,
        Database $dbForProject,
        Document $project,
        array $platform,
        Locale $locale,
        Geo $geo,
        Event $queueForEvents,
        Bus $bus,
        Hooks $hooks,
        Store $store,
        ProofsPassword $proofForPassword,
        ProofsToken $proofForToken,
        bool $domainVerification,
        ?string $cookieDomain,
        Authorization $authorization,
        PasswordPwned $pwnedPasswords,
    ): void {
        $email = \strtolower($email);
        $protocol = $request->getProtocol();

        $maxDuration = $project->getAttribute('auths', [])['duration'] ?? TOKEN_EXPIRATION_LOGIN_LONG;
        if ($duration !== null && $duration > $maxDuration) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "Session duration cannot exceed the project maximum session length of {$maxDuration} seconds.");
        }
        $duration ??= $maxDuration;

        $profile = $dbForProject->findOne('users', [
            Query::equal('email', [$email]),
        ]);

        $userProofForPassword = ProofsPassword::createHash($profile->getAttribute('hash', $proofForPassword->getHash()->getName()), $profile->getAttribute('hashOptions', $proofForPassword->getHash()->getOptions()));

        if ($profile->isEmpty() || empty($profile->getAttribute('passwordUpdate')) || !$userProofForPassword->verify($password, $profile->getAttribute('password'))) {
            throw new Exception(Exception::USER_INVALID_CREDENTIALS);
        }

        if (false === $profile->getAttribute('status')) { // Account is blocked
            throw new Exception(Exception::USER_BLOCKED); // User is in status blocked
        }

        $rotation = $project->getAttribute('auths', [])['passwordRotation'] ?? [];
        if (
            ($rotation['enabled'] ?? false)
            && \microtime(true) - (float) (new \DateTime($profile->getAttribute('passwordUpdate')))->format('U.u') > ($rotation['maxAge'] ?? 31536000)
        ) {
            throw new Exception(Exception::USER_PASSWORD_RESET_REQUIRED);
        }

        $user->setAttributes($profile->getArrayCopy());

        $hooks->trigger('passwordValidator', [$dbForProject, $project, $password, &$user, false]);

        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $secret = $proofForToken->generate();
        $geoRecord = $geo->get($request->getIP());
        $session = new Document(array_merge(
            [
                '$id' => Id::unique(),
                'userId' => $user->getId(),
                'userInternalId' => $user->getSequence(),
                'provider' => SESSION_PROVIDER_EMAIL,
                'providerUid' => $email,
                'secret' => $proofForToken->hash($secret), // One way hash encryption to protect DB leak
                'userAgent' => $request->getUserAgent('UNKNOWN'),
                'ip' => $request->getIP(),
                'factors' => ['password'],
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
            $detector->getOS(),
            $detector->getClient(),
            $detector->getDevice()
        ));

        $authorization->addRole(Role::user($user->getId())->toString());

        $pwnedPolicy = $project->getAttribute('auths', [])['passwordPwned'] ?? [];

        if ($pwnedPolicy['enabled'] ?? true) {
            // Every sign-in records the outcome so the flag follows the breach corpus; only the sessions option refuses the sign-in
            $passwordPwned = !$pwnedPasswords->isValid($password);

            if ($passwordPwned !== $user->getAttribute('passwordPwned')) {
                $user->setAttribute('passwordPwned', $passwordPwned);
                $dbForProject->updateDocument('users', $user->getId(), new Document([
                    'passwordPwned' => $passwordPwned,
                ]));
            }

            if ($passwordPwned && ($pwnedPolicy['sessions'] ?? false)) {
                throw new Exception(Exception::USER_PASSWORD_RESET_REQUIRED);
            }
        }

        // Re-hash if not using recommended algo or its configured costs (read from the hash, not hashOptions)
        if (
            $user->getAttribute('hash') !== $proofForPassword->getHash()->getName()
            || \password_needs_rehash($user->getAttribute('password'), PASSWORD_ARGON2ID, $proofForPassword->getHash()->getOptions())
        ) {
            $user
                ->setAttribute('password', $proofForPassword->hash($password))
                ->setAttribute('hash', $proofForPassword->getHash()->getName())
                ->setAttribute('hashOptions', $proofForPassword->getHash()->getOptions());
            $dbForProject->updateDocument('users', $user->getId(), new Document([
                'password' => $user->getAttribute('password'),
                'hash' => $user->getAttribute('hash'),
                'hashOptions' => $user->getAttribute('hashOptions'),
            ]));
        }

        $session = $dbForProject->createDocument('sessions', $session->setAttribute('$permissions', [
            Permission::read(Role::user($user->getId())),
            Permission::update(Role::user($user->getId())),
            Permission::delete(Role::user($user->getId())),
        ]));

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $encoded = $store
            ->setProperty('id', $user->getId())
            ->setProperty('secret', $secret)
            ->encode();

        if (!$domainVerification) {
            $response->addHeader('X-Fallback-Cookies', \json_encode([$store->getKey() => $encoded]));
        }

        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), $duration));

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
            ->setPayload($response->output($session, Response::MODEL_SESSION), sensitive: ['secret', 'providerAccessToken', 'providerRefreshToken'])
        ;

        $bus->dispatch(new SessionCreated(
            user: $user->getArrayCopy(),
            project: $project->getArrayCopy(),
            session: $session->getArrayCopy(),
            locale: $locale->default,
        ));

        $response->dynamic($session, Response::MODEL_SESSION);
    }
}
