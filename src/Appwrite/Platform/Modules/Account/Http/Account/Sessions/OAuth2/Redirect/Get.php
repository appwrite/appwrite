<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions\OAuth2\Redirect;

use Ahc\Jwt\JWT;
use Appwrite\Abuse\Limit;
use Appwrite\Auth\AuthDevice;
use Appwrite\Auth\EncryptionKey;
use Appwrite\Auth\MFA\Type;
use Appwrite\Auth\OAuth2;
use Appwrite\Auth\OAuth2\Exception as OAuth2Exception;
use Appwrite\Bus\Events\EmailVerified;
use Appwrite\Detector\Detector;
use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Extend\Exception;
use Appwrite\Geo\Geo;
use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Network\Validator\Redirect;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Account\Base;
use Appwrite\URL\URL as URLParser;
use Appwrite\Utopia\Database\Documents\Project;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Auth\Hashes\Sha;
use Utopia\Auth\Proofs\Password as ProofsPassword;
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
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Emails\Email;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Text;
use Utopia\Validator\Validator;
use Utopia\Validator\WhiteList;

class Get extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'getOAuth2Redirect';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/account/sessions/oauth2/:provider/redirect')
            ->desc('Get OAuth2 redirect')
            ->groups(['api', 'account', 'session'])
            ->label('session.allowActive', true)
            ->label('error', APP_VIEWS_DIR . '/general/error.phtml')
            ->label('event', 'users.[userId].sessions.[sessionId].create')
            ->label('scope', 'public')
            ->label('audits.event', 'session.create')
            ->label('audits.resource', 'user/{user.$id}')
            ->label('audits.userId', '{user.$id}')
            ->label('abuse', [Limit::attempts(['ip'], 50)])
            ->label('docs', false)
            ->param('provider', '', new WhiteList(\array_keys(Config::getParam('oAuthProviders')), true), 'OAuth2 provider.')
            ->param('code', '', new Text(2048, 0), 'OAuth2 code. This is a temporary code that the will be later exchanged for an access token.', true)
            // 512 more for the app's state: 256 printable ASCII chars, at most doubled by JSON escaping
            ->param('state', '', new Text(2048 + 512), 'OAuth2 state params.', true)
            ->param('error', '', new Text(2048, 0), 'Error code returned from the OAuth2 provider.', true)
            ->param('error_description', '', new Text(2048, 0), 'Human-readable text providing additional information about the error returned from the OAuth2 provider.', true)
            ->param('device_id', '', new Text(256, 0), 'Device ID returned from the OAuth2 provider.', true)
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('redirectValidator')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('geo')
            ->inject('dbForPlatform')
            ->inject('queueForEvents')
            ->inject('store')
            ->inject('proofForPassword')
            ->inject('proofForToken')
            ->inject('plan')
            ->inject('domainVerification')
            ->inject('cookieDomain')
            ->inject('authorization')
            ->inject('platform')
            ->inject('clientForOAuth2')
            ->inject('mqtt')
            ->inject('queueForRealtime')
            ->inject('authDevice')
            ->inject('publisherForDeletes')
            ->inject('bus')
            ->callback($this->action(...));
    }

    public function action(
        string $provider,
        string $code,
        string $state,
        string $error,
        string $error_description,
        string $device_id,
        Request $request,
        Response $response,
        Project $project,
        Validator $redirectValidator,
        User $user,
        Database $dbForProject,
        Geo $geo,
        Database $dbForPlatform,
        Event $queueForEvents,
        Store $store,
        ProofsPassword $proofForPassword,
        ProofsToken $proofForToken,
        array $plan,
        bool $domainVerification,
        ?string $cookieDomain,
        Authorization $authorization,
        array $platform,
        Client $clientForOAuth2,
        Mqtt $mqtt,
        Realtime $queueForRealtime,
        ?Document $authDevice,
        DeletePublisher $publisherForDeletes,
        Bus $bus,
    ): void {
        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
        $callback = $this->getOAuth2Callback($provider, $project, $request);
        $defaultState = ['success' => $project->getAttribute('url', ''), 'failure' => ''];
        $appId = $project->getAttribute('oAuthProviders', [])[$provider . 'Appid'] ?? '';
        $appSecret = $project->getAttribute('oAuthProviders', [])[$provider . 'Secret'] ?? '{}';
        $providerEnabled = $project->getAttribute('oAuthProviders', [])[$provider . 'Enabled'] ?? false;

        $definition = $this->getOAuth2Provider($provider);
        $className = $definition['class'] ?? null;
        if ($className === null || !\class_exists($className)) {
            throw new Exception(Exception::PROJECT_PROVIDER_UNSUPPORTED);
        }

        $providerName = $definition['name'] ?? '';

        /** @var OAuth2 $oauth2 */
        $oauth2 = new $className($clientForOAuth2, $appId, $appSecret, $callback);

        if (!empty($state)) {
            try {
                $state = \array_merge($defaultState, $oauth2->parseState($state));
            } catch (\Throwable $exception) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to parse login state params as passed from OAuth2 provider');
            }
        } else {
            $state = $defaultState;
        }

        // Allow redirect to rule URL if related to project
        // Check if $redirectValidator is instance of Redirect class
        if ($redirectValidator instanceof Redirect) {
            $domains = \array_filter([
                parse_url($state['success'], PHP_URL_HOST) ?? '',
                parse_url($state['failure'], PHP_URL_HOST) ?? ''
            ], fn ($domain) => \is_string($domain) && $domain !== '');

            if (!empty($domains)) {
                $rules = $authorization->skip(fn () => $dbForPlatform->find('rules', [
                    Query::equal('domain', \array_values(\array_unique($domains))),
                    Query::equal('projectInternalId', [$project->getSequence()]),
                    Query::limit(2)
                ]));

                foreach ($rules as $rule) {
                    $allowedHostnames = $redirectValidator->getAllowedHostnames();
                    $allowedHostnames[] = $rule->getAttribute('domain', '');
                    $redirectValidator->setAllowedHostnames($allowedHostnames);
                }
            }
        }

        if (!$redirectValidator->isValid($state['success'])) {
            throw new Exception(Exception::PROJECT_INVALID_SUCCESS_URL);
        }

        if (!empty($state['failure']) && !$redirectValidator->isValid($state['failure'])) {
            throw new Exception(Exception::PROJECT_INVALID_FAILURE_URL);
        }
        // The default relays live on the console host; the same path on any other allowed host is a customer page.
        // Native apps skip the relay: its JavaScript redirect into the app is late or dropped on slow in-app browsers.
        $consoleHostname = \parse_url($platform['consoleUrl'] ?? '', PHP_URL_HOST);
        $nativeCallback = ['scheme' => $project->getCallbackScheme()];

        $failure = [];
        if (!empty($state['failure'])) {
            $failure = URLParser::parse($state['failure']);
        }

        $failureRedirect = (function (string $type, ?string $message = null, ?int $code = null, ?\Throwable $previous = null, array $params = []) use ($failure, $response, $project, $consoleHostname, $nativeCallback, $state) {
            $exception = new Exception($type, $message, $code, $previous, params: $params);
            if (!empty($failure)) {
                $query = URLParser::parseQuery($failure['query']);
                if (\is_string($state['state'] ?? null) && $state['state'] !== '') {
                    $query['state'] = $state['state'];
                }
                $query['error'] = json_encode([
                    'message' => $exception->getMessage(),
                    'type' => $exception->getType(),
                    'code' => !\is_null($code) ? $code : $exception->getCode(),
                ]);
                if ($failure['host'] === $consoleHostname && $failure['path'] === self::OAUTH_DEFAULT_FAILURE) {
                    $query['project'] = $project->getId();
                    $failure = $nativeCallback;
                }
                $failure['query'] = URLParser::unparseQuery($query);
                $response->redirect(URLParser::unparse($failure), 301);
            }

            throw $exception;
        });

        if (!$providerEnabled) {
            $failureRedirect(Exception::PROJECT_PROVIDER_DISABLED, 'This provider is disabled. Please enable the provider from your ' . APP_NAME . ' console to continue.');
        }

        // The state comes back through the provider unchanged, so it says nothing about which
        // browser started the flow (RFC 6749 §10.12). The nonce cookie set alongside it does:
        // only that browser holds it. The cookie is not
        // rewritten here: two callbacks finishing together would race on it, and replaying an
        // authorization code is refused by the provider (RFC 6749 §4.1.2).
        // A token flow started by the app's server leaves that cookie on the server, so an unbound
        // token flow still proceeds, but as a guest: the browser's signed-in user is not proof that
        // it started this flow, and linking the provider identity to it would let anyone attach
        // their identity to that account by sending the callback link. The app binds the token it
        // receives to its own user through the `state` it passed in.
        // A sibling site can plant the nonce cookie, so the flow is also bound to the user who started it.
        // A guest callback accepts a state without a binding: flows started before it existed still finish.
        $nonces = \array_filter(\explode(',', $request->getCookie('a_oauth2_' . $project->getId())));
        $stateNonce = \is_string($state['nonce'] ?? null) ? $state['nonce'] : '';
        $stateBinding = \is_string($state['binding'] ?? null) ? $state['binding'] : '';
        $bound = \array_any(EncryptionKey::accepted(), fn (string $key) => \hash_equals(\hash_hmac('sha256', $stateNonce . ':' . $user->getId(), $key), $stateBinding))
            || ($stateBinding === '' && $user->isEmpty());
        if (!\array_any($nonces, fn (string $held) => \hash_equals($held, $stateNonce)) || !$bound) {
            if (empty($state['token'])) {
                $failureRedirect(Exception::USER_OAUTH2_STATE_INVALID);
            }

            $user = new User();
        }

        if (!empty($error)) {
            $message = 'The ' . $providerName . ' OAuth2 provider returned an error: ' . $error;
            if (!empty($error_description)) {
                $message .= ': ' . $error_description;
            }
            $failureRedirect(Exception::USER_OAUTH2_PROVIDER_ERROR, $message);
        }

        if (empty($code)) {
            $failureRedirect(Exception::USER_OAUTH2_PROVIDER_ERROR, 'Missing OAuth2 code. Please contact the Appwrite team for additional support.');
        }

        if (!empty($appSecret) && isset($appSecret['version'])) {
            $key = System::getEnv('_APP_OPENSSL_KEY_V' . $appSecret['version']);
            $appSecret = OpenSSL::decrypt($appSecret['data'], $appSecret['method'], $key, 0, \hex2bin($appSecret['iv']), \hex2bin($appSecret['tag']));
        }

        $accessToken = '';
        $refreshToken = '';
        $accessTokenExpiry = 0;
        $oauth2ID = '';

        $oauth2->setDeviceId($device_id);

        try {
            $accessToken = $oauth2->getAccessToken($code);
            $refreshToken = $oauth2->getRefreshToken($code);
            $accessTokenExpiry = $oauth2->getAccessTokenExpiry($code);
            $oauth2ID = $oauth2->getUserID($accessToken);
        } catch (OAuth2Exception $ex) {
            $providerError = $ex->getError() ?: $ex->getMessage();

            $failureRedirect(
                Exception::USER_OAUTH2_PROVIDER_FAILURE,
                previous: $ex,
                params: [$providerName, $providerError],
            );
        }

        if (empty($oauth2ID)) {
            $failureRedirect(Exception::USER_MISSING_ID);
        }

        // The identity names something people share, so it can attach to the signed-in caller but never sign anyone in
        if ($user->isEmpty() && $oauth2->isLinkOnly()) {
            $failureRedirect(Exception::USER_UNAUTHORIZED);
        }

        // Everyone who shares it gets the same provider ID, so each account keeps its own link under an ID scoped to
        // it. Without that, the first account to connect a Bird workspace blocks everyone else in it. A link made
        // before the scoping keeps its unscoped ID, so it is refreshed rather than duplicated.
        if ($oauth2->isLinkOnly()) {
            $unscoped = $dbForProject->findOne('identities', [
                Query::equal('userInternalId', [$user->getSequence()]),
                Query::equal('provider', [$provider]),
                Query::equal('providerUid', [$oauth2ID]),
            ]);

            if ($unscoped->isEmpty()) {
                $oauth2ID = $user->getId() . ':' . $oauth2ID;
            }
        }

        $name = '';
        $nameOAuth = $oauth2->getUserName($accessToken);
        $userParam = $request->getParam('user');
        if (!empty($nameOAuth)) {
            $name = $nameOAuth;
        } elseif ($userParam !== null) {
            $userDecoded = \json_decode($userParam, true);
            if (isset($userDecoded['name']['firstName']) && isset($userDecoded['name']['lastName'])) {
                $name = $userDecoded['name']['firstName'] . ' ' . $userDecoded['name']['lastName'];
            }
        }
        $providerEmail = $oauth2->getUserEmail($accessToken);
        $email = $providerEmail;

        // Check if this identity is connected to a different user
        if (!$user->isEmpty()) {
            $userId = $user->getId();

            $identityWithMatchingUid = $dbForProject->findOne('identities', [
                Query::equal('provider', [$provider]),
                Query::equal('providerUid', [$oauth2ID]),
                Query::notEqual('userInternalId', $user->getSequence()),
            ]);
            if (!$identityWithMatchingUid->isEmpty()) {
                $failureRedirect(Exception::USER_ALREADY_EXISTS);
            }

            if (!empty($providerEmail)) {
                $identityWithMatchingEmail = $dbForProject->findOne('identities', [
                    Query::equal('providerEmail', [$providerEmail]),
                    Query::notEqual('userInternalId', $user->getSequence()),
                ]);
                if (!$identityWithMatchingEmail->isEmpty()) {
                    $failureRedirect(Exception::USER_ALREADY_EXISTS);
                }

                $userWithMatchingEmail = $dbForProject->find('users', [
                    Query::equal('email', [$email]),
                    Query::notEqual('$id', $userId),
                ]);
                if (!empty($userWithMatchingEmail)) {
                    $failureRedirect(Exception::USER_ALREADY_EXISTS);
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
                Query::equal('providerUid', [$oauth2ID]),
            ]);
            if (!$session->isEmpty()) {
                $user->setAttributes($dbForProject->getDocument('users', $session->getAttribute('userId'))->getArrayCopy());
                $grounds = ['sessions' => $session->getId()];
            }
        }

        $newUser = null;
        $newTarget = null;

        if ($user->isEmpty()) { // No user logged in or with OAuth2 provider ID, create new one or connect with account with same email
            $isVerified = $oauth2->isEmailVerified($accessToken);

            $identity = $dbForProject->findOne('identities', [
                Query::equal('provider', [$provider]),
                Query::equal('providerUid', [$oauth2ID]),
            ]);

            if (!$identity->isEmpty()) {
                $user = $dbForProject->getDocument('users', $identity->getAttribute('userId'));
                $grounds = ['identities' => $identity->getId()];
            }

            $emailMetadata = [
                'emailCanonical' => null,
                'emailIsCanonical' => null,
                'emailIsCorporate' => null,
                'emailIsDisposable' => null,
                'emailIsFree' => null,
            ];
            $emails = [$email];
            $canonicalize = false;
            if ($user->isEmpty() && !empty($providerEmail)) {
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
                } catch (\Throwable) {
                    $failureRedirect(Exception::GENERAL_INVALID_EMAIL);
                }
            }

            // If user is not found, check if there is a user with the same email
            if ($user->isEmpty() && !empty($email)) {
                $userWithEmail = $dbForProject->findOne('users', [
                    Query::equal('email', $emails),
                ]);
                if (!$userWithEmail->isEmpty()) {
                    if (!$isVerified) {
                        $failureRedirect(Exception::GENERAL_BAD_REQUEST);
                    }
                    $user->setAttributes($userWithEmail->getArrayCopy());
                    // The provider verified this address, or its canonical form on the same domain
                    if ((new Email($user->getAttribute('email')))->getDomain() === (new Email($providerEmail))->getDomain()) {
                        // Proving an unverified address claims the account, so whoever registered it keeps no other way in.
                        // The claim takes the user row lock, so of two concurrent claims only the first revokes, and a
                        // request that re-checks the account under the same lock finds it already claimed
                        $address = $user->getAttribute('email');
                        $claimed = $authorization->skip(fn () => User::claim($dbForProject, $user, $project, mqtt: $mqtt));

                        if ($user->getAttribute('email') !== $address) {
                            $failureRedirect(Exception::GENERAL_BAD_REQUEST);
                        }

                        if ($claimed) {
                            $dbForProject->purgeCachedDocument('users', $user->getId());

                            // The claim is what verified the address, before the stored row is read below
                            $bus->dispatch(new EmailVerified(user: $user->getArrayCopy(), project: $project->getArrayCopy()));

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
                        $failureRedirect(Exception::GENERAL_BAD_REQUEST);
                    }
                    $user->setAttributes($dbForProject->getDocument('users', $identityWithMatchingEmail->getAttribute('userId'))->getArrayCopy());
                    $grounds = ['identities' => $identityWithMatchingEmail->getId()];
                }
            }

            if ($user->isEmpty()) { // Last option -> create the user
                $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

                if ($limit !== 0) {
                    $total = $dbForProject->count('users', max: APP_LIMIT_USERS);

                    if ($total >= $limit) {
                        $failureRedirect(Exception::USER_COUNT_EXCEEDED);
                    }
                }

                if (!empty($email) && (($project->getId() === 'console') || empty($plan) || ($plan['supportsDisposableEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['disposableEmails'] ?? false) && $emailMetadata['emailIsDisposable']) {
                    $failureRedirect(Exception::USER_EMAIL_DISPOSABLE);
                }

                // When $canonicalize is true we already applied delivery-safe
                // local-part normalization while preserving the provider domain.
                if (!empty($email) && (($project->getId() === 'console') || empty($plan) || ($plan['supportsCanonicalEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['canonicalEmails'] ?? false) && $emailMetadata['emailIsCanonical'] === false && !$canonicalize) {
                    $failureRedirect(Exception::USER_EMAIL_NOT_CANONICAL);
                }

                if (!empty($email) && (($project->getId() === 'console') || empty($plan) || ($plan['supportsFreeEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['freeEmails'] ?? false) && $emailMetadata['emailIsFree']) {
                    $failureRedirect(Exception::USER_EMAIL_FREE);
                }

                if (!empty($email) && (($project->getId() === 'console') || empty($plan) || ($plan['supportsCorporateEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['corporateEmails'] ?? false) && !$emailMetadata['emailIsCorporate']) {
                    $failureRedirect(Exception::USER_EMAIL_NOT_CORPORATE);
                }

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
                        'emailVerification' => !empty($email) && $isVerified, // Trust the provider's userinfo claim, not the mere fact an email was returned
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
                        'search' => implode(' ', [$userId, $email, $name]),
                        'accessedAt' => DateTime::now(),
                        'emailCanonical' => $emailMetadata['emailCanonical'],
                        'emailIsCanonical' => $emailMetadata['emailIsCanonical'],
                        'emailIsCorporate' => $emailMetadata['emailIsCorporate'],
                        'emailIsDisposable' => $emailMetadata['emailIsDisposable'],
                        'emailIsFree' => $emailMetadata['emailIsFree'],
                    ]);

                    $user->removeAttribute('$sequence');
                    $userDoc = $authorization->skip(fn () => $dbForProject->createDocument('users', $user));
                    $newUser = $userDoc;
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
                } catch (Duplicate) {
                    $failureRedirect(Exception::USER_ALREADY_EXISTS);
                }
            }
        }

        $authorization->addRole(Role::user($user->getId())->toString());
        $authorization->addRole(Role::users()->toString());

        if (false === $user->getAttribute('status')) { // Account is blocked
            $failureRedirect(Exception::USER_BLOCKED); // User is in status blocked
        }

        // The account as the request authenticated against it, before this sign-in changes anything on it
        $snapshot = new Document($user->getArrayCopy());

        // Only a user this sign-in created and an address it fills in turn verified from here, and both are
        // saved below; a claim already dispatched EmailVerified and left the account verified
        $wasVerified = $newUser === null && $user->getAttribute('emailVerification', false);

        $backfilled = empty($user->getAttribute('email')) && !empty($providerEmail);
        $backfillEmail = null;
        if ($backfilled) {
            $emailMetadata = [
                'emailCanonical' => null,
                'emailIsCanonical' => null,
                'emailIsCorporate' => null,
                'emailIsDisposable' => null,
                'emailIsFree' => null,
            ];

            $isVerified = $oauth2->isEmailVerified($accessToken);

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
            } catch (\Throwable) {
                $failureRedirect(Exception::GENERAL_INVALID_EMAIL);
            }

            $userWithMatchingEmail = $dbForProject->find('users', [
                Query::equal('email', $emails),
                Query::notEqual('$id', $user->getId()),
            ]);
            if (!empty($userWithMatchingEmail)) {
                $failureRedirect(Exception::USER_ALREADY_EXISTS);
            }

            if ((($project->getId() === 'console') || empty($plan) || ($plan['supportsDisposableEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['disposableEmails'] ?? false) && $emailMetadata['emailIsDisposable']) {
                $failureRedirect(Exception::USER_EMAIL_DISPOSABLE);
            }

            // When $canonicalize is true we already applied delivery-safe
            // local-part normalization while preserving the provider domain.
            if ((($project->getId() === 'console') || empty($plan) || ($plan['supportsCanonicalEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['canonicalEmails'] ?? false) && $emailMetadata['emailIsCanonical'] === false && !$canonicalize) {
                $failureRedirect(Exception::USER_EMAIL_NOT_CANONICAL);
            }

            if ((($project->getId() === 'console') || empty($plan) || ($plan['supportsFreeEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['freeEmails'] ?? false) && $emailMetadata['emailIsFree']) {
                $failureRedirect(Exception::USER_EMAIL_FREE);
            }

            if ((($project->getId() === 'console') || empty($plan) || ($plan['supportsCorporateEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['corporateEmails'] ?? false) && !$emailMetadata['emailIsCorporate']) {
                $failureRedirect(Exception::USER_EMAIL_NOT_CORPORATE);
            }

            $user->setAttribute('email', $email);
            // Never downgrade an already-verified user; only ever promote to verified
            $user->setAttribute('emailVerification', $user->getAttribute('emailVerification', false) || $isVerified);
            $user->setAttribute('emailCanonical', $emailMetadata['emailCanonical']);
            $user->setAttribute('emailIsCanonical', $emailMetadata['emailIsCanonical']);
            $user->setAttribute('emailIsCorporate', $emailMetadata['emailIsCorporate']);
            $user->setAttribute('emailIsDisposable', $emailMetadata['emailIsDisposable']);
            $user->setAttribute('emailIsFree', $emailMetadata['emailIsFree']);

            // Its email target is reserved with the rest of the sign-in, once the account is locked and unchanged
            $backfillEmail = $email;
        }

        // Only what this request changed: its copy predates any claim or password change that landed since
        $changes = ['status' => true];

        if (empty($user->getAttribute('name'))) {
            $user->setAttribute('name', $oauth2->getUserName($accessToken));
            $changes['name'] = $user->getAttribute('name');
        }

        if ($backfilled) {
            $changes += \array_intersect_key($user->getArrayCopy(), \array_flip(['email', 'emailVerification', 'emailCanonical', 'emailIsCanonical', 'emailIsCorporate', 'emailIsDisposable', 'emailIsFree']));
        }

        $user->setAttribute('status', true);

        $authorization->addRole(Role::user($user->getId())->toString());

        $state['success'] = URLParser::parse($state['success']);
        $query = URLParser::parseQuery($state['success']['query']);

        $duration = $project->getAttribute('auths', [])['duration'] ?? TOKEN_EXPIRATION_LOGIN_LONG;
        $expire = DateTime::addSeconds(new \DateTime(), $duration);

        $proofForTokenOAuth2 = new ProofsToken(TOKEN_LENGTH_OAUTH2);
        $proofForTokenOAuth2->setHash(new Sha());
        // If the `token` param is set, we will return the token in the query string
        if ($state['token']) {
            $secret = $proofForTokenOAuth2->generate();
            $token = new Document([
                '$id' => Id::unique(),
                '$permissions' => [
                    Permission::read(Role::user($user->getId())),
                    Permission::update(Role::user($user->getId())),
                    Permission::delete(Role::user($user->getId())),
                ],
                'userId' => $user->getId(),
                'userInternalId' => $user->getSequence(),
                'type' => TOKEN_TYPE_OAUTH2,
                'secret' => $proofForTokenOAuth2->hash($secret), // One way hash encryption to protect DB leak
                'expire' => $expire,
                'userAgent' => $request->getUserAgent('UNKNOWN'),
                'ip' => $request->getClientIP(),
            ]);

            // If the `token` param is not set, we persist the session in a cookie
        } else {
            $detector = new Detector($request->getUserAgent('UNKNOWN'));
            $secret = $proofForToken->generate();

            $geoRecord = $geo->get($request->getClientIP());
            $session = new Document(array_merge([
                '$id' => Id::unique(),
                '$permissions' => [
                    Permission::read(Role::user($user->getId())),
                    Permission::update(Role::user($user->getId())),
                    Permission::delete(Role::user($user->getId())),
                ],
                'userId' => $user->getId(),
                'userInternalId' => $user->getSequence(),
                // The account switching device the session joins, when the browser holds one
                'deviceId' => $authDevice?->getId() ?? '',
                'deviceInternalId' => $authDevice?->getSequence() ?? '',
                'provider' => $provider,
                'providerUid' => $oauth2ID,
                'providerAccessToken' => $accessToken,
                'providerRefreshToken' => $refreshToken,
                'providerAccessTokenExpiry' => DateTime::addSeconds(new \DateTime(), (int)$accessTokenExpiry),
                'secret' => $proofForToken->hash($secret), // One way hash encryption to protect DB leak
                'userAgent' => $request->getUserAgent('UNKNOWN'),
                'ip' => $request->getClientIP(),
                'factors' => [TYPE::EMAIL, 'oauth2'], // include a special oauth2 factor to bypass MFA checks
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
            ], $detector->getOS(), $detector->getClient(), $detector->getDevice()));
        }

        // Stored with a new identity and refreshed on an existing one. The photo URL is refreshed on every login so
        // expired CDN links self-heal
        $credentials = [
            'providerAccessToken' => $accessToken,
            'providerRefreshToken' => $refreshToken,
            'providerAccessTokenExpiry' => DateTime::addSeconds(new \DateTime(), (int) $accessTokenExpiry),
            'photo' => $oauth2->getUserPhoto($accessToken),
        ];

        // Persist the sign-in under the user row lock, so it is ordered against a claim of the account: one that committed
        // since the request read the account fails it, and one that comes later revokes what it wrote
        $conflict = null;
        $linked = null;
        $replaced = null;
        $created = null;
        $target = null;
        try {
            $created = $dbForProject->withTransaction(function () use ($dbForProject, $failureRedirect, $snapshot, $grounds, $user, $provider, $oauth2ID, $providerEmail, $credentials, $changes, $state, $current, $backfillEmail, &$token, &$session, &$conflict, &$linked, &$replaced, &$target) {
                $conflict = null; // A retried attempt starts over
                $linked = null;
                $replaced = null;
                $target = null;

                if (!User::lockUnchanged($dbForProject, $snapshot, $grounds)) {
                    $failureRedirect(Exception::GENERAL_BAD_REQUEST);
                }

                $linked = $this->writeIdentity($user, $provider, $oauth2ID, $providerEmail, $credentials, $credentials, $dbForProject, $conflict);

                if ($conflict !== null) {
                    return;
                }

                if ($backfillEmail !== null) {
                    $target = User::reserveEmailTarget($dbForProject, $user, $backfillEmail);
                }

                $dbForProject->updateDocument('users', $user->getId(), new Document($changes));

                if ($state['token']) {
                    return $dbForProject->createDocument('tokens', $token);
                }

                // The session flow replaces the caller's current session with the one
                // created here, so the browser stays signed in. It is deleted under the
                // lock, so of two callbacks riding the same session only the first gets
                // past lockUnchanged(). The token flow creates no session here; the app
                // exchanges the returned token through createSession later. Deleting the
                // current session in that flow would sign the caller out until the
                // exchange, so it is left in place.
                if ($current) {
                    $previous = $dbForProject->getDocument('sessions', $current);
                    if (!$previous->isEmpty() && $dbForProject->deleteDocument('sessions', $current)) {
                        $replaced = $previous;
                    }
                }

                return $dbForProject->createDocument('sessions', $session);
            });
        } catch (Duplicate) {
            // The (provider, providerUid) unique index guards the same identity being connected to two users, and
            // the identifier index the same address being a target of two
            $conflict = Exception::USER_ALREADY_EXISTS;
            $replaced = null; // Rolled back with the rest
            $target = null;
        }

        // The link's email check could not see another user's link that had not committed, so it is made again now
        if ($conflict === null && $linked !== null && !User::confirmIdentity($dbForProject, $linked)) {
            $restore = $state['token'] ? null : $replaced;
            $collection = $state['token'] ? 'tokens' : 'sessions';
            // Undo under the user row lock, so it is ordered against a claim: a claim that got here first deleted
            // the replacement along with every other session, and the caller's old one must not come back after it
            $authorization->skip(fn () => $dbForProject->withTransaction(function () use ($dbForProject, $snapshot, $changes, $target, $collection, $created, $restore) {
                // Locks the user, and puts back what the sign-in changed on the account and the address it reserved
                User::revertSignIn($dbForProject, $snapshot, $changes, $target);

                if (!$dbForProject->deleteDocument($collection, $created->getId())) {
                    return;
                }

                if ($restore !== null) {
                    // Keep the caller signed in to the session the refused link would have replaced
                    $dbForProject->createDocument('sessions', new Document(\array_diff_key($restore->getArrayCopy(), ['$sequence' => true, '$collection' => true])));
                }
            }));
            $replaced = null;
            $conflict = Exception::GENERAL_BAD_REQUEST;
        }

        if ($conflict !== null) {
            // A request whose link did not hold must not leave behind the user it just created
            $this->dropNewUser($dbForProject, $authorization, $newUser, $newTarget);
            $failureRedirect($conflict);
        }

        if ($replaced !== null) {
            $dbForProject->purgeCachedDocument('users', $user->getId());

            try {
                $mqtt->revoke($project->getId(), $user->getId());
            } catch (\Throwable $error) {
                Console::error('Failed to revoke MQTT connections: ' . $error->getMessage());
            }
        }

        if (!$wasVerified && $user->getAttribute('emailVerification', false)) {
            $bus->dispatch(new EmailVerified(user: $user->getArrayCopy(), project: $project->getArrayCopy()));
        }

        if ($state['token']) {
            $token = $created;

            $queueForEvents
                ->setEvent('users.[userId].tokens.[tokenId].create')
                ->setParam('userId', $user->getId())
                ->setParam('tokenId', $token->getId())
            ;

            // Wrap secret in a JWT that also carries the provider name
            $jwtEncoder = new JWT(EncryptionKey::current(), 'HS256', 60, 0);
            $query['secret'] = $jwtEncoder->encode([
                'secret' => $secret,
                'provider' => $provider,
            ]);
            $query['userId'] = $user->getId();
        } else {
            $session = $created;

            $session
                ->setAttribute('expire', $expire)
                ->setAttribute('userName', $user->getAttribute('name', ''));

            $encoded = $store
                ->setProperty('id', $user->getId())
                ->setProperty('secret', $secret)
                ->encode();

            if (!$domainVerification) {
                $response->addHeader('X-Fallback-Cookies', \json_encode([$store->getKey() => $encoded]));
            }

            $queueForEvents
                ->setParam('userId', $user->getId())
                ->setParam('sessionId', $session->getId())
                ->setPayload($response->output($session, Response::MODEL_SESSION), sensitive: ['secret', 'providerAccessToken', 'providerRefreshToken'])
            ;

            // TODO: Remove this deprecated workaround - support only token
            if ($state['success']['host'] === $consoleHostname && $state['success']['path'] === self::OAUTH_DEFAULT_SUCCESS) {
                $query['project'] = $project->getId();
                $query['domain'] = $cookieDomain;
                $query['key'] = $store->getKey();
                $query['secret'] = $encoded;
                $state['success'] = $nativeCallback;
            }

            $response
                ->addCookie($store->getKey() . '_legacy', $encoded, (new \DateTime($expire))->getTimestamp(), '/', $cookieDomain, ('https' == $protocol), true, null)
                ->addCookie($store->getKey(), $encoded, (new \DateTime($expire))->getTimestamp(), '/', $cookieDomain, ('https' == $protocol), true, Config::getParam('cookieSamesite'));

            // The session joins the account switching device and becomes its active one, before the
            // redirect goes out so the device reads consistently on the client's next request
            if ($authDevice !== null) {
                $authDevice = AuthDevice::attach($dbForProject, $authorization, $proofForToken, $authDevice, $session, $publisherForDeletes, $project, $mqtt);
                AuthDevice::refreshCookie($request, $response, $project, $authDevice, $protocol, $domainVerification, $cookieDomain);
            }
        }

        if (isset($sessionUpgrade) && isset($session)) {
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

        if (\is_string($state['state'] ?? null) && $state['state'] !== '') {
            $query['state'] = $state['state'];
        }

        $state['success']['query'] = URLParser::unparseQuery($query);
        $state['success'] = URLParser::unparse($state['success']);

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($state['success'])
        ;
    }
}
