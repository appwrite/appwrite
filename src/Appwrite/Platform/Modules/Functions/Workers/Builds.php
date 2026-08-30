<?php

namespace Appwrite\Platform\Modules\Functions\Workers;

use Appwrite\Deployment\Deployments;
use Appwrite\Deployment\GitAction;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Usage\Build as BuildUsage;
use Appwrite\Usage\Context;
use Appwrite\Vcs\Factory as VcsFactory;
use Exception;
use Utopia\Config\Config;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Exception\Restricted;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Exception\Transaction as TransactionException;
use Utopia\Database\Query;
use Utopia\Logger\Log;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git;

class Builds extends Action
{
    public static function getName(): string
    {
        return 'builds';
    }

    /**
     * Truncate build logs to the length allowed by the deployments "buildLogs"
     * attribute. Large site builds (heavy prerender/bundle output) can exceed
     * it, which makes persisting the deployment throw a Structure exception and
     * report the build as failed even though it actually succeeded. Keep the
     * tail — it holds the build result and any error — mirroring how execution
     * logs are trimmed elsewhere in this codebase.
     */
    private function truncateBuildLogs(string $logs): string
    {
        $limit = APP_LOG_LENGTH_LIMIT;
        if (\strlen($logs) <= $limit) {
            return $logs;
        }

        $warning = "[WARNING] Logs truncated. The output exceeded {$limit} characters.\n";

        return $warning . \substr($logs, -($limit - \strlen($warning)));
    }

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this
            ->desc('Builds worker')
            ->groups(['builds'])
            ->inject('message')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('queueForRealtime')
            ->inject('usage')
            ->inject('publisherForUsage')
            ->inject('vcsFactory')
            ->inject('dbForProject')
            ->inject('getIsResourceBlocked')
            ->inject('log')
            ->inject('deployments')
            ->callback($this->action(...));
    }

    /**
     * @throws \Utopia\Database\Exception
     */
    public function action(
        Message $message,
        Document $project,
        Database $dbForPlatform,
        Realtime $queueForRealtime,
        Context $usage,
        UsagePublisher $publisherForUsage,
        VcsFactory $vcsFactory,
        Database $dbForProject,
        callable $getIsResourceBlocked,
        Log $log,
        Deployments $deployments,
    ): void {
        $payload = $message->getPayload();

        if (empty($payload)) {
            throw new \Exception('Missing payload');
        }

        $type = $payload['type'] ?? '';
        Span::add('build.type', $type);

        $resource = new Document($payload['resource'] ?? []);
        $deployment = new Document($payload['deployment'] ?? []);
        $template = new Document($payload['template'] ?? []);
        $platform = $payload['platform'] ?? Config::getParam('platform', []);

        $log->addTag('projectId', $project->getId());
        $log->addTag('type', $type);

        switch ($type) {
            case BUILD_TYPE_DEPLOYMENT:
            case BUILD_TYPE_RETRY:
                $templateVcs = $vcsFactory->fromProvider('github');
                $this->buildDeployment(
                    $queueForRealtime,
                    $usage,
                    $publisherForUsage,
                    $dbForPlatform,
                    $dbForProject,
                    $templateVcs,
                    $vcsFactory,
                    $project,
                    $resource,
                    $deployment,
                    $template,
                    $getIsResourceBlocked,
                    $log,
                    $deployments,
                    $platform,
                    (int) ($payload['timeout'] ?? System::getEnv('_APP_COMPUTE_BUILD_TIMEOUT', 900))
                );
                break;

            default:
                throw new \Exception('Invalid build type');
        }
    }

    /**
     * @throws \Utopia\Database\Exception
     * @throws Exception
     */
    protected function buildDeployment(
        Realtime $queueForRealtime,
        Context $usage,
        UsagePublisher $publisherForUsage,
        Database $dbForPlatform,
        Database $dbForProject,
        Git $templateVcs,
        VcsFactory $vcsFactory,
        Document $project,
        Document $resource,
        Document $deployment,
        Document $template,
        callable $getIsResourceBlocked,
        Log $log,
        Deployments $deployments,
        array $platform,
        int $timeout
    ): void {
        Span::add('project.id', $project->getId());
        Span::add('resource.id', $resource->getId());
        Span::add('resource.type', $resource->getCollection());
        Span::add('deployment.id', $deployment->getId());
        Span::add('build.timeout', $timeout);

        $startTime = DateTime::now();
        $durationStart = \microtime(true);
        $phaseStart = $durationStart;

        $resourceKey = match ($resource->getCollection()) {
            'functions' => 'functionId',
            'sites' => 'siteId',
            default => throw new \Exception('Invalid resource type')
        };

        $log->addTag($resourceKey, $resource->getId());

        $resource = $dbForProject->getDocument($resource->getCollection(), $resource->getId());
        if ($resource->isEmpty()) {
            throw new \Exception('Resource not found');
        }

        if ($getIsResourceBlocked($project, $resource->getCollection() === 'functions' ? RESOURCE_TYPE_FUNCTIONS : RESOURCE_TYPE_SITES, $resource->getId())) {
            throw new BuildException('Resource is blocked');
        }

        $log->addTag('deploymentId', $deployment->getId());

        $deployment = $dbForProject->getDocument('deployments', $deployment->getId());
        if ($deployment->isEmpty()) {
            throw new \Exception('Deployment not found');
        }

        if ($resource->getCollection() === 'functions' && empty($deployment->getAttribute('entrypoint', ''))) {
            throw new BuildException('Entrypoint for your Appwrite Function is missing. Please specify it when making deployment or update the entrypoint under your function\'s "Settings" > "Configuration" > "Entrypoint".');
        }

        $version = $this->getVersion($resource);
        Span::add('build.runtime', $resource->getAttribute($resource->getCollection() === 'sites' ? 'buildRuntime' : 'runtime', ''));
        Span::add('build.version', $version);

        $spec = Config::getParam('specifications')[$resource->getAttribute('buildSpecification', APP_COMPUTE_SPECIFICATION_DEFAULT)];
        Span::add('build.cpus', (float) ($spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT));
        Span::add('build.memory', (int) ($spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT));

        // Realtime preparation
        $event = "{$resource->getCollection()}.[{$resourceKey}].deployments.[deploymentId].update";
        $queueForRealtime
            ->setSubscribers(['console'])
            ->setProject($project)
            ->setEvent($event)
            ->setParam($resourceKey, $resource->getId())
            ->setParam('deploymentId', $deployment->getId());

        if ($deployment->getAttribute('status') === 'canceled') {
            $resource = $this->updateLatestDeployment($dbForProject, $resource);
            $this->finalizeCanceledDeployment($deployment->getId(), $dbForProject, $queueForRealtime);

            return;
        }

        $deploymentId = $deployment->getId();

        $updated = $dbForProject->updateDocuments('deployments', new Document([
            'buildStartedAt' => $startTime,
            'status' => 'processing',
        ]), [
            Query::equal('$id', [$deploymentId]),
            Query::notEqual('status', 'canceled'),
        ]);

        if ($updated === 0) {
            $resource = $this->updateLatestDeployment($dbForProject, $resource);
            $this->finalizeCanceledDeployment($deploymentId, $dbForProject, $queueForRealtime);
            return;
        }

        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        $resource = $this->updateLatestDeployment($dbForProject, $resource);

        Span::add('deployment.status', 'processing');

        $queueForRealtime
            ->setPayload($deployment->getArrayCopy())
            ->trigger();

        $installationId = $deployment->getAttribute('installationId', '');
        $providerRepositoryId = $deployment->getAttribute('providerRepositoryId', '');
        $providerCommitHash = $deployment->getAttribute('providerCommitHash', '');
        $owner = '';
        $repositoryName = '';

        // Every other build flow submits its job straight from the request, so a
        // deployment without a repository to push the template into never
        // belongs here.
        if (empty($providerRepositoryId)) {
            throw new \Exception('Only template-into-repository deployments build through this worker');
        }

        $installation = $dbForPlatform->getDocument('installations', $installationId);
        $providerInstallationId = $installation->getAttribute('providerInstallationId');

        try {
            $providerAdapter = $vcsFactory->fromInstallation($installation);
        } catch (\Exception $e) {
            if ($e->getCode() === 404
                && $resource->getAttribute('installationId', '') === $installationId) {
                $this->disconnectVcs($resource, $dbForProject, $dbForPlatform);
            }
            throw $e;
        }

        Span::add('timings.setup', \round(\microtime(true) - $phaseStart, 3));
        $phaseStart = \microtime(true);

        try {
            // VCS and VCS+Temaplte
            $tmpDirectory = '/tmp/builds/' . $deploymentId . '/code';
            $rootDirectory = $resource->getAttribute('providerRootDirectory', '');
            $rootDirectory = \rtrim($rootDirectory, '/');
            $rootDirectory = \ltrim($rootDirectory, '.');
            $rootDirectory = \ltrim($rootDirectory, '/');

            $owner = $providerAdapter->getOwnerName($providerInstallationId);
            $repositoryName = $providerAdapter->getRepositoryName($providerRepositoryId);

            $cloneOwner = $deployment->getAttribute('providerRepositoryOwner', $owner);
            $cloneRepository = $deployment->getAttribute('providerRepositoryName', $repositoryName);

            $branchName = $deployment->getAttribute('providerBranch');
            $commitHash = $deployment->getAttribute('providerCommitHash', '');

            $cloneVersion = $branchName;
            $cloneType = Git::CLONE_TYPE_BRANCH;
            if (! empty($commitHash)) {
                $cloneVersion = $commitHash;
                $cloneType = Git::CLONE_TYPE_COMMIT;
            }

            $gitCloneCommand = $providerAdapter->generateCloneCommand($cloneOwner, $cloneRepository, $cloneVersion, $cloneType, $tmpDirectory, $rootDirectory);
            $stdout = '';
            $stderr = '';

            Console::execute('mkdir -p ' . \escapeshellarg('/tmp/builds/' . $deploymentId), '', $stdout, $stderr);

            if ($dbForProject->getDocument('deployments', $deploymentId)->getAttribute('status') === 'canceled') {
                $this->finalizeCanceledDeployment($deployment->getId(), $dbForProject, $queueForRealtime);

                return;
            }

            $exit = Console::execute($gitCloneCommand, '', $stdout, $stderr);

            if ($exit !== 0) {
                throw new BuildException('Unable to clone code repository: ' . $stderr);
            }

            // Local refactoring for function folder with spaces
            if (str_contains($rootDirectory, ' ')) {
                $rootDirectoryWithoutSpaces = str_replace(' ', '', $rootDirectory);
                $from = $tmpDirectory . '/' . $rootDirectory;
                $to = $tmpDirectory . '/' . $rootDirectoryWithoutSpaces;
                $exit = Console::execute('mv ' . \escapeshellarg($from) . ' ' . \escapeshellarg($to), '', $stdout, $stderr);

                if ($exit !== 0) {
                    throw new \Exception('Unable to move function with spaces' . $stderr);
                }
                $rootDirectory = $rootDirectoryWithoutSpaces;
            }

            // Build from template
            $templateRepositoryName = $template->getAttribute('repositoryName', '');
            $templateOwnerName = $template->getAttribute('ownerName', '');
            $templateReferenceType = $template->getAttribute('referenceType', '');
            $templateReferenceValue = $template->getAttribute('referenceValue', '');

            $templateRootDirectory = $template->getAttribute('rootDirectory', '');
            $templateRootDirectory = \rtrim($templateRootDirectory, '/');
            $templateRootDirectory = \ltrim($templateRootDirectory, '.');
            $templateRootDirectory = \ltrim($templateRootDirectory, '/');

            if (! empty($templateRepositoryName) && ! empty($templateOwnerName) && ! empty($templateReferenceType) && ! empty($templateReferenceValue)) {
                // Clone template repo
                $tmpTemplateDirectory = '/tmp/builds/' . $deploymentId . '/template';

                $gitCloneCommandForTemplate = $templateVcs->generateCloneCommand($templateOwnerName, $templateRepositoryName, $templateReferenceValue, $templateReferenceType, $tmpTemplateDirectory, $templateRootDirectory);
                $exit = Console::execute($gitCloneCommandForTemplate, '', $stdout, $stderr);

                if ($exit !== 0) {
                    throw new \Exception('Unable to clone code repository: ' . $stderr);
                }

                // Ensure directories
                Console::execute('mkdir -p ' . \escapeshellarg($tmpTemplateDirectory . '/' . $templateRootDirectory), '', $stdout, $stderr);
                Console::execute('mkdir -p ' . \escapeshellarg($tmpDirectory . '/' . $rootDirectory), '', $stdout, $stderr);

                // Merge template into user repo
                Console::execute('rsync -av --exclude \'.git\' ' . \escapeshellarg($tmpTemplateDirectory . '/' . $templateRootDirectory . '/') . ' ' . \escapeshellarg($tmpDirectory . '/' . $rootDirectory), '', $stdout, $stderr);

                // Commit and push
                $commitMessage = \escapeshellarg('Create ' . $resource->getAttribute('name', '') . ' function');
                $exit = Console::execute('git config --global user.email ' . \escapeshellarg(APP_VCS_GITHUB_EMAIL) . ' && git config --global user.name ' . \escapeshellarg(APP_VCS_GITHUB_USERNAME) . ' && cd ' . \escapeshellarg($tmpDirectory) . ' && git checkout -b ' . \escapeshellarg($branchName) . ' && git add . && git commit -m ' . $commitMessage . ' && git push origin ' . \escapeshellarg($branchName), '', $stdout, $stderr);

                if ($exit !== 0) {
                    throw new \Exception('Unable to push code repository: ' . $stderr);
                }

                $exit = Console::execute('cd ' . \escapeshellarg($tmpDirectory) . ' && git rev-parse HEAD', '', $stdout, $stderr);

                if ($exit !== 0) {
                    throw new \Exception('Unable to get vcs commit SHA: ' . $stderr);
                }

                $providerCommitHash = \trim($stdout);

                $deployment->setAttribute('providerCommitHash', $providerCommitHash);
                $deployment->setAttribute('providerCommitAuthorUrl', APP_VCS_GITHUB_URL);
                $deployment->setAttribute('providerCommitAuthor', APP_VCS_GITHUB_USERNAME);
                $deployment->setAttribute('providerCommitMessage', "Create '" . $resource->getAttribute('name', '') . "' function");
                $deployment->setAttribute('providerCommitUrl', $providerAdapter->getCommitUrl($cloneOwner, $cloneRepository, $providerCommitHash));
                $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), new Document([
                    'providerCommitHash' => $deployment->getAttribute('providerCommitHash'),
                    'providerCommitAuthorUrl' => $deployment->getAttribute('providerCommitAuthorUrl'),
                    'providerCommitAuthor' => $deployment->getAttribute('providerCommitAuthor'),
                    'providerCommitMessage' => $deployment->getAttribute('providerCommitMessage'),
                    'providerCommitUrl' => $deployment->getAttribute('providerCommitUrl'),
                ]));

                $queueForRealtime
                    ->setPayload($deployment->getArrayCopy())
                    ->trigger();
            }

            // The only build reaching this worker is the template-into-repo push
            // above (a git *write* the jobs-service artifact system has no
            // primitive for). With the commit pushed, hand the build to the
            // jobs-service like any other VCS commit, via the same Deployments
            // service the HTTP endpoints use.
            $ref = $deployment->getAttribute('providerCommitHash') ?: $branchName;
            $deployments->createFromVcs(
                $resource,
                $deployment,
                $providerAdapter,
                $cloneOwner,
                $cloneRepository,
                $ref,
                $resource->getAttribute('providerRootDirectory', ''),
            );

            Console::execute('rm -rf ' . \escapeshellarg('/tmp/builds/' . $deploymentId), '', $stdout, $stderr);
        } catch (\Throwable $th) {
            if ($dbForProject->getDocument('deployments', $deploymentId)->getAttribute('status') === 'canceled') {
                $this->finalizeCanceledDeployment($deployment->getId(), $dbForProject, $queueForRealtime);

                return;
            }

            $isUserFacing = $th instanceof BuildException;
            $message = $isUserFacing
                ? $th->getMessage()
                : 'An internal error occurred while building. Please try again, and contact support if the problem persists.';

            // Record user-facing failures on the span here, since they're not
            // re-raised to the harness (which records internal errors via setError).
            if ($isUserFacing) {
                Span::add('build.exception.type', $th->getType());
                Span::add('build.exception.message', $th->getMessage());
            }

            // Color message red
            if (! \str_contains($message, '')) {
                $message = '[31m' . $message;
            }

            $message = \str_replace('{APPWRITE_DETECTION_SEPARATOR_START}', '', $message);
            $message = \str_replace('{APPWRITE_DETECTION_SEPARATOR_END}', '', $message);

            // Append error to whatever build logs were already streamed
            $deployment = $dbForProject->getDocument('deployments', $deploymentId);
            $previousLogs = $deployment->getAttribute('buildLogs', '');
            if (! empty($previousLogs)) {
                $message = $previousLogs . "\n" . $message;
            }

            $endTime = DateTime::now();
            $durationEnd = \microtime(true);
            $deployment->setAttribute('buildEndedAt', $endTime);
            $deployment->setAttribute('buildDuration', \intval(\ceil($durationEnd - $durationStart)));
            $deployment->setAttribute('status', 'failed');
            Span::add('deployment.status', 'failed');
            Span::add('build.duration', $deployment->getAttribute('buildDuration'));

            $deployment->setAttribute('buildLogs', $this->truncateBuildLogs($message));
            $deployment = $dbForProject->updateDocument('deployments', $deploymentId, new Document([
                'buildEndedAt' => $deployment->getAttribute('buildEndedAt'),
                'buildDuration' => $deployment->getAttribute('buildDuration'),
                'status' => 'failed',
                'buildLogs' => $this->truncateBuildLogs($message),
            ]));

            $resource = $this->updateLatestDeployment($dbForProject, $resource);

            $queueForRealtime
                ->setPayload($deployment->getArrayCopy())
                ->trigger();

            $this->runGitAction('failed', $providerAdapter, $providerCommitHash, $owner, $repositoryName, $project, $resource, $deployment->getId(), $dbForProject, $dbForPlatform, $queueForRealtime, $platform, true);

            // Let the worker harness record internal errors via the span and logger.
            if (! $isUserFacing) {
                throw $th;
            }
        } finally {
            $queueForRealtime
                ->setPayload($deployment->getArrayCopy())
                ->trigger();

            $this->sendUsage(
                resource: $resource,
                deployment: $deployment,
                project: $project,
                usage: $usage,
                publisherForUsage: $publisherForUsage
            );
        }
    }

    protected function sendUsage(Document $resource, Document $deployment, Document $project, Context $usage, UsagePublisher $publisherForUsage): void
    {
        BuildUsage::publish($usage, $resource, $deployment, $project, $publisherForUsage);
    }

    protected function getVersion(Document $resource): string
    {
        return match ($resource->getCollection()) {
            'functions' => $resource->getAttribute('version', 'v2'),
            'sites' => 'v5',
            default => throw new \Exception('Unsupported resource type "' . $resource->getCollection() . '".'),
        };
    }

    /**
     * @throws Structure
     * @throws \Utopia\Database\Exception
     * @throws Conflict
     * @throws Restricted
     */
    protected function runGitAction(
        string $status,
        Git $vcs,
        string $providerCommitHash,
        string $owner,
        string $repositoryName,
        Document $project,
        Document $resource,
        string $deploymentId,
        Database $dbForProject,
        Database $dbForPlatform,
        Realtime $queueForRealtime,
        array $platform,
        bool $secondaryError = false
    ): void {
        $deployment = new Document();

        try {
            $deployment = $dbForProject->getDocument('deployments', $deploymentId);

            GitAction::run($status, $vcs, $providerCommitHash, $owner, $repositoryName, $project, $resource, $deployment, $dbForPlatform, $platform);
        } catch (\Throwable $th) {
            $span = Span::current();
            $errorPrefix = $secondaryError ? 'build.error.secondary' : 'build.git_action.error';
            $span?->set("{$errorPrefix}.stage", 'git_action');
            $span?->set("{$errorPrefix}.status", $status);
            $span?->set("{$errorPrefix}.type", $th::class);
            $span?->set("{$errorPrefix}.message", $th->getMessage());
            $span?->set("{$errorPrefix}.file", $th->getFile());
            $span?->set("{$errorPrefix}.line", $th->getLine());

            $logs = $deployment->getAttribute('buildLogs', '');
            $date = \date('H:i:s');
            $logs .= "[90m[$date] [90m[[0mappwrite[90m][33m Git action failed. Deployment will continue. [0m\n";

            $deployment->setAttribute('buildLogs', $this->truncateBuildLogs($logs));
            $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), new Document([
                'buildLogs' => $deployment->getAttribute('buildLogs'),
            ]));

            $queueForRealtime
                ->setPayload($deployment->getArrayCopy())
                ->trigger();
        }
    }

    protected function disconnectVcs(Document $resource, Database $dbForProject, Database $dbForPlatform): void
    {
        $repositoryId = $resource->getAttribute('repositoryId', '');
        if (!empty($repositoryId)) {
            $dbForPlatform->deleteDocument('repositories', $repositoryId);
        }
        $dbForProject->updateDocument($resource->getCollection(), $resource->getId(), new Document([
            'installationId' => '',
            'installationInternalId' => '',
            'providerRepositoryId' => '',
            'providerBranch' => '',
            'providerSilentMode' => false,
            'providerRootDirectory' => '',
            'repositoryId' => '',
            'repositoryInternalId' => '',
        ]));
    }

    private function updateLatestDeployment(Database $dbForProject, Document $resource): Document
    {
        $latestDeployment = $dbForProject->findOne('deployments', [
            Query::equal('resourceType', [$resource->getCollection()]),
            Query::equal('resourceInternalId', [$resource->getSequence()]),
            Query::orderDesc('$createdAt'),
        ]);

        $updates = $latestDeployment->isEmpty()
            ? [
                'latestDeploymentCreatedAt' => '',
                'latestDeploymentInternalId' => '',
                'latestDeploymentId' => '',
                'latestDeploymentStatus' => '',
            ]
            : [
                'latestDeploymentCreatedAt' => $latestDeployment->getCreatedAt(),
                'latestDeploymentInternalId' => $latestDeployment->getSequence(),
                'latestDeploymentId' => $latestDeployment->getId(),
                'latestDeploymentStatus' => $latestDeployment->getAttribute('status', ''),
            ];

        return $dbForProject->updateDocument(
            $resource->getCollection(),
            $resource->getId(),
            new Document($updates)
        );
    }

    private function finalizeCanceledDeployment(string $deploymentId, Database $dbForProject, Realtime $queueForRealtime)
    {
        Span::add('deployment.status', 'canceled');

        $attempts = 0;

        while (true) {
            try {
                $deployment = $dbForProject->getDocument('deployments', $deploymentId);

                $logs = $deployment->getAttribute('buildLogs', '');
                $date = \date('H:i:s');
                $logs .= "\033[90m[$date] \033[90m[\033[0mappwrite\033[90m]\033[33m Build has been canceled. \033[0m\n";

                $deployment->setAttribute('buildLogs', $this->truncateBuildLogs($logs));
                $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), new Document([
                    'buildLogs' => $deployment->getAttribute('buildLogs'),
                ]));

                break;
            } catch (TransactionException $exception) {
                if (++$attempts >= 5) {
                    throw $exception;
                }
            }
        }

        $queueForRealtime
            ->setPayload($deployment->getArrayCopy())
            ->trigger();
    }
}
