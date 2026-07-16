<?php

namespace Appwrite\Platform\Modules\Functions\Http\Deployments\Duplicate;

use Appwrite\Deployment\Backend;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Utopia\Database\Database;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;
use Utopia\VCS\Adapter\Git\GitHub;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createDuplicateDeployment';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/functions/:functionId/deployments/duplicate')
            ->httpAlias('/v1/functions/:functionId/deployments/:deploymentId/build')
            ->httpAlias('/v1/functions/:functionId/deployments/:deploymentId/builds/:buildId')
            ->desc('Create duplicate deployment')
            ->groups(['api', 'functions'])
            ->label('scope', 'functions.write')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('event', 'functions.[functionId].deployments.[deploymentId].update')
            ->label('audits.event', 'deployment.update')
            ->label('audits.resource', 'function/{request.functionId}')
            ->label('usage.resource', 'function/{request.functionId}')
            ->label('sdk', new Method(
                namespace: 'functions',
                group: 'deployments',
                name: 'createDuplicateDeployment',
                description: <<<EOT
                Create a new build for an existing function deployment. This endpoint allows you to rebuild a deployment with the updated function configuration, including its entrypoint and build commands if they have been modified. The build process will be queued and executed asynchronously. The original deployment's code will be preserved and used for the new build.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_ACCEPTED,
                        model: Response::MODEL_DEPLOYMENT,
                    )
                ]
            ))
            ->param('functionId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Function ID.', false, ['dbForProject'])
            ->param('deploymentId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Deployment ID.', false, ['dbForProject'])
            ->param('buildId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Build unique ID.', true, ['dbForProject']) // added as optional param for backward compatibility
            ->inject('response')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('queueForEvents')
            ->inject('deployments')
            ->inject('deviceForFunctions')
            ->inject('vcsFactory')
            ->callback($this->action(...));
    }

    public function action(
        string $functionId,
        string $deploymentId,
        string $buildId,
        Response $response,
        Database $dbForProject,
        Database $dbForPlatform,
        Event $queueForEvents,
        Backend $deployments,
        Device $deviceForFunctions,
        VcsFactory $vcsFactory,
    ) {
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }
        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        if ($deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        // Remote-source deployments (templates / VCS) on the jobs-service
        // backend never store a source tarball — the build sidecar fetches
        // it — so a duplicate re-fetches the same source from the
        // coordinates persisted on the deployment.
        $path = $deployment->getAttribute('sourcePath');
        $hasSource = ! empty($path) && $deviceForFunctions->exists($path);
        $installationId = $deployment->getAttribute('installationId', '');
        $owner = $deployment->getAttribute('providerRepositoryOwner', '');
        $repository = $deployment->getAttribute('providerRepositoryName', '');

        if (! $hasSource && ($owner === '' || $repository === '')) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $deploymentId = ID::unique();

        $destination = '';
        if ($hasSource) {
            $destination = $deviceForFunctions->getPath($deploymentId . '.' . \pathinfo('code.tar.gz', PATHINFO_EXTENSION));
            $deviceForFunctions->transfer($path, $destination, $deviceForFunctions);
        }

        // Cloning the source deployment's attributes onto the new one, with
        // its own $id and no $sequence, tells the service to create it fresh
        // rather than update the deployment being duplicated. A re-fetched
        // source starts unsized; its stat artifact reports the size.
        $deployment->removeAttribute('$sequence');
        $deployment->setAttributes([
            '$id' => $deploymentId,
            'sourcePath' => $destination,
            'sourceSize' => $hasSource ? $deployment->getAttribute('sourceSize', 0) : 0,
            'totalSize' => $hasSource ? $deployment->getAttribute('sourceSize', 0) : 0,
            'entrypoint' => $function->getAttribute('entrypoint'),
            'buildCommands' => $function->getAttribute('commands', ''),
            'startCommand' => $function->getAttribute('startCommand', ''),
            'buildStartedAt' => null,
            'buildEndedAt' => null,
            'buildDuration' => null,
            'buildSize' => null,
            'buildPath' => '',
            'buildLogs' => '',
            // Not inherited: a redeploy always goes live, and the source's own
            // flag is unset by deactivateOthers() once anything newer builds.
            'activate' => true,
        ]);

        if ($hasSource) {
            $deployment = $deployments->createFromUpload($function, $deployment);
        } elseif ($installationId !== '') {
            $installation = $dbForPlatform->getDocument('installations', $installationId);
            if ($installation->isEmpty()) {
                throw new Exception(Exception::INSTALLATION_NOT_FOUND);
            }

            $github = $vcsFactory->fromInstallation($installation);

            $ref = $deployment->getAttribute('providerCommitHash') ?: $deployment->getAttribute('providerBranch');
            $deployment = $deployments->createFromUrl(
                $function,
                $deployment,
                $github->getRepositoryPresignedUrl($owner, $repository, $ref),
                $deployment->getAttribute('providerRootDirectory', ''),
            );
        } else {
            // Public template repo: providerBranch holds the resolved ref
            // (branch, tag, or commit — see Deployments/Template/Create).
            $deployment = $deployments->createFromRef(
                $function,
                $deployment,
                $owner,
                $repository,
                GitHub::CLONE_TYPE_BRANCH,
                $deployment->getAttribute('providerBranch', ''),
                $deployment->getAttribute('providerRootDirectory', ''),
            );
        }

        $queueForEvents
            ->setParam('functionId', $function->getId())
            ->setParam('deploymentId', $deployment->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($deployment, Response::MODEL_DEPLOYMENT);
    }
}
