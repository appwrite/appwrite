<?php

namespace Appwrite\Platform\Modules\Functions\Http\Deployments\Template;

use Appwrite\Deployment\Backend;
use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Build as BuildPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;
use Utopia\VCS\Adapter\Git\GitHub;

class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createTemplateDeployment';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/functions/:functionId/deployments/template')
            ->desc('Create template deployment')
            ->groups(['api', 'functions'])
            ->label('scope', 'functions.write')
            ->label('event', 'functions.[functionId].deployments.[deploymentId].create')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('audits.event', 'deployment.create')
            ->label('audits.resource', 'function/{request.functionId}')
            ->label('usage.resource', 'function/{request.functionId}')
            ->label('sdk', new Method(
                namespace: 'functions',
                group: 'deployments',
                name: 'createTemplateDeployment',
                description: <<<EOT
                Create a deployment based on a template.

                Use this endpoint with combination of [listTemplates](https://appwrite.io/docs/products/functions/templates) to find the template details.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_ACCEPTED,
                        model: Response::MODEL_DEPLOYMENT,
                    )
                ],
            ))
            ->param('functionId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Function ID.', false, ['dbForProject'])
            ->param('repository', '', new Text(128, 0), 'Repository name of the template.')
            ->param('owner', '', new Text(128, 0), 'The name of the owner of the template.')
            ->param('rootDirectory', '', new Text(128, 0), 'Path to function code in the template repo.')
            ->param('type', '', new WhiteList(['commit', 'branch', 'tag']), 'Type for the reference provided. Can be commit, branch, or tag', enum: new Enum(name: 'TemplateReferenceType'))
            ->param('reference', '', new Text(128, 0), 'Reference value, can be a commit hash, branch name, or release tag')
            ->param('activate', false, new Boolean(), 'Automatically activate the deployment when it is finished building.', true)
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('queueForEvents')
            ->inject('project')
            ->inject('publisherForBuilds')
            ->inject('vcsFactory')
            ->inject('deployments')
            ->inject('authorization')
            ->inject('platform')
            ->callback($this->action(...));
    }

    public function action(
        string $functionId,
        string $repository,
        string $owner,
        string $rootDirectory,
        string $type,
        string $reference,
        bool $activate,
        Request $request,
        Response $response,
        Database $dbForProject,
        Database $dbForPlatform,
        Event $queueForEvents,
        Document $project,
        BuildPublisher $publisherForBuilds,
        VcsFactory $vcsFactory,
        Backend $deployments,
        Authorization $authorization,
        array $platform
    ) {
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $branchUrl = "https://github.com/$owner/$repository/blob/$reference";

        $repositoryUrl = "https://github.com/$owner/$repository";

        $template = new Document([
            'repositoryName' => $repository,
            'ownerName' => $owner,
            'rootDirectory' => $rootDirectory,
            'referenceType' => $type,
            'referenceValue' => $reference,
        ]);

        if (!empty($function->getAttribute('providerRepositoryId'))) {
            // VCS-connected function: the Builds worker merges the template into
            // the user's repo, pushes it as a commit, then builds that commit —
            // on the executor itself, or by submitting a job when
            // _APP_BUILDS_BACKEND=orchestrator.
            $installation = $dbForPlatform->getDocument('installations', $function->getAttribute('installationId'));

            $deployment = $this->redeployVcsFunction(
                request: $request,
                function: $function,
                project: $project,
                installation: $installation,
                dbForProject: $dbForProject,
                publisherForBuilds: $publisherForBuilds,
                template: $template,
                vcs: $vcsFactory->fromInstallation($installation),
                activate: $activate,
                deployments: $deployments,
                platform: $platform,
                referenceType: $type,
                reference: $reference
            );

            $queueForEvents
                ->setParam('functionId', $function->getId())
                ->setParam('deploymentId', $deployment->getId());

            $response
                ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
                ->dynamic($deployment, Response::MODEL_DEPLOYMENT);

            return;
        }

        $deploymentId = ID::unique();

        // Templates can pin a version range (e.g. "0.3.*"); codeload only
        // takes a concrete ref, so resolve the range to the highest matching
        // tag (mirrors the executor's `git ls-remote --tags | tail -1`).
        // Templates are public github.com repositories regardless of the
        // function's own provider, so this always uses the GitHub adapter.
        $ref = $reference;
        if ($type === GitHub::CLONE_TYPE_TAG && \str_contains($reference, '*')) {
            try {
                $tags = $vcsFactory->fromProvider('github')->listTags($owner, $repository, $reference);
                $ref = \end($tags) ?: $reference;
            } catch (\Throwable) {
                // Fall back to the raw reference; the build surfaces a bad ref.
            }
        }

        // Public template: pull the source straight from GitHub's public repo
        // (codeload tarball on the jobs backend, a plain git clone on the
        // executor); unarchive/checkout strips down to the rootDirectory.
        $deployment = $deployments->createFromRef(
            $function,
            new Document([
                '$id' => $deploymentId,
                'entrypoint' => $function->getAttribute('entrypoint', ''),
                'buildCommands' => $function->getAttribute('commands', ''),
                'startCommand' => $function->getAttribute('startCommand', ''),
                'providerRepositoryName' => $repository,
                'providerRepositoryOwner' => $owner,
                'providerRepositoryUrl' => $repositoryUrl,
                'providerBranchUrl' => $branchUrl,
                // The resolved concrete ref (branch, tag, or commit — codeload
                // serves them all through the same URL form), so a duplicate
                // can re-fetch the exact same source later; likewise the root
                // directory. Remote-source builds never store a tarball, so
                // these coordinates are all a redeploy has.
                'providerBranch' => $ref,
                'providerRootDirectory' => $rootDirectory,
                'type' => 'vcs',
                'activate' => $activate,
            ]),
            $owner,
            $repository,
            $type,
            $ref,
            $rootDirectory,
        );

        $this->updateEmptyManualRule($project, $function, $deployment, $dbForPlatform, $authorization);

        $queueForEvents
            ->setParam('functionId', $function->getId())
            ->setParam('deploymentId', $deployment->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($deployment, Response::MODEL_DEPLOYMENT);
    }
}
