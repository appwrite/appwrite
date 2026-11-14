<?php

namespace Appwrite\Platform\Modules\Sites\Http\Deployments\Status;

use Appwrite\Deployment\Deployments;
use Appwrite\Deployment\GitAction;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Transaction as TransactionException;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Lock\Exception\Contention;
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
            ->label('usage.resource', 'site/{request.siteId}')
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
            ->param('siteId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Site ID.', false, ['dbForProject'])
            ->param('deploymentId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Deployment ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('deployments')
            ->inject('locks')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('vcsFactory')
            ->inject('platform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $siteId,
        string $deploymentId,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
        Deployments $deployments,
        callable $locks,
        Document $project,
        Database $dbForPlatform,
        VcsFactory $vcsFactory,
        array $platform,
        Authorization $authorization,
    ) {
        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        if ($deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        if (
            $deployment->getAttribute('resourceId') !== $site->getId()
            || $deployment->getAttribute('resourceType') !== 'sites'
        ) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        if (\in_array($deployment->getAttribute('status'), ['ready', 'failed'])) {
            throw new Exception(Exception::BUILD_ALREADY_COMPLETED);
        }

        // Write under the Jobs worker's per-deployment lock: its handlers
        // read-modify-write buildLogs, and an unserialized cancel write here
        // loses the closing log line to an in-flight append.
        $cancel = function () use ($dbForProject, $deployment) {
            try {
                return $dbForProject->updateDocument('deployments', $deployment->getId(), new Document(Deployments::canceledAttributes($deployment)));
            } catch (TransactionException) {
                $deployment = $dbForProject->getDocument('deployments', $deployment->getId());

                if ($deployment->isEmpty()) {
                    throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
                }

                if (\in_array($deployment->getAttribute('status'), ['ready', 'failed'])) {
                    throw new Exception(Exception::BUILD_ALREADY_COMPLETED);
                }

                if ($deployment->getAttribute('status') !== 'canceled') {
                    $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), new Document(Deployments::canceledAttributes($deployment)));
                }

                return $deployment;
            }
        };

        try {
            $deployment = $locks(Deployments::lock($project->getSequence(), $deploymentId), 30, $cancel, 10.0);
        } catch (Contention) {
            $deployment = $cancel();
        }

        // Best-effort cleanup — the deployment is already marked 'canceled'.
        try {
            $deployments->cancel($deploymentId);
        } catch (\Throwable) {
        }

        // Also best-effort: the provider's check would otherwise wait on a build that never finishes.
        $authorization->skip(fn () => GitAction::report('canceled', $deployment, $project, $dbForProject, $dbForPlatform, $vcsFactory, $platform));

        $queueForEvents
            ->setParam('siteId', $site->getId())
            ->setParam('deploymentId', $deployment->getId());

        $response->dynamic($deployment, Response::MODEL_DEPLOYMENT);
    }
}
