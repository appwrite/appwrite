<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions\LDAP;

use Appwrite\Auth\LDAP\Client;
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
use Utopia\Locale\Locale;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createLDAPSession';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/sessions/ldap')
            ->desc('Create LDAP session')
            ->groups(['api', 'account', 'auth', 'session'])
            ->label('event', 'users.[userId].sessions.[sessionId].create')
            ->label('scope', 'sessions.write')
            ->label('auth.type', 'ldap')
            ->label('audits.event', 'session.create')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('audits.userId', '{response.userId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'sessions',
                name: 'createLDAPSession',
                description: <<<EOT
                Allow the user to login into their account using the credentials held by your project's LDAP directory. Appwrite verifies the credentials by binding to the directory and never stores the password. This route will create a new session for the user.

                When the credentials are valid and no matching account exists yet, one is created from the directory entry. Which entries are eligible can be restricted to a group or filter in your project's LDAP settings.

                A user is limited to 10 active sessions at a time by default. [Learn more about session limits](https://appwrite.io/docs/authentication-security#limits).
                EOT,
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
            ->label('abuse-key', 'url:{url},username:{param-username}')
            ->label('abuse-reset', [201])
            ->param('username', '', new Text(256), 'Username as the directory knows it. Substituted into the configured user filter.')
            ->param('password', '', new Text(256, 0), 'User password held by the directory.')
            ->inject('request')
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('locale')
            ->inject('geoRecord')
            ->inject('queueForEvents')
            ->inject('bus')
            ->inject('store')
            ->inject('proofForPassword')
            ->inject('proofForToken')
            ->inject('domainVerification')
            ->inject('cookieDomain')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $username,
        string $password,
        Request $request,
        Response $response,
        User $user,
        Database $dbForProject,
        Document $project,
        Locale $locale,
        GeoRecord $geoRecord,
        Event $queueForEvents,
        Bus $bus,
        Store $store,
        ProofsPassword $proofForPassword,
        ProofsToken $proofForToken,
        bool $domainVerification,
        ?string $cookieDomain,
        Authorization $authorization
    ): void {
        $protocol = $request->getProtocol();

        // Whether LDAP is enabled for the project is checked by the shared
        // auth.type gate in app/controllers/shared/api/auth.php.
        $auths = $project->getAttribute('auths', []);

        $identity = Client::fromProject($project)->authenticate($username, $password);

        // A wrong password, an unknown user, and a user outside the provisioning
        // filter are deliberately indistinguishable: telling them apart lets a
        // directory be probed for valid usernames.
        if ($identity === null) {
            throw new Exception(Exception::USER_INVALID_CREDENTIALS);
        }

        $email = $identity['email'];

        // Resolve the account another concurrent request provisioned for this
        // same directory entry. Two first-time sign-ins can race at either
        // unique index — the email on the user, or (provider, providerUid) on
        // the identity — and both cases resolve the same way: adopt whatever
        // the winner created.
        $adoptConcurrentAccount = function () use ($dbForProject, $authorization, $identity) {
            // The winner creates the account and its identity as two writes, so
            // a request that loses the first race can arrive between them. Look
            // again briefly rather than failing a sign-in that is only early.
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $winner = $authorization->skip(fn () => $dbForProject->findOne('identities', [
                    Query::equal('provider', [SESSION_PROVIDER_LDAP]),
                    Query::equal('providerUid', [$identity['dn']]),
                ]));

                if (!$winner->isEmpty()) {
                    return $authorization->skip(fn () => $dbForProject->getDocument('users', $winner->getAttribute('userId')));
                }

                if ($attempt < 2) {
                    \usleep(50000);
                }
            }

            // Still no identity for this DN, so this was not a race for the
            // same entry. The identity index covers only the first 128
            // characters of a much longer value and the email index says
            // nothing about the directory at all, so the collision was with an
            // unrelated entry. There is nothing safe to adopt.
            throw new Exception(Exception::USER_INVALID_CREDENTIALS, 'This directory entry could not be linked to an account. Please try signing in again.');
        };

        // Match on the directory entry, not the email address. An email is not
        // proof of anything on its own: a directory entry carrying the same
        // address as an existing password account would otherwise sign straight
        // into it. The DN is what the bind actually authenticated.
        // Identity documents are permissioned to their owner, and there is no
        // authenticated user yet at this point in the flow.
        $ldapIdentity = $authorization->skip(fn () => $dbForProject->findOne('identities', [
            Query::equal('provider', [SESSION_PROVIDER_LDAP]),
            Query::equal('providerUid', [$identity['dn']]),
        ]));

        $profile = $ldapIdentity->isEmpty()
            ? new Document()
            : $authorization->skip(fn () => $dbForProject->getDocument('users', $ldapIdentity->getAttribute('userId')));

        // Whether this request is the one that created the account, which is
        // what decides if it is ours to delete when the link cannot be made.
        $provisioned = false;

        if ($profile->isEmpty()) {
            // No account is linked to this directory entry yet. Refuse to adopt
            // an existing account that merely shares the address: linking a
            // local account to a directory is a deliberate act, not something
            // a matching email should do silently.
            $userWithEmail = $dbForProject->findOne('users', [
                Query::equal('email', [$email]),
            ]);

            if (!$userWithEmail->isEmpty()) {
                throw new Exception(Exception::USER_ALREADY_EXISTS, 'An account with this email address already exists and is not linked to this LDAP directory.');
            }

            $limit = $auths['limit'] ?? 0;

            if ($limit !== 0) {
                $total = $dbForProject->count('users', max: APP_LIMIT_USERS);

                if ($total >= $limit) {
                    throw new Exception(Exception::USER_COUNT_EXCEEDED);
                }
            }

            // The directory just vouched for this person, so provision them.
            // Which directory entries are eligible is governed by the
            // provisioning filter, evaluated during the bind above.
            $userId = ID::unique();

            try {
                $profile = $authorization->skip(fn () => $dbForProject->createDocument('users', new Document([
                    '$id' => $userId,
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::update(Role::user($userId)),
                        Permission::delete(Role::user($userId)),
                    ],
                    'email' => $email,
                    // The directory is the authority on this address, and it
                    // just authenticated against it.
                    'emailVerification' => true,
                    'status' => true,
                    // No local password: this account can only ever be
                    // authenticated by the directory.
                    'password' => null,
                    'hash' => $proofForPassword->getHash()->getName(),
                    'hashOptions' => $proofForPassword->getHash()->getOptions(),
                    'passwordUpdate' => null,
                    'registration' => DateTime::now(),
                    'reset' => false,
                    'name' => $identity['name'] ?: null,
                    'mfa' => false,
                    'prefs' => new \stdClass(),
                    'sessions' => null,
                    'tokens' => null,
                    'memberships' => null,
                    'authenticators' => null,
                    'search' => \implode(' ', [$userId, $email, $identity['name']]),
                    'accessedAt' => DateTime::now(),
                ])));

                $provisioned = true;
            } catch (Duplicate) {
                // Another request provisioned this person first and won the
                // unique email index. Nothing was created here, so adopt theirs.
                $profile = $adoptConcurrentAccount();
            }
        }

        // Record the link between the directory entry and the account, so the
        // next sign-in matches on the DN rather than falling back to the email.
        //
        // The identity carries a unique index on (provider, providerUid), which
        // is what arbitrates two first-time sign-ins racing for the same entry.
        // The loser deletes the account it just created and adopts the winner's,
        // rather than leaving an orphaned duplicate behind.
        if ($ldapIdentity->isEmpty()) {
            try {
                $authorization->skip(fn () => $dbForProject->createDocument('identities', new Document([
                    '$id' => ID::unique(),
                    '$permissions' => [
                        Permission::read(Role::user($profile->getId())),
                        Permission::update(Role::user($profile->getId())),
                        Permission::delete(Role::user($profile->getId())),
                    ],
                    'userInternalId' => $profile->getSequence(),
                    'userId' => $profile->getId(),
                    'provider' => SESSION_PROVIDER_LDAP,
                    'providerUid' => $identity['dn'],
                    'providerEmail' => $email,
                    // LDAP issues no tokens: the bind is the whole exchange.
                    'providerAccessToken' => '',
                    'providerRefreshToken' => '',
                    'providerAccessTokenExpiry' => null,
                    'secrets' => null,
                ])));
            } catch (Duplicate) {
                // The identity index rejected this link. Release the account
                // this request created before adopting the winner's, so a
                // failed sign-in never leaves an orphan behind. Only an account
                // created here is ever deleted, and it is deleted whether the
                // adoption succeeds or throws.
                if ($provisioned) {
                    $orphan = $profile->getId();

                    try {
                        $authorization->skip(fn () => $dbForProject->deleteDocument('users', $orphan));
                    } catch (\Throwable) {
                        // Best effort. Failing to tidy up must not mask the
                        // reason the sign-in could not be completed.
                    }
                }

                $profile = $adoptConcurrentAccount();
            }
        }

        if (false === $profile->getAttribute('status')) {
            throw new Exception(Exception::USER_BLOCKED);
        }

        $user->setAttributes($profile->getArrayCopy());

        $duration = $auths['duration'] ?? TOKEN_EXPIRATION_LOGIN_LONG;
        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $secret = $proofForToken->generate();
        $session = new Document(array_merge(
            [
                '$id' => ID::unique(),
                'userId' => $user->getId(),
                'userInternalId' => $user->getSequence(),
                'provider' => SESSION_PROVIDER_LDAP,
                // The DN rather than the username: it is stable when display
                // attributes change, and unambiguous across the directory.
                'providerUid' => $identity['dn'],
                'secret' => $proofForToken->hash($secret),
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
