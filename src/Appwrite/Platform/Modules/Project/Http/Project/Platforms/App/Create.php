<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Platforms\App;

use Appwrite\Event\Event as QueueEvent;
use Appwrite\Extend\Exception;
use Appwrite\Network\Platform;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Web\Create as CreateWebPlatform;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Hostname;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createProjectAppPlatform';
    }

    /**
     * @return array<string>
     */
    public static function getSupportedTypes(): array
    {
        return [
            Platform::TYPE_FLUTTER_IOS,
            Platform::TYPE_FLUTTER_ANDROID,
            Platform::TYPE_FLUTTER_LINUX,
            Platform::TYPE_FLUTTER_MACOS,
            Platform::TYPE_FLUTTER_WINDOWS,
            Platform::TYPE_APPLE_IOS,
            Platform::TYPE_APPLE_MACOS,
            Platform::TYPE_APPLE_WATCHOS,
            Platform::TYPE_APPLE_TVOS,
            Platform::TYPE_ANDROID,
            Platform::TYPE_UNITY,
            Platform::TYPE_REACT_NATIVE_IOS,
            Platform::TYPE_REACT_NATIVE_ANDROID,
        ];
    }

    /**
     * @return array<string>
     */
    public static function getAllSupportedTypes(): array
    {
        return [
            ...self::getSupportedTypes(),
            ...CreateWebPlatform::getSupportedTypes(),
        ];
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/project/platforms/app')
            ->httpAlias('/v1/projects/:projectId/platforms')
            ->desc('Create project app platform')
            ->groups(['api', 'project'])
            ->label('scope', 'project.write')
            ->label('event', 'platforms.[platformId].create')
            ->label('audits.event', 'project.platform.create')
            ->label('audits.resource', 'project.platform/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'platforms',
                name: 'createAppPlatform',
                description: <<<EOT
                Create a new app platform for your project. Use this endpoint to register a new platform where your users will run your application which will interact with the Appwrite API.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_PLATFORM_APP,
                    )
                ],
            ))
            ->param('platformId', '', fn (Database $dbForPlatform) => new CustomId(false, $dbForPlatform->getAdapter()->getMaxUIDLength()), 'Platform ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForPlatform'])
            ->param('name', null, new Text(128), 'Platform name. Max length: 128 chars.')
            ->param(
                'type',
                null,
                new WhiteList($this->getAllSupportedTypes(), true),  // We only support all here for backwards compatibility
                'Platform type. Possible values are: ' . implode(', ', $this->getSupportedTypes())
            )
            ->param('identifier', '', new Text(256), 'Package name for Android or bundle ID for iOS or macOS. Max length: 256 chars.', true) // We only mark optional=true for backwards compatibility
            ->inject('request')
            ->inject('response')
            ->inject('queueForEvents')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $platformId,
        string $name,
        string $type,
        ?string $identifier, // Only nullable for backwards compatibility
        Request $request,
        Response $response,
        QueueEvent $queueForEvents,
        Document $project,
        Database $dbForPlatform,
        Authorization $authorization,
    ) {
        $hostname = null;

        // Backwards compatibility
        $isDeprecatedRequest = false;
        if (!\in_array($type, self::getSupportedTypes())) {
            $isDeprecatedRequest = true;
            $hostname = $request->getParam('hostname', '');
            $hostnameValidator = new Hostname();
            if (!$hostnameValidator->isValid($hostname)) {
                throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Param "hostname" is invalid: ' . $hostnameValidator->getDescription());
            }
        } else {
            if (empty($identifier)) {
                throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Param "identifier" is not optional.');
            }
        }

        $platformId = ($platformId == 'unique()') ? ID::unique() : $platformId;

        $platform = new Document([
            '$id' => $platformId,
            '$permissions' => [],
            'projectInternalId' => $project->getSequence(),
            'projectId' => $project->getId(),
            'type' => $type,
            'name' => $name,
            'key' => $identifier,
            'hostname' => $hostname, // Web platform attribute; We fill only during backwards compatibility, otherwise null
            'store' => null, // Unused attribute
        ]);

        try {
            $platform = $authorization->skip(fn () => $dbForPlatform->createDocument('platforms', $platform));
        } catch (DuplicateException) {
            throw new Exception(Exception::PLATFORM_ALREADY_EXISTS);
        }

        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $queueForEvents->setParam('platformId', $platform->getId());

        if (!$isDeprecatedRequest) {
            $platform->setAttribute('hostname', '');
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($platform, Response::MODEL_PLATFORM_APP);
    }
}
