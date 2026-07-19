<?php

namespace Appwrite\Platform\Modules\Functions\Http\Deployments\Status;

use Appwrite\Deployment\Backend;
use Appwrite\Deployment\Backend\Orchestrator;
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
            ->setHttpPath('/v1/functions/:functionId/deployments/:deploymentId/status')
            ->httpAlias('/v1/functions/:functionId/deployments/:deploymentId/build')
            ->desc('Update deployment status')
            ->groups(['api', 'functions'])
            ->label('scope', 'functions.write')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('audits.event', 'deployment.update')
            ->label('audits.resource', 'function/{request.functionId}')
            ->label('usage.resource', 'function/{request.functionId}')
            ->label('sdk', new Method(
                namespace: 'functions',
                group: 'deployments',
                name: 'updateDeploymentStatus',
                description: <<<EOT
                Cancel an ongoing function deployment build. If the build is already in progress, it will be stopped and marked as canceled. If the build hasn't started yet, it will be marked as canceled without executing. You cannot cancel builds that have already completed (status 'ready') or failed. The response includes the final build status and details.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_DEPLOYMENT,
                    )
                ]
            ))
            ->param('functionId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Function ID.', false, ['dbForProject'])
            ->param('deploymentId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Deployment ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('deployments')
            ->callback($this->action(...));
    }

    public function action(
        string $functionId,
        string $deploymentId,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
        Backend $deployments,
    ) {
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
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

        try {
            $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), new Document($this->cancel($deployment, $duration, $deployments instanceof Orchestrator)));
        } catch (TransactionException) {
            $deployment = $dbForProject->getDocument('deployments', $deployment->getId());

            if ($deployment->isEmpty()) {
                throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
            }

            if (\in_array($deployment->getAttribute('status'), ['ready', 'failed'])) {
                throw new Exception(Exception::BUILD_ALREADY_COMPLETED);
            }

            if ($deployment->getAttribute('status') !== 'canceled') {
                $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), new Document($this->cancel($deployment, $duration, $deployments instanceof Orchestrator)));
            }
        }

        // Best-effort cleanup — the deployment is already marked 'canceled'.
        try {
            $deployments->cancel($deploymentId);
        } catch (\Throwable) {
        }

        $queueForEvents
            ->setParam('functionId', $function->getId())
            ->setParam('deploymentId', $deployment->getId());

        $response->dynamic($deployment, Response::MODEL_DEPLOYMENT);
    }

    /**
     * The sparse update marking a build canceled. Jobs-backed builds have no
     * cancel worker to write the closing log line the executor's Builds worker
     * adds, so it is appended here; executor deployments get it from their worker.
     *
     * @return array<string, mixed>
     */
    private function cancel(Document $deployment, int $duration, bool $appendLog): array
    {
        $update = [
            'buildEndedAt' => DateTime::now(),
            'buildDuration' => $duration,
            'status' => 'canceled',
        ];

        if ($appendLog) {
            $logs = $deployment->getAttribute('buildLogs', '') . "\033[90m[" . \date('H:i:s') . "] \033[90m[\033[0mappwrite\033[90m]\033[33m Build has been canceled. \033[0m\n";
            $update['buildLogs'] = \substr($logs, -APP_LOG_LENGTH_LIMIT);
        }

        return $update;
    }
}
