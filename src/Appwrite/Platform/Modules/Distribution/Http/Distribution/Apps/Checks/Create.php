<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Checks;

use Appwrite\Abuse\Limit;
use Appwrite\Distribution\Checks;
use Appwrite\Distribution\Platform;
use Appwrite\Distribution\Stores;
use Appwrite\Distribution\Submissions;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Distribution\Exception\Conflict;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Exception\RateLimited;
use Utopia\Distribution\Exception\Unavailable;
use Utopia\Lock\Exception\Contention;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;

    private const int LOCK_TTL = 120;

    private const float LOCK_TIMEOUT = 2.0;

    public static function getName(): string
    {
        return 'createCheck';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/distribution/apps/:appId/checks')
            ->desc('Create check')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.read')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('abuse', [Limit::attempts(['ip', 'method', 'url', 'userId'], APP_LIMIT_WRITE_RATE_DEFAULT, seconds: APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)])
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'checks',
                name: 'createCheck',
                description: <<<EOT
                Run preflight checks for a target and a store track: the store connection, the app in the store and the signing identity. The check runs synchronously and is not saved. `ready` is false while any issue has the `error` severity.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_DISTRIBUTION_CHECK,
                    )
                ],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('appId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'App ID.', false, ['dbForProject'])
            ->param('targetId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Target ID.', false, ['dbForProject'])
            ->param('track', '', new Text(64, 0), 'Store track to check for. Leave empty to use the default track of the target, or the internal testing track of the store when the target has none.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('distributionStores')
            ->inject('locks')
            ->callback($this->action(...));
    }

    public function action(
        string $appId,
        string $targetId,
        string $track,
        Response $response,
        Database $dbForProject,
        Stores $distributionStores,
        callable $locks,
    ): void {
        $app = $dbForProject->getDocument('distributionApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_APP_NOT_FOUND);
        }

        $target = $dbForProject->getDocument('distributionTargets', $targetId);

        if ($target->isEmpty() || $target->getAttribute('appInternalId') !== $app->getSequence()) {
            throw new Exception(Exception::DISTRIBUTION_TARGET_NOT_FOUND);
        }

        $tracks = $distributionStores->tracks(Stores::provider(Platform::from((string) $target->getAttribute('platform', ''))));
        $track = $track ?: ((string) $target->getAttribute('track', '') ?: ($tracks[0] ?? ''));
        $connectionId = (string) $target->getAttribute('connectionId', '');
        $connection = $connectionId === '' ? new Document() : $dbForProject->getDocument('distributionConnections', $connectionId);

        try {
            $check = $this->check(new Checks($dbForProject, $distributionStores), $locks, $app, $target, $track, $connection);
        } catch (RateLimited|Unavailable $unavailable) {
            throw new Exception(Exception::DISTRIBUTION_STORE_UNAVAILABLE, $unavailable->getMessage(), previous: $unavailable);
        } catch (Invalid|Conflict $rejected) {
            throw new Exception(Exception::DISTRIBUTION_CONNECTION_INVALID, $rejected->getMessage(), previous: $rejected);
        }

        $response->dynamic(new Document($check), Response::MODEL_DISTRIBUTION_CHECK);
    }

    /**
     * @return array<string, mixed>
     */
    private function check(Checks $checks, callable $locks, Document $app, Document $target, string $track, Document $connection): array
    {
        if ($connection->isEmpty()) {
            return $checks->run($app, $target, $track);
        }

        try {
            return $locks(
                Submissions::storeLock($connection, (string) $target->getAttribute('identifier', '')),
                self::LOCK_TTL,
                fn (): array => $checks->run($app, $target, $track),
                self::LOCK_TIMEOUT,
            );
        } catch (Contention) {
            return $checks->run($app, $target, $track, store: false);
        }
    }
}
