<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Services\Status;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\WhiteList;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectServiceStatus';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/services/:serviceId/status')
            ->httpAlias('/v1/projects/:projectId/service')
            ->desc('Update project service status')
            ->groups(['api', 'project'])
            ->label('scope', 'project.write')
            ->label('event', 'services.[service].update')
            ->label('audits.event', 'project.services.[service].update')
            ->label('audits.resource', 'project.services/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: null,
                name: 'updateServiceStatus',
                description: <<<EOT
                Update the status of a specific service. Use this endpoint to enable or disable a service in your project. 
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ],
            ))
            ->param('serviceId', '', new WhiteList(array_keys(array_filter(Config::getParam('services'), fn ($element) => $element['optional'])), true), 'Service name. Can be one of: '.\implode(', ', array_keys(array_filter(Config::getParam('services'), fn ($element) => $element['optional']))))
            ->param('enabled', null, new Boolean(), 'Service status.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $serviceId,
        bool $enabled,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization
    ): void {
        $services = $project->getAttribute('services', []);
        $services[$serviceId] = $enabled;

        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
            'services' => $services,
        ])));

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}
