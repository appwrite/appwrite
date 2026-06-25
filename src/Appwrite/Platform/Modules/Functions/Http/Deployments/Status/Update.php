<?php

namespace Appwrite\Platform\Modules\Functions\Http\Deployments\Status;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Executor\Executor;
use OpenRuntimes\Orchestrator\Client as OrchestratorClient;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Transaction as TransactionException;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

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
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('executor')
            ->callback($this->action(...));
    }

    public function action(
        string $functionId,
        string $deploymentId,
        Response $response,
        Database $dbForProject,
        Document $project,
        Event $queueForEvents,
        Executor $executor
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
        $logs = $deployment->getAttribute('buildLogs', '');
        if (!\str_contains($logs, 'Build has been canceled.')) {
            $date = \date('H:i:s');
            $logs .= "\033[90m[$date] \033[90m[\033[0mappwrite\033[90m]\033[33m Build has been canceled. \033[0m\n";
        }

        try {
            $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), new Document([
                'buildEndedAt' => DateTime::now(),
                'buildDuration' => $duration,
                'status' => 'canceled',
                'buildLogs' => $logs,
            ]));

            if ($deployment->getSequence() === $function->getAttribute('latestDeploymentInternalId', '')) {
                $function = $dbForProject->updateDocument('functions', $function->getId(), new Document([
                    'latestDeploymentStatus' => $deployment->getAttribute('status', ''),
                ]));
            }
        } catch (TransactionException) {
            $deployment = $dbForProject->getDocument('deployments', $deployment->getId());

            if ($deployment->isEmpty()) {
                throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
            }

            if (\in_array($deployment->getAttribute('status'), ['ready', 'failed'])) {
                throw new Exception(Exception::BUILD_ALREADY_COMPLETED);
            }

            if ($deployment->getAttribute('status') !== 'canceled') {
                $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), new Document([
                    'buildEndedAt' => DateTime::now(),
                    'buildDuration' => $duration,
                    'status' => 'canceled',
                    'buildLogs' => $logs,
                ]));
            }

            if ($deployment->getSequence() === $function->getAttribute('latestDeploymentInternalId', '')) {
                $function = $dbForProject->updateDocument('functions', $function->getId(), new Document([
                    'latestDeploymentStatus' => $deployment->getAttribute('status', ''),
                ]));
            }
        }

        try {
            $dbForProject->getAuthorization()->skip(fn () => $dbForProject->deleteDocuments('resourceTokens', [
                Query::equal('resourceType', [TOKENS_RESOURCE_TYPE_DEPLOYMENT_ARTIFACTS]),
                Query::equal('resourceInternalId', [$function->getSequence() . ':' . $deployment->getSequence()]),
            ]));
        } catch (\Throwable) {
        }

        try {
            if (System::getEnv('_APP_BUILDS_BACKEND', 'executor') === 'orchestrator') {
                $client = new OrchestratorClient(
                    endpoint: System::getEnv('_APP_ORCHESTRATOR_HOST', ''),
                    apiKey: System::getEnv('_APP_ORCHESTRATOR_API_KEY', '') ?: null,
                );
                $client->jobs()->delete($project->getId() . '-' . $deploymentId . '-build');
            } else {
                $executor->deleteRuntime($project->getId(), $deploymentId . "-build");
            }
        } catch (\Throwable) {
        }

        $queueForEvents
            ->setParam('functionId', $function->getId())
            ->setParam('deploymentId', $deployment->getId());

        $response->dynamic($deployment, Response::MODEL_DEPLOYMENT);
    }
}
