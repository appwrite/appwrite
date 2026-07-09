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
use Utopia\Console;
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
            // and pushed as a commit, then that commit is built.
            $installation = $dbForPlatform->getDocument('installations', $function->getAttribute('installationId'));

            if (System::getEnv('_APP_BUILDS_BACKEND', 'executor') === 'orchestrator') {
                // The jobs-service artifact system only reads source (download /
                // unarchive), so it cannot do the git *write*. Perform the merge +
                // commit + push here (mirroring the executor Builds worker), then
                // build the resulting commit on the jobs-service like any other VCS
                // commit — no template merge needed in the build.
                $commitHash = $this->pushTemplateToRepository($github, $function, $installation, $owner, $repository, $reference, $type, $rootDirectory);
                $deployment = $this->redeployVcsFunction(
                    request: $request,
                    function: $function,
                    project: $project,
                    installation: $installation,
                    dbForProject: $dbForProject,
                    publisherForBuilds: $publisherForBuilds,
                    template: new Document(),
                    github: $github,
                    activate: $activate,
                    platform: $platform,
                    referenceType: 'commit',
                    reference: $commitHash,
                    jobs: $jobs
                );
            } else {
                // Executor: the Builds worker clones the repo, merges the template,
                // pushes the commit and builds it, all in the build job.
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
            }

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

    /**
     * Merge a template into a VCS-connected function's repository and push it as a
     * commit, returning the new commit hash. Replicates the executor Builds
     * worker's clone + rsync + commit + push for the jobs backend, which has no
     * git-write primitive; the resulting commit is then built like any other VCS
     * commit. Runs the git binary in the request flow.
     */
    private function pushTemplateToRepository(
        GitHub $github,
        Document $function,
        Document $installation,
        string $templateOwner,
        string $templateRepository,
        string $templateReference,
        string $templateType,
        string $templateRootDirectory,
    ): string {
        $providerInstallationId = $installation->getAttribute('providerInstallationId', '');
        if (empty($providerInstallationId)) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }
        $github->initializeVariables($providerInstallationId, System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY', ''), System::getEnv('_APP_VCS_GITHUB_APP_ID', ''));

        $owner = $github->getOwnerName($providerInstallationId);
        $repository = $github->getRepositoryName($function->getAttribute('providerRepositoryId', ''));
        $branch = $function->getAttribute('providerBranch', 'main');

        $rootDirectory = \ltrim(\ltrim(\rtrim($function->getAttribute('providerRootDirectory', ''), '/'), '.'), '/');
        $templateRootDirectory = \ltrim(\ltrim(\rtrim($templateRootDirectory, '/'), '.'), '/');

        $id = ID::unique();
        $repoDirectory = '/tmp/templates/' . $id . '/code';
        $templateDirectory = '/tmp/templates/' . $id . '/template';
        $stdout = '';
        $stderr = '';

        try {
            if (Console::execute($github->generateCloneCommand($owner, $repository, $branch, GitHub::CLONE_TYPE_BRANCH, $repoDirectory, $rootDirectory), '', $stdout, $stderr) !== 0) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Unable to clone repository: ' . $stderr);
            }
            if (Console::execute($github->generateCloneCommand($templateOwner, $templateRepository, $templateReference, $templateType, $templateDirectory, $templateRootDirectory), '', $stdout, $stderr) !== 0) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Unable to clone template repository: ' . $stderr);
            }

            Console::execute('mkdir -p ' . \escapeshellarg($repoDirectory . '/' . $rootDirectory), '', $stdout, $stderr);
            Console::execute('mkdir -p ' . \escapeshellarg($templateDirectory . '/' . $templateRootDirectory), '', $stdout, $stderr);

            // Merge the template into the repo, then commit + push the branch.
            Console::execute('rsync -av --exclude \'.git\' ' . \escapeshellarg($templateDirectory . '/' . $templateRootDirectory . '/') . ' ' . \escapeshellarg($repoDirectory . '/' . $rootDirectory), '', $stdout, $stderr);

            $message = \escapeshellarg("Create '" . $function->getAttribute('name', '') . "' function");
            if (Console::execute('git config --global user.email ' . \escapeshellarg(APP_VCS_GITHUB_EMAIL) . ' && git config --global user.name ' . \escapeshellarg(APP_VCS_GITHUB_USERNAME) . ' && cd ' . \escapeshellarg($repoDirectory) . ' && git checkout -b ' . \escapeshellarg($branch) . ' && git add . && git commit -m ' . $message . ' && git push origin ' . \escapeshellarg($branch), '', $stdout, $stderr) !== 0) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Unable to push to repository: ' . $stderr);
            }

            if (Console::execute('cd ' . \escapeshellarg($repoDirectory) . ' && git rev-parse HEAD', '', $stdout, $stderr) !== 0) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Unable to resolve commit hash: ' . $stderr);
            }

            return \trim($stdout);
        } finally {
            Console::execute('rm -rf ' . \escapeshellarg('/tmp/templates/' . $id), '', $stdout, $stderr);
        }
    }
}
