<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Platforms\Web;

use Appwrite\Event\Event as QueueEvent;
use Appwrite\Extend\Exception;
use Appwrite\Network\Platform;
use Appwrite\Platform\Modules\Compute\Base;
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

/**
 * WARNING: This kind of platform has most complex action, because it holds backwards compatibility too.
 * If possible, refer to any other type of platform for APIs, for more simpler endpoint.
 */
class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createProjectWebPlatform';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/project/platforms/web')
            ->httpAlias('/v1/projects/:projectId/platforms')
            ->desc('Create project web platform')
            ->groups(['api', 'project'])
            ->label('scope', 'project.write')
            ->label('event', 'platforms.[platformId].create')
            ->label('audits.event', 'project.platform.create')
            ->label('audits.resource', 'project.platform/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'platforms',
                name: 'createWebPlatform',
                description: <<<EOT
                Create a new web platform for your project. Use this endpoint to register a new platform where your users will run your application which will interact with the Appwrite API.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_PLATFORM_WEB,
                    )
                ],
            ))
            ->param('platformId', '', fn (Database $dbForPlatform) => new CustomId(false, $dbForPlatform->getAdapter()->getMaxUIDLength()), 'Platform ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForPlatform'])
            ->param('name', null, new Text(128), 'Platform name. Max length: 128 chars.')
            ->param('hostname', '', new Hostname(), 'Platform web hostname. Max length: 256 chars.', true) // Optional for backwards compatibility
            ->param('key', '', new Text(256), 'Package name for Android or bundle ID for iOS or macOS. Max length: 256 chars.', true) // Exists for backwards compatibility
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
        string $hostname,
        Request $request,
        Response $response,
        QueueEvent $queueForEvents,
        Document $project,
        Database $dbForPlatform,
        Authorization $authorization,
    ) {
        $type = Platform::TYPE_WEB;
        $key = ''; // App platform attribute
        
        // Backwards compatibility
        // Used to have: type, name, key, hostname
        if(!empty($request->getParam('type', ''))) {
            // Validate deprecated type, and rename to new type
            $deprecatedtypeMapping = [
                // Web
                'web' => Platform::TYPE_WEB,
                'flutter-web' => Platform::TYPE_WEB,
                'unity' => Platform::TYPE_WEB, // Was not officially supported anyway
                
                // Apple
                'flutter-macos' => Platform::TYPE_APPLE,
                'flutter-ios' => Platform::TYPE_APPLE,
                'react-native-ios' => Platform::TYPE_APPLE,
                'apple-ios' => Platform::TYPE_APPLE,
                'apple-macos' => Platform::TYPE_APPLE,
                'apple-watchos' => Platform::TYPE_APPLE,
                'apple-tvos' => Platform::TYPE_APPLE,
                
                // Android
                'flutter-android' => Platform::TYPE_ANDROID,
                'android' => Platform::TYPE_ANDROID,
                'react-native-android' => Platform::TYPE_ANDROID,
                
                'flutter-windows' => Platform::TYPE_WINDOWS,
            ];
               
            $typeValidator = new WhiteList(\array_keys($deprecatedtypeMapping));
            if(!$typeValidator->isValid($request->getParam('type', ''))) {
                throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Param "type" is invalid: ' . $typeValidator->getDescription());
            }
            
            $type = $deprecatedtypeMapping[$request->getParam('type', '')] ?? Platform::TYPE_WEB;
            
            // Validate deprecated app id (key)
            if (!empty($request->getParam('key', ''))) {
                $keyValidator = new Text(256);
                if(!$keyValidator->isValid($request->getParam('key', ''))) {
                    throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Param "key" is invalid: ' . $keyValidator->getDescription());
                }
                $key = $request->getParam('key', '');
            }
        } else {
            // Modern request, validate hostname
            if (empty($hostname)) {
                throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Param "hostname" is not optional.');
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
            'key' => $key,
            'hostname' => $hostname
        ]);

        try {
            $platform = $authorization->skip(fn () => $dbForPlatform->createDocument('platforms', $platform));
        } catch (DuplicateException) {
            throw new Exception(Exception::PLATFORM_ALREADY_EXISTS);
        }

        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $queueForEvents->setParam('platformId', $platform->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($platform, Response::MODEL_PLATFORM_WEB);
    }
}
