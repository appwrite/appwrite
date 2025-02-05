<?php

namespace Appwrite\Platform\Modules\Sites\Http\Deployments\Builds;

use Appwrite\Event\Build;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createDeploymentBuild';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/sites/:siteId/deployments/:deploymentId/build')
            ->desc('Rebuild deployment')
            ->groups(['api', 'sites'])
            ->label('scope', 'sites.write')
            ->label('event', 'sites.[siteId].deployments.[deploymentId].update')
            ->label('audits.event', 'deployment.update')
            ->label('audits.resource', 'site/{request.siteId}')
            ->label('sdk', new Method(
                namespace: 'sites',
                name: 'createDeploymentBuild',
                description: '/docs/references/sites/create-deployment-build.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ]
            ))
            ->param('siteId', '', new UID(), 'Site ID.')
            ->param('deploymentId', '', new UID(), 'Deployment ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('queueForBuilds')
            ->inject('deviceForSites')
            ->inject('deviceForFunctions') //TODO: remove it later
            ->callback([$this, 'action']);
    }

    public function action(string $siteId, string $deploymentId, Response $response, Database $dbForProject, Event $queueForEvents, Build $queueForBuilds, Device $deviceForSites, Device $deviceForFunctions)
    {
        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }
        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        if ($deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $path = $deployment->getAttribute('path');
        if (empty($path) || !$deviceForFunctions->exists($path)) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $deploymentId = ID::unique();

        $destination = $deviceForFunctions->getPath($deploymentId . '.' . \pathinfo('code.tar.gz', PATHINFO_EXTENSION));
        $deviceForFunctions->transfer($path, $destination, $deviceForFunctions);

        $deployment->removeAttribute('$internalId');
        $deployment = $dbForProject->createDocument('deployments', $deployment->setAttributes([
            '$internalId' => '',
            '$id' => $deploymentId,
            'buildId' => '',
            'buildInternalId' => '',
            'path' => $destination,
            'buildCommand' => $site->getAttribute('buildCommand', ''),
            'installCommand' => $site->getAttribute('installCommand', ''),
            'outputDirectory' => $site->getAttribute('outputDirectory', ''),
            'search' => implode(' ', [$deploymentId]),
        ]));

        $queueForBuilds
            ->setType(BUILD_TYPE_DEPLOYMENT)
            ->setResource($site)
            ->setDeployment($deployment);

        $queueForEvents
            ->setParam('siteId', $site->getId())
            ->setParam('deploymentId', $deployment->getId());

        $response->noContent();
    }
}
