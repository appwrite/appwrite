<?php

namespace Appwrite\Platform\Modules\Sites\Http\Deployments\Status;

use Appwrite\Deployment\Deployments;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Transaction as TransactionException;
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
            ->param('siteId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Site ID.', false, ['dbForProject'])
            ->param('deploymentId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Deployment ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('deployments')
            ->inject('locks')
            ->callback($this->action(...));
    }

    public function action(
        string $siteId,
        string $deploymentId,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
        Deployments $deployments,
        callable $locks
    ) {
        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        // Read and decide under the same lock as completion; never cancel a
        // stale snapshot or fall back to an unlocked write on contention.
        $deployment = $locks('jobs-deployment:' . $deploymentId, 30, function () use ($dbForProject, $deploymentId, $site) {
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

            if ($deployment->getAttribute('status') === 'canceled') {
                return $deployment;
            }

            $startTime = new \DateTime($deployment->getAttribute('buildStartedAt', 'now'));
            $endTime = new \DateTime('now');
            $duration = $endTime->getTimestamp() - $startTime->getTimestamp();

            try {
                return $dbForProject->updateDocument('deployments', $deployment->getId(), new Document($this->cancel($deployment, $duration)));
            } catch (TransactionException) {
                $deployment = $dbForProject->getDocument('deployments', $deployment->getId());

                if ($deployment->isEmpty()) {
                    throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
                }

                if (\in_array($deployment->getAttribute('status'), ['ready', 'failed'])) {
                    throw new Exception(Exception::BUILD_ALREADY_COMPLETED);
                }

                if ($deployment->getAttribute('status') !== 'canceled') {
                    $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), new Document($this->cancel($deployment, $duration)));
                }

                return $deployment;
            }
        }, 10.0);

        // Best-effort cleanup — the deployment is already marked 'canceled'.
        try {
            $deployments->cancel($deploymentId);
        } catch (\Throwable) {
        }

        $queueForEvents
            ->setParam('siteId', $site->getId())
            ->setParam('deploymentId', $deployment->getId());

        $response->dynamic($deployment, Response::MODEL_DEPLOYMENT);
    }

    /**
     * The sparse update marking a build canceled. No worker writes the closing
     * log line for a canceled build, so it is appended here.
     *
     * @return array<string, mixed>
     */
    private function cancel(Document $deployment, int $duration): array
    {
        $logs = $deployment->getAttribute('buildLogs', '') . "\033[90m[" . \date('H:i:s') . "] \033[90m[\033[0mappwrite\033[90m]\033[33m Build has been canceled. \033[0m\n";

        return [
            'buildEndedAt' => DateTime::now(),
            'buildDuration' => $duration,
            'status' => 'canceled',
            'buildLogs' => \substr($logs, -APP_LOG_LENGTH_LIMIT),
        ];
    }
}
