<?php

namespace Appwrite\Platform\Modules\Users\Http\Users\Sessions;

use Appwrite\Detector\Detector;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Geo\Geo;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Auth\Proofs\Token;
use Utopia\Auth\Store;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Locale\Locale;
use Utopia\Platform\Scope\HTTP;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createUserSession';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/users/:userId/sessions')
            ->desc('Create session')
            ->groups(['api', 'users'])
            ->label('event', 'users.[userId].sessions.[sessionId].create')
            ->label('scope', ['users.write', 'sessions.write'])
            ->label('audits.event', 'session.create')
            ->label('audits.resource', 'user/{request.userId}')
            ->label('usage.metric', 'sessions.{scope}.requests.create')
            ->label('sdk', new Method(
                namespace: 'users',
                group: 'sessions',
                name: 'createSession',
                description: '/docs/references/users/create-session.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_SESSION,
                    )
                ]
            ))
            ->param('userId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getAdapter()->getMaxUIDLength()), 'User ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForProject'])
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('locale')
            ->inject('geo')
            ->inject('queueForEvents')
            ->inject('store')
            ->inject('proofForToken')
            ->callback($this->action(...));
    }

    public function action(string $userId, Request $request, Response $response, Database $dbForProject, Document $project, Locale $locale, Geo $geo, Event $queueForEvents, Store $store, Token $proofForToken): void
    {
        $user = $dbForProject->getDocument('users', $userId);
        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $secret = $proofForToken->generate();
        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $duration = $project->getAttribute('auths', [])['duration'] ?? TOKEN_EXPIRATION_LOGIN_LONG;
        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), $duration));

        $session = new Document(array_merge(
            [
                '$id' => ID::unique(),
                'userId' => $user->getId(),
                'userInternalId' => $user->getSequence(),
                'provider' => SESSION_PROVIDER_SERVER,
                'secret' => $proofForToken->hash($secret), // One way hash encryption to protect DB leak
                'userAgent' => $request->getUserAgent('UNKNOWN'),
                'factors' => ['server'],
                'ip' => $request->getIP(),
                'countryCode' => \strtolower($geo->get($request->getIP())->getCountryCode()),
                'expire' => $expire,
            ],
            $detector->getOS(),
            $detector->getClient(),
            $detector->getDevice()
        ));

        $session->setAttribute('$permissions', [
            Permission::read(Role::user($user->getId())),
            Permission::update(Role::user($user->getId())),
            Permission::delete(Role::user($user->getId())),
        ]);

        $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

        $session = $dbForProject->createDocument('sessions', $session);

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $encoded = $store
            ->setProperty('id', $user->getId())
            ->setProperty('secret', $secret)
            ->encode();

        $session
            ->setAttribute('secret', $encoded)
            ->setAttribute('countryName', $countryName);

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('sessionId', $session->getId())
            ->setPayload($response->output($session, Response::MODEL_SESSION));

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($session, Response::MODEL_SESSION);
    }
}
