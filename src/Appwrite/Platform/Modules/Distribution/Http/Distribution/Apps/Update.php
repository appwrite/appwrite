<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps;

use Appwrite\Distribution\Framework;
use Appwrite\Distribution\Platform;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateApp';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/distribution/apps/:appId')
            ->desc('Update app')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.write')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('event', 'distributionApps.[appId].update')
            ->label('audits.event', 'distribution.app.update')
            ->label('audits.resource', 'distributionApp/{request.appId}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'apps',
                name: 'updateApp',
                description: <<<EOT
                Update an app by its unique ID. Only the parameters you pass are changed. The framework can only change to one that supports every platform the app already has a target for.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_DISTRIBUTION_APP,
                    )
                ],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('appId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'App ID.', false, ['dbForProject'])
            ->param('name', null, new Nullable(new Text(128)), 'App name. Max length: 128 chars.', true)
            ->param('framework', null, new Nullable(new WhiteList(Framework::values(), true)), 'Framework the app is built with.', true, enum: new Enum(name: 'DistributionFramework'))
            ->param('enabled', null, new Nullable(new Boolean()), 'Whether the app is enabled. A disabled app ignores pushes and rejects new builds.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $appId,
        ?string $name,
        ?string $framework,
        ?bool $enabled,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents
    ): void {
        $app = $dbForProject->getDocument('distributionApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_APP_NOT_FOUND);
        }

        if (\is_null($name) && \is_null($framework) && \is_null($enabled)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'At least one of name, framework or enabled is required.');
        }

        $updates = new Document();

        if (!\is_null($framework) && $framework !== $app->getAttribute('framework')) {
            $this->assertTargetsSupported($dbForProject, $app, $framework);
            $updates->setAttribute('framework', $framework);
        }

        if (!\is_null($name)) {
            $updates->setAttribute('name', $name);
        }

        if (!\is_null($enabled)) {
            $updates->setAttribute('enabled', $enabled);
        }

        if (!\is_null($name) || $updates->isSet('framework')) {
            $updates->setAttribute('search', \implode(' ', [
                $app->getId(),
                $name ?? $app->getAttribute('name'),
                $framework ?? $app->getAttribute('framework'),
            ]));
        }

        $app = $dbForProject->updateDocument('distributionApps', $app->getId(), $updates);

        $queueForEvents->setParam('appId', $app->getId());

        $response->dynamic($app, Response::MODEL_DISTRIBUTION_APP);
    }

    private function assertTargetsSupported(Database $dbForProject, Document $app, string $framework): void
    {
        $platforms = Config::getParam('distribution')['frameworks'][$framework];

        $targets = $dbForProject->find('distributionTargets', [
            Query::equal('appInternalId', [$app->getSequence()]),
            Query::limit(\count(Platform::cases())),
        ]);

        foreach ($targets as $target) {
            $platform = $target->getAttribute('platform');

            if (!\array_key_exists($platform, $platforms)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "Framework '{$framework}' does not support the app's '{$platform}' target. Delete the target first.");
            }
        }
    }
}
