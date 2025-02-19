<?php

namespace Appwrite\Platform\Modules\Functions\Http\Deployments\Vcs;

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
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Request;
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
            ->desc('Create deployment')
            ->groups(['api', 'functions'])
            ->label('scope', 'functions.write')
            ->label('event', 'functions.[functionId].deployments.[deploymentId].create')
            ->label('audits.event', 'deployment.create')
            ->label('audits.resource', 'function/{request.functionId}')
            ->label('sdk', new Method(
                namespace: 'functions',
                name: 'createVcsDeployment',
                description: <<<EOT
                Create a deployment when a function is connected to VCS.
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
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('queueForBuilds')
            ->inject('gitHub')
            ->callback([$this, 'action']);
    }

    public function action(string $functionId, Request $request, Response $response, Database $dbForProject, Database $dbForPlatform, Document $project, Event $queueForEvents, Build $queueForBuilds, GitHub $github)
    {
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $template = new Document();

        $installation = $dbForPlatform->getDocument('installations', $function->getAttribute('installationId'));

        $deployment = $this->redeployVcsFunction($request, $function, $project, $installation, $dbForProject, $queueForBuilds, $template, $github);

        $queueForEvents
            ->setParam('functionId', $function->getId())
            ->setParam('deploymentId', $deployment->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($deployment, Response::MODEL_DEPLOYMENT);
    }
}
