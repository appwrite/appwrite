<?php

namespace Appwrite\Platform\Modules\Sites\Http\Deployments;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Executor\Executor;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class CancelDeployment extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'cancelDeployment';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/sites/:siteId/deployments/:deploymentId/build')
            ->desc('Cancel deployment')
            ->groups(['api', 'sites'])
            ->label('scope', 'functions.write') //TODO: Update the scope to sites later
            ->label('audits.event', 'deployment.update')
            ->label('audits.resource', 'site/{request.siteId}')
            ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
            ->label('sdk.namespace', 'sites')
            ->label('sdk.method', 'updateDeploymentBuild')
            ->label('sdk.response.code', Response::STATUS_CODE_OK)
            ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
            ->label('sdk.response.model', Response::MODEL_BUILD)
            ->param('siteId', '', new UID(), 'Site ID.')
            ->param('deploymentId', '', new UID(), 'Deployment ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('queueForEvents')
            ->callback([$this, 'action']);
    }

    public function action(string $siteId, string $deploymentId, Response $response, Database $dbForProject, Document $project, Event $queueForEvents)
    {
        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        if ($deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $build = Authorization::skip(fn () => $dbForProject->getDocument('builds', $deployment->getAttribute('buildId', '')));

        if ($build->isEmpty()) {
            $buildId = ID::unique();
            $build = $dbForProject->createDocument('builds', new Document([
                '$id' => $buildId,
                '$permissions' => [],
                'startTime' => DateTime::now(),
                'deploymentInternalId' => $deployment->getInternalId(),
                'deploymentId' => $deployment->getId(),
                'status' => 'canceled',
                'path' => '',
                'runtime' => $site->getAttribute('framework'),
                'source' => $deployment->getAttribute('path', ''),
                'sourceType' => '',
                'logs' => '',
                'duration' => 0,
                'size' => 0
            ]));

            $deployment->setAttribute('buildId', $build->getId());
            $deployment->setAttribute('buildInternalId', $build->getInternalId());
            $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), $deployment);
        } else {
            if (\in_array($build->getAttribute('status'), ['ready', 'failed'])) {
                throw new Exception(Exception::BUILD_ALREADY_COMPLETED);
            }

            $startTime = new \DateTime($build->getAttribute('startTime'));
            $endTime = new \DateTime('now');
            $duration = $endTime->getTimestamp() - $startTime->getTimestamp();

            $build = $dbForProject->updateDocument('builds', $build->getId(), $build->setAttributes([
                'endTime' => DateTime::now(),
                'duration' => $duration,
                'status' => 'canceled'
            ]));
        }

        $dbForProject->purgeCachedDocument('deployments', $deployment->getId());

        try {
            $executor = new Executor(App::getEnv('_APP_EXECUTOR_HOST'));
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

        $response->dynamic($build, Response::MODEL_BUILD);
    }
}
