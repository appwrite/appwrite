<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Platforms;

use Appwrite\Extend\Exception;
use Appwrite\Network\Platform;
use Appwrite\Platform\Modules\Compute\Base;
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
            ->httpAlias('/v1/projects/:projectId/platforms/:platformId')
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
                            Response::MODEL_PLATFORM_APPLE,
                            Response::MODEL_PLATFORM_ANDROID,
                            Response::MODEL_PLATFORM_WINDOWS,
                            Response::MODEL_PLATFORM_LINUX,
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

        $type = $platform->getAttribute('type');

        $model = match($type) {
            Platform::TYPE_WEB => Response::MODEL_PLATFORM_WEB,
            Platform::TYPE_APPLE => Response::MODEL_PLATFORM_APPLE,
            Platform::TYPE_ANDROID => Response::MODEL_PLATFORM_ANDROID,
            Platform::TYPE_WINDOWS => Response::MODEL_PLATFORM_WINDOWS,
            Platform::TYPE_LINUX => Response::MODEL_PLATFORM_LINUX,
            default => throw new Exception(Exception::GENERAL_UNKNOWN, 'Platform type ' . $type . ' is not supported'),
        };

        $response->dynamic($platform, $model);
    }
}
