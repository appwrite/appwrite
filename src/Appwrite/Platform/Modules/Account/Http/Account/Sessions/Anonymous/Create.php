<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions\Anonymous;

use Appwrite\Detector\Detector;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Geo\Geo;
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
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Locale\Locale;
use Utopia\Platform\Scope\HTTP;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createAnonymousSession';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/sessions/anonymous')
            ->desc('Create anonymous session')
            ->groups(['api', 'account', 'auth', 'session'])
            ->label('event', 'users.[userId].sessions.[sessionId].create')
            ->label('scope', 'sessions.write')
            ->label('auth.type', 'anonymous')
            ->label('audits.event', 'session.create')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('audits.userId', '{response.userId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'sessions',
                name: 'createAnonymousSession',
                description: '/docs/references/account/create-session-anonymous.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_SESSION,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->label('abuse-limit', 50)
            ->label('abuse-key', 'ip:{ip}')
            ->inject('request')
            ->inject('response')
            ->inject('locale')
            ->inject('user')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('geo')
            ->inject('queueForEvents')
            ->inject('store')
            ->inject('proofForPassword')
            ->inject('proofForToken')
            ->inject('domainVerification')
            ->inject('cookieDomain')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        Request $request,
        Response $response,
        Locale $locale,
        User $user,
        Document $project,
        Database $dbForProject,
        Geo $geo,
        Event $queueForEvents,
        Store $store,
        ProofsPassword $proofForPassword,
        ProofsToken $proofForToken,
        bool $domainVerification,
        ?string $cookieDomain,
        Authorization $authorization,
    ): void {
        $protocol = $request->getProtocol();

        if ('console' === $project->getId()) {
            throw new Exception(Exception::USER_ANONYMOUS_CONSOLE_PROHIBITED, 'Failed to create anonymous user');
        }

        $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

        if ($limit !== 0) {
            $total = $dbForProject->count('users', max: APP_LIMIT_USERS);

            if ($total >= $limit) {
                throw new Exception(Exception::USER_COUNT_EXCEEDED);
            }
        }

        $userId = Id::unique();
        $user->setAttributes([
            '$id' => $userId,
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::user($userId)),
                Permission::delete(Role::user($userId)),
            ],
            'email' => null,
            'emailVerification' => false,
            'status' => true,
            'password' => null,
            'hash' => $proofForPassword->getHash()->getName(),
            'hashOptions' => $proofForPassword->getHash()->getOptions(),
            'passwordUpdate' => null,
            'registration' => DateTime::now(),
            'reset' => false,
            'name' => null,
            'mfa' => false,
            'prefs' => new \stdClass(),
            'sessions' => null,
            'tokens' => null,
            'memberships' => null,
            'authenticators' => null,
            'search' => $userId,
            'accessedAt' => DateTime::now(),
        ]);
        $user->removeAttribute('$sequence');
        $user = $authorization->skip(fn () => $dbForProject->createDocument('users', $user));

        // Create session token
        $duration = $project->getAttribute('auths', [])['duration'] ?? TOKEN_EXPIRATION_LOGIN_LONG;
        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $secret = $proofForToken->generate();

        $geoRecord = $geo->get($request->getIP());
        $session = new Document(array_merge(
            [
                '$id' => Id::unique(),
                'userId' => $user->getId(),
                'userInternalId' => $user->getSequence(),
                'provider' => SESSION_PROVIDER_ANONYMOUS,
                'secret' => $proofForToken->hash($secret), // One way hash encryption to protect DB leak
                'userAgent' => $request->getUserAgent('UNKNOWN'),
                'ip' => $request->getIP(),
                'factors' => ['anonymous'],
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

        $response->dynamic($session, Response::MODEL_SESSION);
    }
}
