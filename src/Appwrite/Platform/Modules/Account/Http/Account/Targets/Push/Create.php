<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Targets\Push;

use Appwrite\Detector\Detector;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createPushTarget';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/targets/push')
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
            ->callback($this->action(...));
    }

    public function action(
        string $targetId,
        string $identifier,
        string $providerId,
        string $bundleId,
        string $environment,
        Event $queueForEvents,
        User $user,
        Request $request,
        Response $response,
        Database $dbForProject,
        Authorization $authorization,
        ?Document $current,
    ): void {
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
    }
}
