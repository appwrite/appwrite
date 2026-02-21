<?php

namespace Appwrite\Platform\Modules\Sites\Http\Deployments\Status;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Executor\Executor;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateDeploymentStatus';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/sites/:siteId/deployments/:deploymentId/status')
            ->desc('Update deployment status')
            ->groups(['api', 'sites'])
            ->label('scope', 'sites.write')
            ->label('audits.event', 'deployment.update')
            ->label('audits.resource', 'site/{request.siteId}')
            ->label('sdk', new Method(
                namespace: 'sites',
                group: 'deployments',
                name: 'updateDeploymentStatus',
                description: <<<EOT
                Cancel an ongoing site deployment build. If the build is already in progress, it will be stopped and marked as canceled. If the build hasn't started yet, it will be marked as canceled without executing. You cannot cancel builds that have already completed (status 'ready') or failed. The response includes the final build status and details.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
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
            ->inject('dbForProject')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('executor')
            ->callback($this->action(...));
    }

    public function action(
        string $siteId,
        string $deploymentId,
        Response $response,
        Database $dbForProject,
        Document $project,
        Event $queueForEvents,
        Executor $executor
    ) {
        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        if ($deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        if (\in_array($deployment->getAttribute('status'), ['ready', 'failed'])) {
            throw new Exception(Exception::BUILD_ALREADY_COMPLETED);
        }

        $startTime = new \DateTime($deployment->getAttribute('buildStartedAt', 'now'));
        $endTime = new \DateTime('now');
        $duration = $endTime->getTimestamp() - $startTime->getTimestamp();

        $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), $deployment->setAttributes([
            'buildEndedAt' => DateTime::now(),
            'buildDuration' => $duration,
            'status' => 'canceled'
        ]));

        if ($deployment->getSequence() === $site->getAttribute('latestDeploymentInternalId', '')) {
            $site = $site->setAttribute('latestDeploymentStatus', $deployment->getAttribute('status', ''));
            $dbForProject->updateDocument('sites', $site->getId(), $site);
        }

        try {
            $executor->deleteRuntime($project->getId(), $deploymentId . "-build");
        } catch (\Throwable $th) {
            // Don't throw if the deployment doesn't exist
            if ($th->getCode() !== 404) {
                throw $th;
            }
        }

        $queueForEvents
            ->setParam('siteId', $site->getId())
            ->setParam('deploymentId', $deployment->getId());

        $response->dynamic($deployment, Response::MODEL_DEPLOYMENT);
    }
}
