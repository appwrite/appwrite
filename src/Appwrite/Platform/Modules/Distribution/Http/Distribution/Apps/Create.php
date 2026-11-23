<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps;

use Appwrite\Distribution\Framework;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Id;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createApp';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/distribution/apps')
            ->desc('Create app')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.write')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('event', 'distributionApps.[appId].create')
            ->label('audits.event', 'distribution.app.create')
            ->label('audits.resource', 'distributionApp/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'apps',
                name: 'createApp',
                description: <<<EOT
                Create a new app. An app is the mobile project you build, sign and submit to the app stores. Add a target for each platform the app ships to.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_DISTRIBUTION_APP,
                    )
                ],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('appId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getMaxUidLength()), 'App ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForProject'])
            ->param('name', '', new Text(128), 'App name. Max length: 128 chars.')
            ->param('framework', '', new WhiteList(Framework::values(), true), 'Framework the app is built with.', enum: new Enum(name: 'DistributionFramework'))
            ->param('enabled', true, new Boolean(), 'Whether the app is enabled. A disabled app ignores pushes and rejects new builds.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $appId,
        string $name,
        string $framework,
        bool $enabled,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents
    ): void {
        $appId = $appId === 'unique()' ? Id::unique() : $appId;

        try {
            $app = $dbForProject->createDocument('distributionApps', new Document([
                '$id' => $appId,
                'name' => $name,
                'framework' => $framework,
                'enabled' => $enabled,
                'search' => \implode(' ', [$appId, $name, $framework]),
            ]));
        } catch (Duplicate) {
            throw new Exception(Exception::DISTRIBUTION_APP_ALREADY_EXISTS);
        }

        $queueForEvents->setParam('appId', $app->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($app, Response::MODEL_DISTRIBUTION_APP);
    }
}
