<?php

namespace Appwrite\Platform\Modules\Functions\Http\Deployments\Template;

use Appwrite\Compute\Job;
use Appwrite\Event\Event;
use Appwrite\Event\Message\Build as BuildMessage;
use Appwrite\Event\Publisher\Build as BuildPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use OpenRuntimes\Orchestrator\Jobs;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
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
            ->inject('jobs')
            ->inject('gitHub')
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
        Jobs $jobs,
        GitHub $github,
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
            // VCS-connected function: the template is merged into the user's repo
            // and pushed as a commit, then that commit is built. This stays on the
            // executor for now because it is a git *write*, which the jobs-service
            // artifact system (download/unarchive) does not cover.
            //
            // Plan to move it to the jobs backend (3a — Git Data API push, no git
            // binary), when utopia-php/vcs grows the bulk-push methods:
            //   1. download the template tarball (codeload) + read the tree of the
            //      target repo/branch (github->getRepositoryTree).
            //   2. for each template file: create a blob (POST .../git/blobs).
            //   3. create a tree from the blobs on top of the branch's base tree
            //      (POST .../git/trees), then a commit (POST .../git/commits), then
            //      fast-forward the branch ref (PATCH .../git/refs/heads/{branch}).
            //   4. take the resulting commit hash and submit the build via
            //      Job::build (same authenticated-tarball path as createVcsDeployment).
            // This replaces clone + rsync + `git commit && git push` with ~4 API
            // calls and no working tree. Until then, executor handles this branch.
            $installation = $dbForPlatform->getDocument('installations', $function->getAttribute('installationId'));

            $deployment = $this->redeployVcsFunction(
                request: $request,
                function: $function,
                project: $project,
                installation: $installation,
                dbForProject: $dbForProject,
                publisherForBuilds: $publisherForBuilds,
                template: $template,
                github: $github,
                activate: $activate,
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

        // Backend for the template build: 'orchestrator' (jobs-service, which
        // pulls the public GitHub tarball via artifacts) or 'executor' (default;
        // the Builds worker clones the repo). The jobs path pre-declares buildPath.
        $useJobs = System::getEnv('_APP_BUILDS_BACKEND', 'executor') === 'orchestrator';

        $deploymentId = ID::unique();
        $deployment = $dbForProject->createDocument('deployments', new Document([
            '$id' => $deploymentId,
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'resourceId' => $function->getId(),
            'resourceInternalId' => $function->getSequence(),
            'resourceType' => 'functions',
            'entrypoint' => $function->getAttribute('entrypoint', ''),
            'buildCommands' => $function->getAttribute('commands', ''),
            'startCommand' => $function->getAttribute('startCommand', ''),
            'providerRepositoryName' => $repository,
            'providerRepositoryOwner' => $owner,
            'providerRepositoryUrl' => $repositoryUrl,
            'providerBranchUrl' => $branchUrl,
            'providerBranch' => $type == GitHub::CLONE_TYPE_BRANCH ? $reference : '',
            'type' => 'vcs',
            'activate' => $activate,
            'status' => 'waiting',
            'buildPath' => $useJobs ? Job::buildPath($project->getId(), $deploymentId) : '',
        ]));

        $this->updateEmptyManualRule($project, $function, $deployment, $dbForPlatform, $authorization);

        if ($useJobs) {
            // Templates can pin a version range (e.g. "0.3.*"); codeload only
            // takes a concrete ref, so resolve the range to the highest matching
            // tag (mirrors the executor's `git ls-remote --tags | tail -1`).
            $ref = $reference;
            if ($type === GitHub::CLONE_TYPE_TAG && \str_contains($reference, '*')) {
                try {
                    $tags = $github->listTags($owner, $repository, $reference);
                    $ref = \end($tags) ?: $reference;
                } catch (\Throwable) {
                    // Fall back to the raw reference; the build surfaces a bad ref.
                }
            }

            // Public template: pull the source straight from GitHub's codeload
            // tarball; unarchive strips the "{repo}-{ref}/" wrapper + the rootDirectory.
            $source = [
                'url' => "https://codeload.github.com/{$owner}/{$repository}/tar.gz/{$ref}",
                'subdir' => $rootDirectory,
            ];
            $jobs->create(...Job::build($project, $function, $deployment, $platform, $source));
        } else {
            $publisherForBuilds->enqueue(new BuildMessage(
                project: $project,
                resource: $function,
                deployment: $deployment,
                type: BUILD_TYPE_DEPLOYMENT,
                template: $template,
                platform: $platform,
            ));
        }

        $queueForEvents
            ->setParam('functionId', $function->getId())
            ->setParam('deploymentId', $deployment->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($deployment, Response::MODEL_DEPLOYMENT);
    }
}
