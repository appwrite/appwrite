<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions\LDAP;

use Appwrite\Abuse\Limit;
use Appwrite\Auth\LDAP\Client;
use Appwrite\Auth\MFA\Type;
use Appwrite\Auth\Validator\Phone;
use Appwrite\Bus\Events\SessionCreated;
use Appwrite\Detector\Detector;
use Appwrite\Event\Event;
use Appwrite\Event\Realtime;
use Appwrite\Extend\Exception;
use Appwrite\Geo\Geo;
use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Network\Validator\PublicHostname;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Account\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\SDK\Specification\Validator\PasswordFormat;
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
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Locale\Locale;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class Create extends Base
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
                Allow the user to login into their account by providing the username and password they use in the project's LDAP directory. The directory verifies the password; on the first sign-in an account is created from the directory entry, and on later sign-ins the name, email and phone follow the directory. This endpoint requires LDAP to be configured and enabled for the project. A user is limited to 10 active sessions at a time by default. [Learn more about session limits](https://appwrite.io/docs/authentication-security#limits).
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
            ->label('abuse', [
                Limit::failures(['projectId', 'url', 'param.username'], 10),
                Limit::failures(['projectId', 'url', 'ip'], 100),
            ])
            ->param('username', '', new Text(256), 'Directory username, matched against the username attribute of the project\'s LDAP configuration.')
            ->param('password', '', new PasswordFormat(new Text(256, 1)), 'Directory password.')
            ->param('duration', null, new Nullable(new Range(60, TOKEN_EXPIRATION_LOGIN_LONG)), 'Session length in seconds. Minimum is 60 seconds, and it cannot exceed the project maximum session length. Defaults to the project maximum session length.', true)
            ->inject('request')
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('locale')
            ->inject('geo')
            ->inject('queueForEvents')
            ->inject('bus')
            ->inject('store')
            ->inject('proofForToken')
            ->inject('proofForPassword')
            ->inject('plan')
            ->inject('domainVerification')
            ->inject('cookieDomain')
            ->inject('authorization')
            ->inject('mqtt')
            ->inject('queueForRealtime')
            ->inject('publicHostname')
            ->callback($this->action(...));
    }

    public function action(
        string $username,
        string $password,
        ?int $duration,
        Request $request,
        Response $response,
        User $user,
        Database $dbForProject,
        Document $project,
        Locale $locale,
        Geo $geo,
        Event $queueForEvents,
        Bus $bus,
        Store $store,
        ProofsToken $proofForToken,
        ProofsPassword $proofForPassword,
        array $plan,
        bool $domainVerification,
        ?string $cookieDomain,
        Authorization $authorization,
        Mqtt $mqtt,
        Realtime $queueForRealtime,
        PublicHostname $publicHostname,
    ): void {
        $ldap = $project->getAttribute('ldap', []);

        if (empty($ldap['hosts'] ?? []) || empty($ldap['baseDn'] ?? '')) {
            throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'LDAP is not configured for this project');
        }

        $maxDuration = $project->getAttribute('auths', [])['duration'] ?? TOKEN_EXPIRATION_LOGIN_LONG;
        if ($duration !== null && $duration > $maxDuration) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "Session duration cannot exceed the project maximum session length of {$maxDuration} seconds.");
        }
        $duration ??= $maxDuration;

        $entry = (new Client($publicHostname, $ldap))->authenticate($username, $password);

        // The directory is the project's own authority on who its users are, so the address
        // it returns is trusted the way a provider's attested email is for OAuth2.
        [$newUser, $newTarget] = $this->resolveUser($user, SESSION_PROVIDER_LDAP, $entry['id'], $entry['email'], true, $entry['name'], $dbForProject, $project, $plan, $proofForPassword, $authorization, $mqtt, $queueForRealtime);

        $authorization->addRole(Role::user($user->getId())->toString());
        $authorization->addRole(Role::users()->toString());

        if (false === $user->getAttribute('status')) { // Account is blocked
            throw new Exception(Exception::USER_BLOCKED);
        }

        $this->upsertIdentity($user, SESSION_PROVIDER_LDAP, $entry['id'], $entry['email'], [], ['providerEmail' => $entry['email']], $dbForProject, $authorization, $newUser, $newTarget);

        $this->refresh($user, $entry, $dbForProject, $project, $plan, $authorization);

        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $secret = $proofForToken->generate();
        $geoRecord = $geo->get($request->getIP());
        $session = new Document(array_merge(
            [
                '$id' => Id::unique(),
                'userId' => $user->getId(),
                'userInternalId' => $user->getSequence(),
                'provider' => SESSION_PROVIDER_LDAP,
                'providerUid' => $entry['id'],
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

    /**
     * Keep the account in step with the directory entry it was created from: the
     * directory is the authority on the user's name, email and phone, so a change
     * there is applied on the next sign-in. Mutates `$user` in place.
     *
     * @param array{id: string, dn: string, email: string, name: string, phone: string} $entry
     */
    private function refresh(User $user, array $entry, Database $dbForProject, Document $project, array $plan, Authorization $authorization): void
    {
        if ($entry['name'] !== '' && $entry['name'] !== $user->getAttribute('name')) {
            $user->setAttribute('name', $entry['name']);
            $dbForProject->updateDocument('users', $user->getId(), new Document([
                'name' => $entry['name'],
            ]));
        }

        if ($entry['email'] !== '' && $entry['email'] !== $user->getAttribute('email')) {
            $this->refreshEmail($user, $entry['email'], $dbForProject, $project, $plan, $authorization);
        }

        // A number the directory holds in another format cannot be messaged, so it is left alone
        if ($entry['phone'] !== '' && $entry['phone'] !== $user->getAttribute('phone') && (new Phone())->isValid($entry['phone'])) {
            $this->refreshPhone($user, $entry['phone'], $dbForProject, $authorization);
        }
    }

    /**
     * Move the account to the address the directory now holds, the way updateEmail does
     * for the user: the email target follows it, and tokens and challenges proving the
     * old address are dropped. Another account already using the address is a conflict.
     */
    private function refreshEmail(User $user, string $providerEmail, Database $dbForProject, Document $project, array $plan, Authorization $authorization): void
    {
        [$email, $emails, $emailMetadata, $canonicalize] = $this->parseEmail($providerEmail, true, $project, $plan);

        $userWithMatchingEmail = $dbForProject->find('users', [
            Query::equal('email', $emails),
            Query::notEqual('$id', $user->getId()),
        ]);
        if (!empty($userWithMatchingEmail)) {
            throw new Exception(Exception::USER_EMAIL_ALREADY_EXISTS);
        }

        $identityWithMatchingEmail = $dbForProject->findOne('identities', [
            Query::equal('providerEmail', [$providerEmail]),
            Query::notEqual('userInternalId', $user->getSequence()),
        ]);
        if (!$identityWithMatchingEmail->isEmpty()) {
            throw new Exception(Exception::USER_EMAIL_ALREADY_EXISTS);
        }

        $target = $authorization->skip(fn () => $dbForProject->findOne('targets', [
            Query::equal('identifier', [$email]),
        ]));
        if (!$target->isEmpty() && $target->getAttribute('userInternalId') !== $user->getSequence()) {
            throw new Exception(Exception::USER_EMAIL_ALREADY_EXISTS);
        }

        $this->assertEmailPolicy($emailMetadata, $email, $canonicalize, $project, $plan);

        $changes = [
            'email' => $email,
            'emailVerification' => true,
            'emailCanonical' => $emailMetadata['emailCanonical'],
            'emailIsCanonical' => $emailMetadata['emailIsCanonical'],
            'emailIsCorporate' => $emailMetadata['emailIsCorporate'],
            'emailIsDisposable' => $emailMetadata['emailIsDisposable'],
            'emailIsFree' => $emailMetadata['emailIsFree'],
        ];

        try {
            $updated = $dbForProject->withTransaction(function () use ($dbForProject, $authorization, $user, $email, $changes) {
                $current = $authorization->skip(fn () => $dbForProject->getDocument('users', $user->getId(), forUpdate: true));
                if ($current->isEmpty()) {
                    throw new Exception(Exception::USER_NOT_FOUND);
                }

                $previous = $current->getAttribute('email');
                $updated = $dbForProject->updateDocument('users', $user->getId(), new Document($changes));

                $oldTarget = \array_find($updated->getAttribute('targets', []), static fn (Document $target): bool => $target->getAttribute('providerType') === MESSAGE_TYPE_EMAIL && $target->getAttribute('identifier') === $previous);

                if ($oldTarget instanceof Document && !$oldTarget->isEmpty()) {
                    $authorization->skip(fn () => $dbForProject->updateDocument('targets', $oldTarget->getId(), $oldTarget->setAttribute('identifier', $email)));
                } else {
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
                }

                $sequence = $user->getSequence();
                if ($previous !== $email && $sequence !== '' && $sequence !== null) {
                    $authorization->skip(fn () => $dbForProject->deleteDocuments('tokens', [
                        Query::equal('userInternalId', [$sequence]),
                        Query::equal('type', [
                            TOKEN_TYPE_RECOVERY,
                            TOKEN_TYPE_RECOVERY_OTP,
                            TOKEN_TYPE_VERIFICATION,
                            TOKEN_TYPE_VERIFICATION_OTP,
                            TOKEN_TYPE_MAGIC_URL,
                            TOKEN_TYPE_EMAIL,
                        ]),
                    ]));
                    $authorization->skip(fn () => $dbForProject->deleteDocuments('challenges', [
                        Query::equal('userInternalId', [$sequence]),
                        Query::equal('type', [Type::EMAIL]),
                    ]));
                    // A pending invite was sent to the old address, so it must not prove the new one
                    $authorization->skip(fn () => $dbForProject->updateDocuments('memberships', new Document(['secret' => '']), [
                        Query::equal('userInternalId', [$sequence]),
                        Query::equal('confirm', [false]),
                    ]));
                }

                return $updated;
            });
        } catch (Duplicate) {
            throw new Exception(Exception::USER_EMAIL_ALREADY_EXISTS);
        }

        $user->setAttributes($updated->getArrayCopy());
        $dbForProject->purgeCachedDocument('users', $user->getId());
    }

    /**
     * Move the account to the number the directory now holds, the way updatePhone does
     * for the user. The number is not verified: the directory vouches for who the user
     * is, not for who answers the phone.
     */
    private function refreshPhone(User $user, string $phone, Database $dbForProject, Authorization $authorization): void
    {
        $target = $authorization->skip(fn () => $dbForProject->findOne('targets', [
            Query::equal('identifier', [$phone]),
        ]));
        if (!$target->isEmpty() && $target->getAttribute('userInternalId') !== $user->getSequence()) {
            throw new Exception(Exception::USER_PHONE_ALREADY_EXISTS);
        }

        try {
            $updated = $dbForProject->withTransaction(function () use ($dbForProject, $authorization, $user, $phone) {
                $current = $authorization->skip(fn () => $dbForProject->getDocument('users', $user->getId(), forUpdate: true));
                if ($current->isEmpty()) {
                    throw new Exception(Exception::USER_NOT_FOUND);
                }

                $previous = $current->getAttribute('phone');
                $updated = $dbForProject->updateDocument('users', $user->getId(), new Document([
                    'phone' => $phone,
                    'phoneVerification' => false,
                ]));

                $oldTarget = \array_find($updated->getAttribute('targets', []), static fn (Document $target): bool => $target->getAttribute('providerType') === MESSAGE_TYPE_SMS && $target->getAttribute('identifier') === $previous);

                if ($oldTarget instanceof Document && !$oldTarget->isEmpty()) {
                    $authorization->skip(fn () => $dbForProject->updateDocument('targets', $oldTarget->getId(), $oldTarget->setAttribute('identifier', $phone)));
                } else {
                    $dbForProject->createDocument('targets', new Document([
                        '$permissions' => [
                            Permission::read(Role::user($user->getId())),
                            Permission::update(Role::user($user->getId())),
                            Permission::delete(Role::user($user->getId())),
                        ],
                        'userId' => $user->getId(),
                        'userInternalId' => $user->getSequence(),
                        'providerType' => MESSAGE_TYPE_SMS,
                        'identifier' => $phone,
                    ]));
                }

                $sequence = $user->getSequence();
                if ($previous !== $phone && $sequence !== '' && $sequence !== null) {
                    $authorization->skip(function () use ($dbForProject, $sequence) {
                        $dbForProject->deleteDocuments('tokens', [
                            Query::equal('userInternalId', [$sequence]),
                            Query::equal('type', [TOKEN_TYPE_PHONE]),
                        ]);
                        $dbForProject->deleteDocuments('challenges', [
                            Query::equal('userInternalId', [$sequence]),
                            Query::equal('type', [Type::PHONE]),
                        ]);
                        // A pending invite was sent to the old number, so it must not prove the new one
                        $dbForProject->updateDocuments('memberships', new Document(['secret' => '']), [
                            Query::equal('userInternalId', [$sequence]),
                            Query::equal('confirm', [false]),
                        ]);
                    });
                }

                return $updated;
            });
        } catch (Duplicate) {
            throw new Exception(Exception::USER_PHONE_ALREADY_EXISTS);
        }

        $user->setAttributes($updated->getArrayCopy());
        $dbForProject->purgeCachedDocument('users', $user->getId());
    }
}
