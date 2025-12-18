<?php

namespace Appwrite\Platform\Modules\Sites\Http\Deployments\Vcs;

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
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Request;
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
            ->setHttpPath('/v1/sites/:siteId/deployments/vcs')
            ->desc('Create VCS deployment')
            ->groups(['api', 'sites'])
            ->label('scope', 'sites.write')
            ->label('resourceType', RESOURCE_TYPE_SITES)
            ->label('event', 'sites.[siteId].deployments.[deploymentId].create')
            ->label('audits.event', 'deployment.create')
            ->label('audits.resource', 'site/{request.siteId}')
            ->label('sdk', new Method(
                namespace: 'sites',
                group: 'deployments',
                name: 'createVcsDeployment',
                description: <<<EOT
                Create a deployment when a site is connected to VCS.

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
            ->param('siteId', '', new UID(), 'Site ID.')
            // TODO: Support tag in future
            ->param('type', '', new WhiteList(['branch', 'commit', 'tag']), 'Type of reference passed. Allowed values are: branch, commit')
            ->param('reference', '', new Text(255), 'VCS reference to create deployment from. Depending on type this can be: branch name, commit hash')
            ->param('activate', false, new Boolean(), 'Automatically activate the deployment when it is finished building.', true)
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('queueForBuilds')
            ->inject('gitHub')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $siteId,
        string $type,
        string $reference,
        bool $activate,
        Request $request,
        Response $response,
        Database $dbForProject,
        Database $dbForPlatform,
        Document $project,
        Event $queueForEvents,
        Build $queueForBuilds,
        GitHub $github,
        Authorization $authorization
    ) {
        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        $template = new Document();

        $installation = $dbForPlatform->getDocument('installations', $site->getAttribute('installationId'));

        $deployment = $this->redeployVcsSite(
            request: $request,
            site: $site,
            project: $project,
            installation: $installation,
            dbForProject: $dbForProject,
            dbForPlatform: $dbForPlatform,
            queueForBuilds: $queueForBuilds,
            template: $template,
            github: $github,
            activate: $activate,
            authorization: $authorization,
            reference: $reference,
            referenceType: $type
        );

        $queueForEvents
            ->setParam('siteId', $site->getId())
            ->setParam('deploymentId', $deployment->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($deployment, Response::MODEL_DEPLOYMENT);
    }
}
