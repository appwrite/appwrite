<?php

namespace Appwrite\Platform\Modules\Functions\Http\Deployments\Template;

use Appwrite\Event\Build;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Request;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;
use Utopia\VCS\Adapter\Git\GitHub;

class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createTemplateDeployment';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/functions/:functionId/deployments/template')
            ->desc('Create template deployment')
            ->groups(['api', 'functions'])
            ->label('scope', 'functions.write')
            ->label('event', 'functions.[functionId].deployments.[deploymentId].create')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('audits.event', 'deployment.create')
            ->label('audits.resource', 'function/{request.functionId}')
            ->label('sdk', new Method(
                namespace: 'functions',
                group: 'deployments',
                name: 'createTemplateDeployment',
                description: <<<EOT
                Create a deployment based on a template.
                
                Use this endpoint with combination of [listTemplates](https://appwrite.io/docs/server/functions#listTemplates) to find the template details.
                EOT,
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_ACCEPTED,
                        model: Response::MODEL_DEPLOYMENT,
                    )
                ],
            ))
            ->param('functionId', '', new UID(), 'Function ID.')
            ->param('repository', '', new Text(128, 0), 'Repository name of the template.')
            ->param('owner', '', new Text(128, 0), 'The name of the owner of the template.')
            ->param('rootDirectory', '', new Text(128, 0), 'Path to function code in the template repo.')
            ->param('version', '', new Text(128, 0), 'Version (tag) for the repo linked to the function template.')
            ->param('activate', false, new Boolean(), 'Automatically activate the deployment when it is finished building.', true)
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('queueForEvents')
            ->inject('project')
            ->inject('queueForBuilds')
            ->inject('gitHub')
            ->callback($this->action(...));
    }

    public function action(
        string $functionId,
        string $repository,
        string $owner,
        string $rootDirectory,
        string $version,
        bool $activate,
        Request $request,
        Response $response,
        Database $dbForProject,
        Database $dbForPlatform,
        Event $queueForEvents,
        Document $project,
        Build $queueForBuilds,
        GitHub $github
    ) {
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $template = new Document([
            'repositoryName' => $repository,
            'ownerName' => $owner,
            'rootDirectory' => $rootDirectory,
            'version' => $version
        ]);

        if (!empty($function->getAttribute('providerRepositoryId'))) {
            $installation = $dbForPlatform->getDocument('installations', $function->getAttribute('installationId'));

            $deployment = $this->redeployVcsFunction(
                request: $request,
                function: $function,
                project: $project,
                installation: $installation,
                dbForProject: $dbForProject,
                queueForBuilds: $queueForBuilds,
                template: $template,
                github: $github,
                activate: $activate
            );

            $queueForEvents
                ->setParam('functionId', $function->getId())
                ->setParam('deploymentId', $deployment->getId());

            $response
                ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
                ->dynamic($deployment, Response::MODEL_DEPLOYMENT);

            return;
        }

        $deploymentId = ID::unique();
        $deployment = $dbForProject->createDocument('deployments', new Document([
            '$id' => $deploymentId,
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'resourceId' => $function->getId(),
            'resourceInternalId' => $function->getInternalId(),
            'resourceType' => 'functions',
            'entrypoint' => $function->getAttribute('entrypoint', ''),
            'buildCommands' => $function->getAttribute('commands', ''),
            'type' => 'manual',
            'activate' => $activate,
        ]));

        $function = $function
            ->setAttribute('latestDeploymentId', $deployment->getId())
            ->setAttribute('latestDeploymentInternalId', $deployment->getInternalId())
            ->setAttribute('latestDeploymentCreatedAt', $deployment->getCreatedAt())
            ->setAttribute('latestDeploymentStatus', $deployment->getAttribute('status', ''));
        $dbForProject->updateDocument('functions', $function->getId(), $function);

        $queueForBuilds
            ->setType(BUILD_TYPE_DEPLOYMENT)
            ->setResource($function)
            ->setDeployment($deployment)
            ->setTemplate($template);

        $queueForEvents
            ->setParam('functionId', $function->getId())
            ->setParam('deploymentId', $deployment->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($deployment, Response::MODEL_DEPLOYMENT);
    }
}
