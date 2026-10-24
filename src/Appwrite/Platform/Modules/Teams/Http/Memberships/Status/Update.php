<?php

namespace Appwrite\Platform\Modules\Teams\Http\Memberships\Status;

use Appwrite\Auth\MFA\Type;
use Appwrite\Detector\Detector;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use MaxMind\Db\Reader;
use Utopia\Auth\Proofs\Token;
use Utopia\Auth\Store;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateTeamMembershipStatus';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/teams/:teamId/memberships/:membershipId/status')
            ->desc('Update team membership status')
            ->groups(['api', 'teams'])
            ->label('event', 'teams.[teamId].memberships.[membershipId].update.status')
            ->label('scope', 'public')
            ->label('audits.event', 'membership.update')
            ->label('audits.resource', 'team/{request.teamId}')
            ->label('audits.userId', '{request.userId}')
            ->label('sdk', new Method(
                namespace: 'teams',
                group: 'memberships',
                name: 'updateMembershipStatus',
                description: '/docs/references/teams/update-team-membership-status.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_MEMBERSHIP,
                    )
                ]
            ))
            ->param('teamId', '', new UID(), 'Team ID.')
            ->param('membershipId', '', new UID(), 'Membership ID.')
            ->param('userId', '', new UID(), 'User ID.')
            ->param('secret', '', new Text(256), 'Secret key.')
            ->inject('request')
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('authorization')
            ->inject('project')
            ->inject('geodb')
            ->inject('queueForEvents')
            ->inject('store')
            ->inject('proofForToken')
            ->inject('domainVerification')
            ->inject('cookieDomain')
            ->callback($this->action(...));
    }

    public function action(string $teamId, string $membershipId, string $userId, string $secret, Request $request, Response $response, Document $user, Database $dbForProject, Authorization $authorization, $project, Reader $geodb, Event $queueForEvents, Store $store, Token $proofForToken, bool $domainVerification, ?string $cookieDomain)
    {
        $protocol = $request->getProtocol();

        $membership = $dbForProject->getDocument('memberships', $membershipId);

        if ($membership->isEmpty()) {
            throw new Exception(Exception::MEMBERSHIP_NOT_FOUND);
        }

        $team = $authorization->skip(fn () => $dbForProject->getDocument('teams', $teamId));

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        if ($membership->getAttribute('teamInternalId') !== $team->getSequence()) {
            throw new Exception(Exception::TEAM_MEMBERSHIP_MISMATCH);
        }

        if (!$proofForToken->verify($secret, $membership->getAttribute('secret'))) {
            throw new Exception(Exception::TEAM_INVALID_SECRET);
        }

        if ($userId !== $membership->getAttribute('userId')) {
            throw new Exception(Exception::TEAM_INVITE_MISMATCH, 'Invite does not belong to current user (' . $user->getAttribute('email') . ')');
        }

        $hasSession = !$user->isEmpty();
        if (!$hasSession) {
            $user->setAttributes($dbForProject->getDocument('users', $userId)->getArrayCopy()); // Get user
        }

        if ($membership->getAttribute('userInternalId') !== $user->getSequence()) {
            throw new Exception(Exception::TEAM_INVITE_MISMATCH, 'Invite does not belong to current user (' . $user->getAttribute('email') . ')');
        }

        if ($membership->getAttribute('confirm') === true) {
            throw new Exception(Exception::MEMBERSHIP_ALREADY_CONFIRMED);
        }

        $membership // Attach user to team
            ->setAttribute('joined', DateTime::now())
            ->setAttribute('confirm', true)
        ;

        $authorization->addRole(Role::user($user->getId())->toString());

        // Built up front and stored with the confirmation, so a failure cannot consume the invite without a session
        $session = null;
        $sessionSecret = '';
        $expire = null;
        if (!$hasSession) {
            $detector = new Detector($request->getUserAgent('UNKNOWN'));
            $record = $geodb->get($request->getIP());
            $authDuration = $project->getAttribute('auths', [])['duration'] ?? TOKEN_EXPIRATION_LOGIN_LONG;
            $expire = DateTime::addSeconds(new \DateTime(), $authDuration);
            $sessionSecret = $proofForToken->generate();
            $session = new Document(array_merge([
                '$id' => ID::unique(),
                '$permissions' => [
                    Permission::read(Role::user($user->getId())),
                    Permission::update(Role::user($user->getId())),
                    Permission::delete(Role::user($user->getId())),
                ],
                'userId' => $user->getId(),
                'userInternalId' => $user->getSequence(),
                'provider' => SESSION_PROVIDER_EMAIL,
                'providerUid' => $user->getAttribute('email'),
                'secret' => $proofForToken->hash($sessionSecret), // One way hash encryption to protect DB leak
                'userAgent' => $request->getUserAgent('UNKNOWN'),
                'ip' => $request->getIP(),
                'countryCode' => ($record) ? \strtolower($record['country']['iso_code']) : '--',
                'expire' => DateTime::addSeconds(new \DateTime(), $authDuration)
            ], $detector->getOS(), $detector->getClient(), $detector->getDevice()));
        }

        $targetUser = $user;
        $membership = $dbForProject->withTransaction(function () use ($dbForProject, $authorization, $membership, $team, $targetUser, $secret, $proofForToken, &$session) {
            // Lock the user first, as an address change does, so a voided invite cannot slip through
            $locked = $authorization->skip(fn () => $dbForProject->getDocument('users', $targetUser->getId(), forUpdate: true));

            // Re-read under a lock, a concurrent accept of the same invite must not be counted twice
            $current = $authorization->skip(fn () => $dbForProject->getDocument('memberships', $membership->getId(), forUpdate: true));

            if ($current->getAttribute('confirm') === true) {
                return new Document();
            }

            if (!$proofForToken->verify($secret, (string) $current->getAttribute('secret'))) {
                throw new Exception(Exception::TEAM_INVALID_SECRET);
            }

            $confirmed = $dbForProject->updateDocument('memberships', $membership->getId(), new Document(['joined' => $membership->getAttribute('joined'), 'confirm' => true]));

            $authorization->skip(fn () => $dbForProject->increaseDocumentAttribute('teams', $team->getId(), 'total', 1));

            // The invite proves the address it was sent to, and only one address on file tells which that was
            $hasEmail = !empty($locked->getAttribute('email'));
            $hasPhone = !empty($locked->getAttribute('phone'));
            $verification = match (true) {
                $hasEmail && !$hasPhone => 'emailVerification',
                $hasPhone && !$hasEmail => 'phoneVerification',
                default => null,
            };

            if ($verification !== null) {
                $targetUser->setAttribute($verification, true);
                $authorization->skip(fn () => $dbForProject->updateDocument('users', $targetUser->getId(), new Document([$verification => true])));
            }

            // The session counts only the channel the invite proved; when that is unknown the user signs in instead
            if ($verification === null) {
                $session = null;
            }

            if ($session !== null) {
                $phoneProof = $verification === 'phoneVerification';
                $session
                    ->setAttribute('provider', $phoneProof ? SESSION_PROVIDER_PHONE : SESSION_PROVIDER_EMAIL)
                    ->setAttribute('providerUid', $phoneProof ? $locked->getAttribute('phone') : $locked->getAttribute('email'))
                    ->setAttribute('factors', [$phoneProof ? Type::PHONE : Type::EMAIL]);
                $session = $dbForProject->createDocument('sessions', $session);
            }

            return $confirmed;
        });

        if ($membership->isEmpty()) {
            throw new Exception(Exception::MEMBERSHIP_ALREADY_CONFIRMED);
        }

        // Hand the new session to a user who was not logged in
        if ($session !== null) {
            $authorization->addRole(Role::user($userId)->toString());

            $encoded = $store
                ->setProperty('id', $user->getId())
                ->setProperty('secret', $sessionSecret)
                ->encode();

            if (!$domainVerification) {
                $response->addHeader('X-Fallback-Cookies', \json_encode([$store->getKey() => $encoded]));
            }

            $response
                ->addCookie(
                    name: $store->getKey() . '_legacy',
                    value: $encoded,
                    expire: (new \DateTime($expire))->getTimestamp(),
                    path: '/',
                    domain: $cookieDomain,
                    secure: ('https' === $protocol),
                    httponly: true
                )
                ->addCookie(
                    name: $store->getKey(),
                    value: $encoded,
                    expire: (new \DateTime($expire))->getTimestamp(),
                    path: '/',
                    domain: $cookieDomain,
                    secure: ('https' === $protocol),
                    httponly: true,
                    sameSite: Config::getParam('cookieSamesite')
                )
            ;
        }

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('teamId', $team->getId())
            ->setParam('membershipId', $membership->getId())
        ;

        $response->dynamic(
            $membership
                ->setAttribute('teamName', $team->getAttribute('name'))
                ->setAttribute('userName', $user->getAttribute('name'))
                ->setAttribute('userEmail', $user->getAttribute('email')),
            Response::MODEL_MEMBERSHIP
        );
    }
}
