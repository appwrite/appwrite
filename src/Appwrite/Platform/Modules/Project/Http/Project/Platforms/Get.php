<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Platforms;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\App\Create as AppPlatformCreate;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Web\Create as WebPlatformCreate;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'getProjectPlatform';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/project/platforms/:platformId')
            ->desc('Get project platform')
            ->groups(['api', 'project'])
            ->label('scope', 'project.read')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'platforms',
                name: 'getPlatform',
                description: <<<EOT
                Get a platform by its unique ID. This endpoint returns the platform's details, including its name, type, and key configurations.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: [
                            Response::MODEL_PLATFORM_WEB,
                            Response::MODEL_PLATFORM_APP
                        ],
                    )
                ]
            ))
            ->param('platformId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Platform ID.', false, ['dbForPlatform'])
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        string $platformId,
        Response $response,
        Database $dbForPlatform,
        Authorization $authorization,
        Document $project
    ) {
        $platform = $authorization->skip(fn () => $dbForPlatform->getDocument('platforms', $platformId));

        if ($platform->isEmpty() || $platform->getAttribute('projectInternalId', '') !== $project->getSequence()) {
            throw new Exception(Exception::PLATFORM_NOT_FOUND);
        }

        $webPlatforms = WebPlatformCreate::getSupportedTypes();
        $appPlatforms = AppPlatformCreate::getSupportedTypes();

        if (\in_array($platform->getAttribute('type'), $webPlatforms)) {
            $model = Response::MODEL_PLATFORM_WEB;
        } elseif (\in_array($platform->getAttribute('type'), $appPlatforms)) {
            $model = Response::MODEL_PLATFORM_APP;
        } else {
            throw new Exception(Exception::GENERAL_UNKNOWN, 'Platform type ' . $platform->getAttribute('type') . ' is not supported');
        }

        $response->dynamic($platform, $model);
    }
}
