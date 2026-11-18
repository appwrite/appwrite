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
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updatePushTarget';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/account/targets/:targetId/push')
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
            ->callback($this->action(...));
    }

    public function action(
        string $targetId,
        string $identifier,
        string $bundleId,
        string $environment,
        Event $queueForEvents,
        Document $user,
        Request $request,
        Response $response,
        Database $dbForProject,
        Authorization $authorization,
    ): void {
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
    }
}
