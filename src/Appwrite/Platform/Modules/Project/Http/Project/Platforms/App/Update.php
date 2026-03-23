<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Platforms\App;

use Appwrite\Event\Event as QueueEvent;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\App\Create as AppPlatformCreate;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Update extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectAppPlatform';
    }

    public function __construct()
    {
        $this->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/project/platforms/app/:platformId')
            ->desc('Update project app platform')
            ->groups(['api', 'project'])
            ->label('scope', 'project.write')
            ->label('event', 'platforms.[platformId].update')
            ->label('audits.event', 'project.platform.update')
            ->label('audits.resource', 'project.platform/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'platforms',
                name: 'updateAppPlatform',
                description: <<<EOT
                Update an app platform by its unique ID. Use this endpoint to update the platform's name or identifier.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PLATFORM_APP,
                    )
                ]
            ))
            ->param('platformId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Platform ID.', false, ['dbForPlatform'])
            ->param('name', null, new Text(128), 'Platform name. Max length: 128 chars.')
            ->param('identifier', '', new Text(256), 'Package name for Android or bundle ID for iOS or macOS. Max length: 256 chars.')
            ->inject('response')
            ->inject('queueForEvents')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        string $platformId,
        string $name,
        string $identifier,
        Response $response,
        QueueEvent $queueForEvents,
        Database $dbForPlatform,
        Authorization $authorization,
        Document $project,
    ) {
        $platform = $authorization->skip(fn () => $dbForPlatform->getDocument('platforms', $platformId));

        if ($platform->isEmpty() || $platform->getAttribute('projectInternalId', '') !== $project->getSequence()) {
            throw new Exception(Exception::PLATFORM_NOT_FOUND);
        }

        $appPlatforms = AppPlatformCreate::getSupportedTypes();
        if (!\in_array($platform->getAttribute('type', ''), $appPlatforms)) {
            throw new Exception(Exception::PLATFORM_METHOD_UNSUPPORTED);
        }

        $updates = new Document([
            'name' => $name,
            'key' => $identifier,
        ]);

        try {
            $platform = $authorization->skip(fn () => $dbForPlatform->updateDocument('platforms', $platform->getId(), $updates));
        } catch (Duplicate) {
            throw new Exception(Exception::PLATFORM_ALREADY_EXISTS);
        }

        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $queueForEvents->setParam('platformId', $platform->getId());

        $response->dynamic($platform, Response::MODEL_PLATFORM_APP);
    }
}
