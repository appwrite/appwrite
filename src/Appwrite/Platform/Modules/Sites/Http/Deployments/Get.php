<?php

namespace Appwrite\Platform\Modules\Sites\Http\Deployments;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getDeployment';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/sites/:siteId/deployments/:deploymentId')
            ->desc('Get deployment')
            ->groups(['api', 'sites'])
            ->label('scope', 'sites.read')
            ->label('sdk', new Method(
                namespace: 'sites',
                name: 'getDeployment',
                description: <<<EOT
                Get a site deployment by its unique ID.
                EOT,
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_DEPLOYMENT,
                    )
                ]
            ))
            ->param('siteId', '', new UID(), 'Site ID.')
            ->param('deploymentId', '', new UID(), 'Deployment ID.')
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->callback([$this, 'action']);
    }

    public function action(string $siteId, string $deploymentId, Response $response, Document $project, Database $dbForProject, Database $dbForPlatform)
    {
        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        if ($deployment->getAttribute('resourceId') !== $site->getId()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        if ($deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $build = $dbForProject->getDocument('builds', $deployment->getAttribute('buildId', ''));
        $deployment->setAttribute('status', $build->getAttribute('status', 'waiting'));
        $deployment->setAttribute('buildLogs', $build->getAttribute('logs', ''));
        $deployment->setAttribute('buildTime', $build->getAttribute('duration', 0));
        $deployment->setAttribute('buildSize', $build->getAttribute('size', 0));
        $deployment->setAttribute('size', $deployment->getAttribute('size', 0));

        $rule = Authorization::skip(fn () => $dbForPlatform->findOne('rules', [
            Query::equal("projectInternalId", [$project->getInternalId()]),
            Query::equal("resourceType", ["deployment"]),
            Query::equal("resourceInternalId", [$deployment->getInternalId()])
        ]));

        if (!empty($rule)) {
            $deployment->setAttribute('domain', $rule->getAttribute('domain', ''));
        }

        $response->dynamic($deployment, Response::MODEL_DEPLOYMENT);
    }
}
