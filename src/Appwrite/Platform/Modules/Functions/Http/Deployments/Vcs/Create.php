<?php

namespace Appwrite\Platform\Modules\Functions\Http\Deployments\Vcs;

use Appwrite\Bus\Events\DeploymentCreated;
use Appwrite\Event\Publisher\Build as BuildPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Bus\Bus;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;
use Utopia\VCS\Adapter\Git\GitHub;

class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createVcsDeployment';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/functions/:functionId/deployments/vcs')
            ->desc('Create VCS deployment')
            ->groups(['api', 'functions'])
            ->label('scope', 'functions.write')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('audits.event', 'deployment.create')
            ->label('audits.resource', 'function/{request.functionId}')
            ->label('usage.resource', 'function/{request.functionId}')
            ->label('sdk', new Method(
                namespace: 'functions',
                group: 'deployments',
                name: 'createVcsDeployment',
                description: <<<EOT
                Create a deployment when a function is connected to VCS.

                This endpoint lets you create deployment from a branch, commit, or a tag.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_ACCEPTED,
                        model: Response::MODEL_DEPLOYMENT,
                    )
                ],
            ))
            ->param('functionId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Function ID.', false, ['dbForProject'])
            // TODO: Support tag in future
            ->param('type', '', new WhiteList(['branch', 'commit']), 'Type of reference passed. Allowed values are: branch, commit', enum: new Enum(name: 'VCSReferenceType'))
            ->param('reference', '', new Text(255), 'VCS reference to create deployment from. Depending on type this can be: branch name, commit hash')
            ->param('activate', false, new Boolean(), 'Automatically activate the deployment when it is finished building.', true)
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('bus')
            ->inject('publisherForBuilds')
            ->inject('gitHub')
            ->inject('platform')
            ->inject('user')
            ->callback($this->action(...));
    }

    public function action(
        string $functionId,
        string $type,
        string $reference,
        bool $activate,
        Request $request,
        Response $response,
        Database $dbForProject,
        Database $dbForPlatform,
        Document $project,
        Bus $bus,
        BuildPublisher $publisherForBuilds,
        GitHub $github,
        array $platform,
        Document $actor,
    ) {
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $template = new Document();

        $installation = $dbForPlatform->getDocument('installations', $function->getAttribute('installationId'));

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $deployment = $this->redeployVcsFunction(
            request: $request,
            function: $function,
            project: $project,
            installation: $installation,
            dbForProject: $dbForProject,
            publisherForBuilds: $publisherForBuilds,
            template: $template,
            github: $github,
            activate: $activate,
            platform: $platform,
            reference: $reference,
            referenceType: $type
        );

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($deployment, Response::MODEL_DEPLOYMENT);

        $bus->dispatch(new DeploymentCreated($deployment, $function->getId(), $project, $actor));
    }
}
