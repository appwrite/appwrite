<?php

namespace Appwrite\Platform\Modules\Functions\Workers;

use Ahc\Jwt\JWT;
use Appwrite\Event\Event;
use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Message\Usage as UsageMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Publisher\Screenshot;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Filter\BranchDomain as BranchDomainFilter;
use Appwrite\Usage\Context;
use Appwrite\Utopia\Response\Model\Deployment;
use Appwrite\Vcs\Comment;
use Exception;
use Executor\Exception as ExecutorException;
use Executor\Exception\Timeout as ExecutorTimeout;
use Executor\Executor;
use Swoole\Coroutine as Co;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Exception\Restricted;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Exception\Transaction as TransactionException;
use Utopia\Database\Query;
use Utopia\Detector\Detection\Rendering\SSR;
use Utopia\Detector\Detection\Rendering\XStatic;
use Utopia\Detector\Detector\Rendering;
use Utopia\Logger\Log;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Span\Span;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git\GitHub;

class Builds extends Action
{
    public static function getName(): string
    {
        return 'builds';
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
            ->inject('queueForEvents')
            ->inject('publisherForScreenshots')
            ->inject('queueForWebhooks')
            ->inject('publisherForFunctions')
            ->inject('queueForRealtime')
            ->inject('usage')
            ->inject('publisherForUsage')
            ->inject('cache')
            ->inject('dbForProject')
            ->inject('deviceForFunctions')
            ->inject('deviceForSites')
            ->inject('isResourceBlocked')
            ->inject('deviceForFiles')
            ->inject('log')
            ->inject('executor')
            ->inject('plan')
            ->callback($this->action(...));
    }

    /**
     * @throws \Utopia\Database\Exception
     */
    public function action(
        Message $message,
        Document $project,
        Database $dbForPlatform,
        Event $queueForEvents,
        Screenshot $publisherForScreenshots,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Realtime $queueForRealtime,
        Context $usage,
        UsagePublisher $publisherForUsage,
        Cache $cache,
        Database $dbForProject,
        Device $deviceForFunctions,
        Device $deviceForSites,
        callable $isResourceBlocked,
        Device $deviceForFiles,
        Log $log,
        Executor $executor,
        array $plan
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
                $github = new GitHub($cache);
                $this->buildDeployment(
                    $deviceForFunctions,
                    $deviceForSites,
                    $deviceForFiles,
                    $publisherForScreenshots,
                    $queueForWebhooks,
                    $publisherForFunctions,
                    $queueForRealtime,
                    $queueForEvents,
                    $usage,
                    $publisherForUsage,
                    $dbForPlatform,
                    $dbForProject,
                    $github,
                    $project,
                    $resource,
                    $deployment,
                    $template,
                    $isResourceBlocked,
                    $log,
                    $executor,
                    $plan,
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
        Device $deviceForFunctions,
        Device $deviceForSites,
        Device $deviceForFiles,
        Screenshot $publisherForScreenshots,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Context $usage,
        UsagePublisher $publisherForUsage,
        Database $dbForPlatform,
        Database $dbForProject,
        GitHub $github,
        Document $project,
        Document $resource,
        Document $deployment,
        Document $template,
        callable $isResourceBlocked,
        Log $log,
        Executor $executor,
        array $plan,
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

        $device = match ($resource->getCollection()) {
            'sites' => $deviceForSites,
            'functions' => $deviceForFunctions,
        };

        $log->addTag($resourceKey, $resource->getId());

        $resource = $dbForProject->getDocument($resource->getCollection(), $resource->getId());
        if ($resource->isEmpty()) {
            throw new \Exception('Resource not found');
        }

        if ($isResourceBlocked($project, $resource->getCollection() === 'functions' ? RESOURCE_TYPE_FUNCTIONS : RESOURCE_TYPE_SITES, $resource->getId())) {
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
        $runtime = $this->getRuntime($resource, $version);
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

        $source = $deployment->getAttribute('sourcePath', '');
        $installationId = $deployment->getAttribute('installationId', '');
        $providerRepositoryId = $deployment->getAttribute('providerRepositoryId', '');
        $providerCommitHash = $deployment->getAttribute('providerCommitHash', '');
        $isVcsEnabled = ! empty($providerRepositoryId);
        $owner = '';
        $repositoryName = '';

        if ($isVcsEnabled) {
            $installation = $dbForPlatform->getDocument('installations', $installationId);
            $providerInstallationId = $installation->getAttribute('providerInstallationId');
            $privateKey = System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
            $githubAppId = System::getEnv('_APP_VCS_GITHUB_APP_ID');

            try {
                $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);
            } catch (\Exception $e) {
                if ($e->getCode() === 404
                    && $resource->getAttribute('installationId', '') === $installationId) {
                    $this->disconnectVcs($resource, $dbForProject, $dbForPlatform);
                }
                throw $e;
            }
        }

        Span::add('timings.setup', \round(\microtime(true) - $phaseStart, 3));
        $phaseStart = \microtime(true);

        try {
            if (! $isVcsEnabled) {
                // Non-VCS + Template
                $templateRepositoryName = $template->getAttribute('repositoryName', '');
                $templateOwnerName = $template->getAttribute('ownerName', '');
                $templateReferenceType = $template->getAttribute('referenceType', '');
                $templateReferenceValue = $template->getAttribute('referenceValue', '');

                $templateRootDirectory = $template->getAttribute('rootDirectory', '');
                $templateRootDirectory = \rtrim($templateRootDirectory, '/');
                $templateRootDirectory = \ltrim($templateRootDirectory, '.');
                $templateRootDirectory = \ltrim($templateRootDirectory, '/');

                if (! empty($templateRepositoryName) && ! empty($templateOwnerName) && ! empty($templateReferenceType) && ! empty($templateReferenceValue)) {
                    $stdout = '';
                    $stderr = '';

                    // Clone template repo
                    $tmpTemplateDirectory = '/tmp/builds/' . $deploymentId . '-template';

                    $gitCloneCommandForTemplate = $github->generateCloneCommand($templateOwnerName, $templateRepositoryName, $templateReferenceValue, $templateReferenceType, $tmpTemplateDirectory, $templateRootDirectory);

                    $exit = Console::execute($gitCloneCommandForTemplate, '', $stdout, $stderr);

                    if ($exit !== 0) {
                        throw new \Exception('Unable to clone code repository: ' . $stderr);
                    }

                    Console::execute('find ' . \escapeshellarg($tmpTemplateDirectory) . ' -type d -name ".git" -exec rm -rf {} +', '', $stdout, $stderr);

                    // Ensure directories
                    Console::execute('mkdir -p ' . \escapeshellarg($tmpTemplateDirectory . '/' . $templateRootDirectory), '', $stdout, $stderr);

                    $tmpPathFile = $tmpTemplateDirectory . '/code.tar.gz';

                    $localDevice = new Local();

                    if (substr($tmpTemplateDirectory, -1) !== '/') {
                        $tmpTemplateDirectory .= '/';
                    }

                    $tarParamDirectory = \escapeshellarg($tmpTemplateDirectory . (empty($templateRootDirectory) ? '' : '/' . $templateRootDirectory));
                    Console::execute('tar --exclude code.tar.gz -czf ' . \escapeshellarg($tmpPathFile) . ' -C ' . \escapeshellcmd($tarParamDirectory) . ' .', '', $stdout, $stderr); // TODO: Replace escapeshellcmd with escapeshellarg if we find a way that doesnt break syntax

                    $source = $device->getPath($deployment->getId() . '.' . \pathinfo('code.tar.gz', PATHINFO_EXTENSION));
                    $result = $localDevice->transfer($tmpPathFile, $source, $device);

                    if (! $result) {
                        throw new \Exception('Unable to move file');
                    }

                    Console::execute('rm -rf ' . \escapeshellarg($tmpTemplateDirectory), '', $stdout, $stderr);

                    $directorySize = $device->getFileSize($source);
                    $deployment
                        ->setAttribute('sourcePath', $source)
                        ->setAttribute('sourceSize', $directorySize)
                        ->setAttribute('totalSize', $directorySize);
                    $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), new Document([
                        'sourcePath' => $deployment->getAttribute('sourcePath'),
                        'sourceSize' => $deployment->getAttribute('sourceSize'),
                        'totalSize' => $deployment->getAttribute('totalSize'),
                    ]));

                    $queueForRealtime
                        ->setPayload($deployment->getArrayCopy())
                        ->trigger();

                    Span::add('build.source_size', $deployment->getAttribute('sourceSize'));
                }
            } elseif ($isVcsEnabled) {
                // VCS and VCS+Temaplte
                $tmpDirectory = '/tmp/builds/' . $deploymentId . '/code';
                $rootDirectory = $resource->getAttribute('providerRootDirectory', '');
                $rootDirectory = \rtrim($rootDirectory, '/');
                $rootDirectory = \ltrim($rootDirectory, '.');
                $rootDirectory = \ltrim($rootDirectory, '/');

                $owner = $github->getOwnerName($providerInstallationId);
                $repositoryName = $github->getRepositoryName($providerRepositoryId);

                $cloneOwner = $deployment->getAttribute('providerRepositoryOwner', $owner);
                $cloneRepository = $deployment->getAttribute('providerRepositoryName', $repositoryName);

                $branchName = $deployment->getAttribute('providerBranch');
                $commitHash = $deployment->getAttribute('providerCommitHash', '');

                $cloneVersion = $branchName;
                $cloneType = GitHub::CLONE_TYPE_BRANCH;
                if (! empty($commitHash)) {
                    $cloneVersion = $commitHash;
                    $cloneType = GitHub::CLONE_TYPE_COMMIT;
                }

                $gitCloneCommand = $github->generateCloneCommand($cloneOwner, $cloneRepository, $cloneVersion, $cloneType, $tmpDirectory, $rootDirectory);
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

                    $gitCloneCommandForTemplate = $github->generateCloneCommand($templateOwnerName, $templateRepositoryName, $templateReferenceValue, $templateReferenceType, $tmpTemplateDirectory, $templateRootDirectory);
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
                    $deployment->setAttribute('providerCommitUrl', "https://github.com/$cloneOwner/$cloneRepository/commit/$providerCommitHash");
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

                $tmpPath = '/tmp/builds/' . $deploymentId;
                $tmpPathFile = $tmpPath . '/code.tar.gz';
                $localDevice = new Local();

                if (substr($tmpDirectory, -1) !== '/') {
                    $tmpDirectory .= '/';
                }

                $directorySize = $localDevice->getDirectorySize($tmpDirectory);
                $sizeLimit = (int) System::getEnv('_APP_COMPUTE_SIZE_LIMIT', '30000000');

                if (isset($plan['deploymentSize'])) {
                    $sizeLimit = (int) $plan['deploymentSize'] * 1000 * 1000;
                }

                if ($directorySize > $sizeLimit && $sizeLimit !== 0) {
                    throw new BuildException('Repository directory size should be less than ' . number_format($sizeLimit / (1000 * 1000), 2) . ' MBs.');
                }

                Console::execute('find ' . \escapeshellarg($tmpDirectory) . ' -type d -name ".git" -exec rm -rf {} +', '', $stdout, $stderr);

                $tarParamDirectory = '/tmp/builds/' . $deploymentId . '/code' . (empty($rootDirectory) ? '' : '/' . $rootDirectory);
                Console::execute('tar --exclude code.tar.gz -czf ' . \escapeshellarg($tmpPathFile) . ' -C ' . \escapeshellcmd($tarParamDirectory) . ' .', '', $stdout, $stderr); // TODO: Replace escapeshellcmd with escapeshellarg if we find a way that doesnt break syntax

                $source = $device->getPath($deployment->getId() . '.' . \pathinfo('code.tar.gz', PATHINFO_EXTENSION));
                $result = $localDevice->transfer($tmpPathFile, $source, $device);

                if (! $result) {
                    throw new \Exception('Unable to move file');
                }

                Console::execute('rm -rf ' . \escapeshellarg($tmpPath), '', $stdout, $stderr);

                $directorySize = $device->getFileSize($source);

                $deployment
                    ->setAttribute('sourcePath', $source)
                    ->setAttribute('sourceSize', $directorySize)
                    ->setAttribute('totalSize', $directorySize);
                $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), new Document([
                    'sourcePath' => $deployment->getAttribute('sourcePath'),
                    'sourceSize' => $deployment->getAttribute('sourceSize'),
                    'totalSize' => $deployment->getAttribute('totalSize'),
                ]));

                $queueForRealtime
                    ->setPayload($deployment->getArrayCopy())
                    ->trigger();

                Span::add('build.source_size', $deployment->getAttribute('sourceSize'));

                $this->runGitAction('processing', $github, $providerCommitHash, $owner, $repositoryName, $project, $resource, $deployment->getId(), $dbForProject, $dbForPlatform, $queueForRealtime, $platform);
            }

            Span::add('timings.source', \round(\microtime(true) - $phaseStart, 3));
            $phaseStart = \microtime(true);

            /** Request the executor to build the code... */
            $updated = $dbForProject->updateDocuments('deployments', new Document([
                'status' => 'building',
            ]), [
                Query::equal('$id', [$deploymentId]),
                Query::notEqual('status', 'canceled'),
            ]);

            if ($updated === 0) {
                $this->finalizeCanceledDeployment($deploymentId, $dbForProject, $queueForRealtime);
                return;
            }

            $deployment = $dbForProject->getDocument('deployments', $deploymentId);
            Span::add('deployment.status', 'building');

            $resource = $this->updateLatestDeployment($dbForProject, $resource);

            $queueForRealtime
                ->setPayload($deployment->getArrayCopy())
                ->trigger();

            if ($isVcsEnabled) {
                $this->runGitAction('building', $github, $providerCommitHash, $owner, $repositoryName, $project, $resource, $deployment->getId(), $dbForProject, $dbForPlatform, $queueForRealtime, $platform);
            }

            $deploymentModel = new Deployment();
            $deploymentUpdate =
                $queueForEvents
                    ->setProject($project)
                    ->setEvent("{$resource->getCollection()}.[{$resourceKey}].deployments.[deploymentId].update")
                    ->setParam($resourceKey, $resource->getId())
                    ->setParam('deploymentId', $deployment->getId())
                    ->setPayload($deployment->getArrayCopy(array_keys($deploymentModel->getRules())));

            /** Trigger Webhook */
            $queueForWebhooks
                ->from($deploymentUpdate)
                ->trigger();

            /** Trigger Functions */
            $publisherForFunctions->enqueue(FunctionMessage::fromEvent(
                event: $deploymentUpdate->getEvent(),
                params: $deploymentUpdate->getParams(),
                project: $deploymentUpdate->getProject(),
                user: $deploymentUpdate->getUser(),
                userId: $deploymentUpdate->getUserId(),
                payload: $deploymentUpdate->getPayload(),
                platform: $deploymentUpdate->getPlatform(),
            ));

            /** Trigger Realtime Event */
            $queueForRealtime
                ->setPayload($deployment->getArrayCopy())
                ->trigger();

            $vars = [];

            // Shared vars
            foreach ($resource->getAttribute('varsProject', []) as $var) {
                $vars[$var->getAttribute('key')] = $var->getAttribute('value', '');
            }

            // Function vars
            foreach ($resource->getAttribute('vars', []) as $var) {
                $vars[$var->getAttribute('key')] = $var->getAttribute('value', '');
            }

            // Some runtimes/frameworks can't compile with less memory than this
            $minMemory = $resource->getCollection() === 'sites' ? 2048 : 1024;

            if (
                $resource->getAttribute('framework', '') === 'analog' ||
                $resource->getAttribute('framework', '') === 'tanstack-start'
            ) {
                $minMemory = 4096;
            }

            $cpus = $spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT;
            $memory = max($spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT, $minMemory);
            $jwtObj = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', $timeout, 0);

            $apiKey = $jwtObj->encode([
                'projectId' => $project->getId(),
                'scopes' => $resource->getAttribute('scopes', []),
            ]);

            // Appwrite vars
            $vars = \array_merge($vars, [
                'APPWRITE_VERSION' => APP_VERSION_STABLE,
                'APPWRITE_REGION' => $project->getAttribute('region'),
                'APPWRITE_DEPLOYMENT_TYPE' => $deployment->getAttribute('type', ''),
                'APPWRITE_VCS_REPOSITORY_ID' => $deployment->getAttribute('providerRepositoryId', ''),
                'APPWRITE_VCS_REPOSITORY_NAME' => $deployment->getAttribute('providerRepositoryName', ''),
                'APPWRITE_VCS_REPOSITORY_OWNER' => $deployment->getAttribute('providerRepositoryOwner', ''),
                'APPWRITE_VCS_REPOSITORY_URL' => $deployment->getAttribute('providerRepositoryUrl', ''),
                'APPWRITE_VCS_REPOSITORY_BRANCH' => $deployment->getAttribute('providerBranch', ''),
                'APPWRITE_VCS_REPOSITORY_BRANCH_URL' => $deployment->getAttribute('providerBranchUrl', ''),
                'APPWRITE_VCS_COMMIT_HASH' => $deployment->getAttribute('providerCommitHash', ''),
                'APPWRITE_VCS_COMMIT_MESSAGE' => $deployment->getAttribute('providerCommitMessage', ''),
                'APPWRITE_VCS_COMMIT_URL' => $deployment->getAttribute('providerCommitUrl', ''),
                'APPWRITE_VCS_COMMIT_AUTHOR_NAME' => $deployment->getAttribute('providerCommitAuthor', ''),
                'APPWRITE_VCS_COMMIT_AUTHOR_URL' => $deployment->getAttribute('providerCommitAuthorUrl', ''),
                'APPWRITE_VCS_ROOT_DIRECTORY' => $deployment->getAttribute('providerRootDirectory', ''),
            ]);

            $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') == 'disabled' ? 'http' : 'https';
            $endpoint = "$protocol://{$platform['apiHostname']}/v1";

            switch ($resource->getCollection()) {
                case 'functions':
                    $vars = [
                        ...$vars,
                        'APPWRITE_FUNCTION_API_ENDPOINT' => $endpoint,
                        'APPWRITE_FUNCTION_API_KEY' => API_KEY_EPHEMERAL . '_' . $apiKey,
                        'APPWRITE_FUNCTION_ID' => $resource->getId(),
                        'APPWRITE_FUNCTION_NAME' => $resource->getAttribute('name'),
                        'APPWRITE_FUNCTION_DEPLOYMENT' => $deployment->getId(),
                        'APPWRITE_FUNCTION_PROJECT_ID' => $project->getId(),
                        'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'] ?? '',
                        'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'] ?? '',
                        'APPWRITE_FUNCTION_CPUS' => $cpus,
                        'APPWRITE_FUNCTION_MEMORY' => $memory,
                        'OPEN_RUNTIMES_NFT' => System::getEnv('_APP_OPEN_RUNTIMES_NFT', 'enabled'),
                    ];
                    break;
                case 'sites':
                    $vars = [
                        ...$vars,
                        'APPWRITE_SITE_API_ENDPOINT' => $endpoint,
                        'APPWRITE_SITE_API_KEY' => API_KEY_EPHEMERAL . '_' . $apiKey,
                        'APPWRITE_SITE_ID' => $resource->getId(),
                        'APPWRITE_SITE_NAME' => $resource->getAttribute('name'),
                        'APPWRITE_SITE_DEPLOYMENT' => $deployment->getId(),
                        'APPWRITE_SITE_PROJECT_ID' => $project->getId(),
                        'APPWRITE_SITE_RUNTIME_NAME' => $runtime['name'] ?? '',
                        'APPWRITE_SITE_RUNTIME_VERSION' => $runtime['version'] ?? '',
                        'APPWRITE_SITE_CPUS' => $cpus,
                        'APPWRITE_SITE_MEMORY' => $memory,
                        'OPEN_RUNTIMES_NFT' => System::getEnv('_APP_OPEN_RUNTIMES_NFT', 'enabled'),
                    ];
                    break;
            }

            $command = $this->getCommand(
                resource: $resource,
                deployment: $deployment
            );

            $cacheKey = $this->getNodeModulesCacheKey($project, $resource, $runtime, $version, $command);

            Span::add('build.node_modules_cache.enabled', $cacheKey !== '');
            Span::add('build.node_modules_cache.key', $cacheKey);

            $response = null;
            $err = null;

            if ($dbForProject->getDocument('deployments', $deploymentId)->getAttribute('status') === 'canceled') {
                $this->finalizeCanceledDeployment($deployment->getId(), $dbForProject, $queueForRealtime);

                return;
            }

            $isCanceled = false;
            $span = Span::current();

            Co::join([
                Co\go(function () use ($executor, &$response, $project, $deployment, $source, $resource, $runtime, $vars, $command, $cacheKey, $cpus, $memory, $timeout, &$err, $version, $span) {
                    try {
                        if ($version === 'v2') {
                            $command = 'tar -zxf /tmp/code.tar.gz -C /usr/code && cd /usr/local/src/ && ./build.sh';
                        } else {
                            $outputDirectory = $deployment->getAttribute('buildOutput') ?? $resource->getAttribute('outputDirectory');
                            if ($resource->getCollection() === 'sites') {
                                $command = $this->prepareSiteBuildCommand($command, $outputDirectory ?? '', $resource->getAttribute('framework', ''));
                            }

                            $command = 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh ' . \trim(\escapeshellarg($command));
                        }

                        $response = $executor->createRuntime(
                            deploymentId: $deployment->getId(),
                            projectId: $project->getId(),
                            source: $source,
                            image: $runtime['image'],
                            version: $version,
                            cpus: $cpus,
                            memory: $memory,
                            timeout: $timeout,
                            remove: true,
                            entrypoint: $deployment->getAttribute('entrypoint', ''),
                            destination: APP_STORAGE_BUILDS . "/app-{$project->getId()}",
                            variables: $vars,
                            command: $command,
                            outputDirectory: $outputDirectory ?? '',
                            cacheKey: $cacheKey
                        );

                    } catch (ExecutorTimeout $error) {
                        $span?->set('build.runtime.timed_out', true);
                        $span?->set('build.runtime.error_type', $error::class);
                        $span?->set('build.runtime.error_message', $error->getMessage());
                        $err = new BuildException(type: AppwriteException::BUILD_TIMEOUT, previous: $error);
                    } catch (ExecutorException $error) {
                        $span?->set('build.runtime.error_type', $error::class);
                        $span?->set('build.runtime.error_message', $error->getMessage());
                        $span?->set('build.runtime.executor_error_type', $error->getType());

                        $err = $error->getType() === ExecutorException::BUILD_FAILED
                            ? new BuildException($error->getMessage(), previous: $error)
                            : $error;
                    } catch (\Throwable $error) {
                        $span?->set('build.runtime.error_type', $error::class);
                        $span?->set('build.runtime.error_message', $error->getMessage());
                        $err = $error;
                    }
                }),
                Co\go(function () use ($executor, $project, &$deployment, &$response, $dbForProject, $timeout, &$err, $queueForRealtime, &$isCanceled, $span) {
                    try {
                        $insideSeparation = false;

                        $executor->getLogs(
                            deploymentId: $deployment->getId(),
                            projectId: $project->getId(),
                            timeout: $timeout,
                            callback: function ($logs) use (&$response, &$err, $dbForProject, &$isCanceled, &$deployment, $queueForRealtime, &$insideSeparation, $span) {
                                if ($isCanceled) {
                                    return;
                                }

                                // If we have response or error from concurrent coroutine, we already have latest logs
                                if ($response === null && $err === null) {
                                    $deployment = $dbForProject->getDocument('deployments', $deployment->getId());

                                    if ($deployment->getAttribute('status') === 'canceled') {
                                        $isCanceled = true;
                                        $span?->set('build.logs.ignored_reason', 'canceled');

                                        return;
                                    }

                                    // Get only valid UTF8 part - removes leftover half-multibytes causing SQL errors
                                    $logs = \mb_substr($logs, 0, null, 'UTF-8');

                                    // Do not stream logs added for SSR detection
                                    if (! $insideSeparation) {
                                        $separator = \strpos($logs, '{APPWRITE_DETECTION_SEPARATOR_START}');
                                        if ($separator !== false) {
                                            $logs = \substr($logs, 0, $separator);
                                            $insideSeparation = true;

                                            $leftover = \substr($logs, $separator + strlen('{APPWRITE_DETECTION_SEPARATOR_START}'));
                                            $separator = \strpos($leftover, '{APPWRITE_DETECTION_SEPARATOR_END}');
                                            if ($separator !== false) {
                                                $logs .= \substr($leftover, $separator + strlen('{APPWRITE_DETECTION_SEPARATOR_END}'));
                                                $insideSeparation = false;
                                            }
                                        }
                                    } else {
                                        $separator = \strpos($logs, '{APPWRITE_DETECTION_SEPARATOR_END}');
                                        if ($separator !== false) {
                                            $logs = \substr($logs, $separator + strlen('{APPWRITE_DETECTION_SEPARATOR_END}'));
                                            $insideSeparation = false;
                                        } else {
                                            $logs = '';
                                        }
                                    }

                                    if (empty($logs)) {
                                        return;
                                    }

                                    $currentLogs = $deployment->getAttribute('buildLogs', '');
                                    $affected = false;

                                    $streamLogs = \str_replace('\\n', '{APPWRITE_LINEBREAK_PLACEHOLDER}', $logs);
                                    foreach (\explode("\n", $streamLogs) as $streamLog) {
                                        if (empty($streamLog)) {
                                            continue;
                                        }

                                        $streamLog = \str_replace('{APPWRITE_LINEBREAK_PLACEHOLDER}', "\n", $streamLog);
                                        $streamParts = \explode(' ', $streamLog, 2);

                                        if (! isset($streamParts[1])) {
                                            continue;
                                        }

                                        // TODO: use part[0] as timestamp when switching to dbForLogs for build logs
                                        $currentLogs .= $streamParts[1];

                                        if (! empty($streamParts[1])) {
                                            $affected = true;
                                        }
                                    }

                                    if ($affected) {
                                        $deployment = $deployment->setAttribute('buildLogs', $currentLogs);
                                        $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), new Document([
                                            'buildLogs' => $currentLogs,
                                        ]));

                                        $queueForRealtime
                                            ->setPayload($deployment->getArrayCopy())
                                            ->trigger();
                                    }
                                }
                            }
                        );
                        $span?->set('build.logs.finished', true);
                    } catch (\Throwable $error) {
                        $span?->set('build.logs.error_type', $error::class);
                        $span?->set('build.logs.error_message', $error->getMessage());
                        if (empty($err)) {
                            $err = $error;
                        }
                    }
                }),
            ]);

            Span::add('timings.build', \round(\microtime(true) - $phaseStart, 3));
            $phaseStart = \microtime(true);

            $latestDeployment = $dbForProject->getDocument('deployments', $deploymentId);
            if ($latestDeployment->getAttribute('status') === 'canceled') {
                $this->finalizeCanceledDeployment($deployment->getId(), $dbForProject, $queueForRealtime);

                return;
            }

            if ($err) {
                throw $err;
            }

            $buildSizeLimit = (int) System::getEnv('_APP_COMPUTE_BUILD_SIZE_LIMIT', '2000000000');
            if (isset($plan['buildSize'])) {
                $buildSizeLimit = $plan['buildSize'] * 1000 * 1000;
            }
            if ($response['size'] > $buildSizeLimit && $buildSizeLimit !== 0) {
                throw new BuildException('Build size should be less than ' . number_format($buildSizeLimit / (1000 * 1000), 2) . ' MBs.');
            }

            $deployment->setAttribute('buildPath', $response['path']);
            $deployment->setAttribute('buildSize', $response['size']);
            $deployment->setAttribute('totalSize', $deployment->getAttribute('buildSize', 0) + $deployment->getAttribute('sourceSize', 0));
            Span::add('build.size', $deployment->getAttribute('buildSize'));
            Span::add('build.total_size', $deployment->getAttribute('totalSize'));

            $logs = '';
            foreach ($response['output'] as $log) {
                $logs .= $log['content'];
            }

            ['logs' => $logs, 'detectionLogs' => $detectionLogs] = $this->splitSiteDetectionLogs($logs);

            $deployment->setAttribute('buildLogs', $logs);

            $adapter = null;
            if ($resource->getCollection() === 'sites' && ! empty($detectionLogs)) {
                $detection = $this->detectSiteRendering($resource->getAttribute('framework', ''), $detectionLogs);

                $adapter = $resource->getAttribute('adapter', '');
                if (empty($adapter)) {
                    $resource = $dbForProject->updateDocument('sites', $resource->getId(), new Document(['adapter' => $detection->getName(), 'fallbackFile' => $detection->getFallbackFile() ?? '']));

                    $deployment->setAttribute('adapter', $detection->getName());
                    $deployment->setAttribute('fallbackFile', $detection->getFallbackFile() ?? '');
                    Span::add('build.adapter', $deployment->getAttribute('adapter'));
                    Span::add('build.fallback_file', $deployment->getAttribute('fallbackFile'));
                } elseif ($adapter === 'ssr' && $detection->getName() === 'static') {
                    throw new BuildException('Adapter mismatch. Detected: ' . $detection->getName() . ' does not match with the set adapter: ' . $adapter);
                }
            }

            $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), new Document([
                'buildPath' => $deployment->getAttribute('buildPath'),
                'buildSize' => $deployment->getAttribute('buildSize'),
                'totalSize' => $deployment->getAttribute('totalSize'),
                'buildLogs' => $deployment->getAttribute('buildLogs'),
                'adapter' => $deployment->getAttribute('adapter'),
                'fallbackFile' => $deployment->getAttribute('fallbackFile'),
            ]));
            $queueForRealtime
                ->setPayload($deployment->getArrayCopy())
                ->trigger();

            $this->afterBuildSuccess($queueForRealtime, $dbForProject, $deployment, $runtime, $adapter);

            $logs = $deployment->getAttribute('buildLogs', '');
            $date = \date('H:i:s');
            $logs .= "\033[90m[$date] \033[90m[\033[0mappwrite\033[90m]\033[32m Deployment finished. \033[0m\n";
            $deployment->setAttribute('buildLogs', $logs);

            /** Update the status */
            $endTime = DateTime::now();
            $durationEnd = \microtime(true);
            $deployment->setAttribute('buildEndedAt', $endTime);
            $deployment->setAttribute('buildDuration', \intval(\ceil($durationEnd - $durationStart)));
            $deployment->setAttribute('status', 'ready');
            $deployment = $dbForProject->updateDocument('deployments', $deploymentId, new Document([
                'buildEndedAt' => $deployment->getAttribute('buildEndedAt'),
                'buildDuration' => $deployment->getAttribute('buildDuration'),
                'buildLogs' => $deployment->getAttribute('buildLogs'),
                'status' => 'ready',
            ]));
            Span::add('deployment.status', 'ready');
            Span::add('build.duration', $deployment->getAttribute('buildDuration'));

            $resource = $this->updateLatestDeployment($dbForProject, $resource);

            if ($isVcsEnabled) {
                $this->runGitAction('ready', $github, $providerCommitHash, $owner, $repositoryName, $project, $resource, $deployment->getId(), $dbForProject, $dbForPlatform, $queueForRealtime, $platform);
            }

            /** Set auto deploy */
            $activateBuild = false;
            if ($deployment->getAttribute('activate') === true) {
                // Check if current active deployment started later than this deployment
                $resource = $dbForProject->getDocument($resource->getCollection(), $resource->getId());
                $currentActiveDeploymentId = $resource->getAttribute('deploymentId', '');
                if (! empty($currentActiveDeploymentId)) {
                    $currentActiveDeployment = $dbForProject->getDocument('deployments', $currentActiveDeploymentId);
                    if (! $currentActiveDeployment->isEmpty()) {
                        $currentActiveStartTime = $currentActiveDeployment->getCreatedAt();
                        $deploymentStartTime = $deployment->getCreatedAt();

                        // Skip auto-activation if current active deployment started later than deployment that is being activated
                        if ($currentActiveStartTime < $deploymentStartTime) {
                            $activateBuild = true;
                        } else {
                            Span::add('build.auto_activation.skipped_reason', 'current_deployment_newer');
                        }
                    }
                } else {
                    $activateBuild = true;
                }
            }

            if ($activateBuild) {
                switch ($resource->getCollection()) {
                    case 'functions':
                        $resource = $dbForProject->updateDocument('functions', $resource->getId(), new Document([
                            'live' => true,
                            'deploymentId' => $deployment->getId(),
                            'deploymentInternalId' => $deployment->getSequence(),
                            'deploymentCreatedAt' => $deployment->getCreatedAt(),
                        ]));

                        $queries = [
                            Query::equal('projectInternalId', [$project->getSequence()]),
                            Query::equal('type', ['deployment']),
                            Query::equal('deploymentResourceInternalId', [$resource->getSequence()]),
                            Query::equal('deploymentResourceType', ['function']),
                            Query::equal('trigger', ['manual']),
                            Query::equal('deploymentVcsProviderBranch', ['']),
                        ];

                        $rulesUpdated = false;
                        $dbForPlatform->forEach('rules', function (Document $rule) use ($dbForPlatform, $deployment, &$rulesUpdated) {
                            $rulesUpdated = true;
                            $rule = $dbForPlatform->updateDocument('rules', $rule->getId(), new Document([
                                'deploymentId' => $deployment->getId(),
                                'deploymentInternalId' => $deployment->getSequence(),
                            ]));
                        }, $queries);
                        break;
                    case 'sites':
                        $resource = $dbForProject->updateDocument('sites', $resource->getId(), new Document([
                            'live' => true,
                            'deploymentId' => $deployment->getId(),
                            'deploymentInternalId' => $deployment->getSequence(),
                            'deploymentCreatedAt' => $deployment->getCreatedAt(),
                        ]));
                        $queries = [
                            Query::equal('projectInternalId', [$project->getSequence()]),
                            Query::equal('type', ['deployment']),
                            Query::equal('deploymentResourceInternalId', [$resource->getSequence()]),
                            Query::equal('deploymentResourceType', ['site']),
                            Query::equal('trigger', ['manual']),
                            Query::equal('deploymentVcsProviderBranch', ['']),
                        ];

                        $dbForPlatform->forEach('rules', function (Document $rule) use ($dbForPlatform, $deployment) {
                            $rule = $dbForPlatform->updateDocument('rules', $rule->getId(), new Document([
                                'deploymentId' => $deployment->getId(),
                                'deploymentInternalId' => $deployment->getSequence(),
                            ]));
                        }, $queries);

                        break;
                }

                Span::add('build.activated', true);
            }

            $resource = $this->updateLatestDeployment($dbForProject, $resource);

            $this->afterDeploymentSuccess(
                $project,
                $deployment,
            );

            // Send realtime event after updating the associated resource so that Console will have the resource's deployment details when re-fetching.
            $queueForRealtime
                ->setPayload($deployment->getArrayCopy())
                ->trigger();

            if ($resource->getCollection() === 'sites') {
                // VCS branch
                $branchName = $deployment->getAttribute('providerBranch');
                if (! empty($branchName)) {
                    $domain = (new BranchDomainFilter())->apply([
                        'branch' => $branchName,
                        'resourceId' => $resource->getId(),
                        'projectId' => $project->getId(),
                        'sitesDomain' => $platform['sitesDomain'],
                    ]);
                    $ruleId = md5($domain);

                    try {
                        $dbForPlatform->createDocument('rules', new Document([
                            '$id' => $ruleId,
                            'projectId' => $project->getId(),
                            'projectInternalId' => $project->getSequence(),
                            'domain' => $domain,
                            'type' => 'deployment',
                            'trigger' => 'deployment',
                            'deploymentId' => $deployment->getId(),
                            'deploymentInternalId' => $deployment->getSequence(),
                            'deploymentResourceType' => 'site',
                            'deploymentResourceId' => $resource->getId(),
                            'deploymentResourceInternalId' => $resource->getSequence(),
                            'deploymentVcsProviderBranch' => $branchName,
                            'status' => 'verified',
                            'certificateId' => '',
                            'search' => implode(' ', [$ruleId, $domain]),
                            'owner' => 'Appwrite',
                            'region' => $project->getAttribute('region'),
                        ]));
                    } catch (Duplicate $err) {
                        $rule = $dbForPlatform->updateDocument('rules', $ruleId, new Document([
                            'deploymentId' => $deployment->getId(),
                            'deploymentInternalId' => $deployment->getSequence(),
                        ]));
                    }

                    $queries = [
                        Query::equal('projectInternalId', [$project->getSequence()]),
                        Query::equal('type', ['deployment']),
                        Query::equal('deploymentResourceInternalId', [$resource->getSequence()]),
                        Query::equal('deploymentResourceType', ['site']),
                        Query::equal('deploymentVcsProviderBranch', [$branchName]),
                        Query::equal('trigger', ['manual']),
                    ];

                    $dbForPlatform->foreach('rules', function (Document $rule) use ($dbForPlatform, $deployment) {
                        $rule = $dbForPlatform->updateDocument('rules', $rule->getId(), new Document([
                            'deploymentId' => $deployment->getId(),
                            'deploymentInternalId' => $deployment->getSequence(),
                        ]));
                    }, $queries);

                    Span::add('build.preview_rule_created', true);
                }
            }

            $queueForRealtime
                ->setPayload($deployment->getArrayCopy())
                ->trigger();

            if ($dbForProject->getDocument('deployments', $deploymentId)->getAttribute('status') === 'canceled') {
                $this->finalizeCanceledDeployment($deployment->getId(), $dbForProject, $queueForRealtime);

                return;
            }

            /** Update function schedule */

            // Inform scheduler if function is still active
            if ($resource->getCollection() === 'functions') {
                $schedule = $dbForPlatform->getDocument('schedules', $resource->getAttribute('scheduleId'));
                $schedule
                    ->setAttribute('resourceUpdatedAt', DateTime::now())
                    ->setAttribute('schedule', $resource->getAttribute('schedule'))
                    ->setAttribute('active', ! empty($resource->getAttribute('schedule')) && ! empty($resource->getAttribute('deploymentId')));
                $dbForPlatform->updateDocument('schedules', $schedule->getId(), new Document([
                    'resourceUpdatedAt' => $schedule->getAttribute('resourceUpdatedAt'),
                    'schedule' => $schedule->getAttribute('schedule'),
                    'active' => $schedule->getAttribute('active'),
                ]));
            }

            /** Screenshot site */
            if ($resource->getCollection() === 'sites') {
                $publisherForScreenshots->enqueue(new \Appwrite\Event\Message\Screenshot(
                    project: $project,
                    deploymentId: $deployment->getId(),
                ));

                Span::add('build.screenshot_queued', true);
            }

            Span::add('timings.finalize', \round(\microtime(true) - $phaseStart, 3));
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

            $deployment->setAttribute('buildLogs', $message);
            $deployment = $dbForProject->updateDocument('deployments', $deploymentId, new Document([
                'buildEndedAt' => $deployment->getAttribute('buildEndedAt'),
                'buildDuration' => $deployment->getAttribute('buildDuration'),
                'status' => 'failed',
                'buildLogs' => $message,
            ]));

            $resource = $this->updateLatestDeployment($dbForProject, $resource);

            $queueForRealtime
                ->setPayload($deployment->getArrayCopy())
                ->trigger();

            if ($isVcsEnabled) {
                $this->runGitAction('failed', $github, $providerCommitHash, $owner, $repositoryName, $project, $resource, $deployment->getId(), $dbForProject, $dbForPlatform, $queueForRealtime, $platform, true);
            }

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
        $spec = Config::getParam('specifications')[$resource->getAttribute('buildSpecification', APP_COMPUTE_SPECIFICATION_DEFAULT)];
        $cpus = (int) ($spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT);
        $memory = (int) ($spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT);

        switch ($deployment->getAttribute('status')) {
            case 'ready':
                $usage
                    ->addMetric(METRIC_BUILDS_SUCCESS, 1) // per project
                    ->addMetric(METRIC_BUILDS_COMPUTE_SUCCESS, (int) $deployment->getAttribute('buildDuration', 0) * 1000)
                    ->addMetric(str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_BUILDS_SUCCESS), 1) // per function
                    ->addMetric(str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_BUILDS_COMPUTE_SUCCESS), (int) $deployment->getAttribute('buildDuration', 0) * 1000)
                    ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_SUCCESS), 1) // per function
                    ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_COMPUTE_SUCCESS), (int) $deployment->getAttribute('buildDuration', 0) * 1000);
                break;
            case 'failed':
                $usage
                    ->addMetric(METRIC_BUILDS_FAILED, 1) // per project
                    ->addMetric(METRIC_BUILDS_COMPUTE_FAILED, (int) $deployment->getAttribute('buildDuration', 0) * 1000)
                    ->addMetric(str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_BUILDS_FAILED), 1) // per function
                    ->addMetric(str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_BUILDS_COMPUTE_FAILED), (int) $deployment->getAttribute('buildDuration', 0) * 1000)
                    ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_FAILED), 1) // per function
                    ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_COMPUTE_FAILED), (int) $deployment->getAttribute('buildDuration', 0) * 1000);
                break;
        }

        $usage
            ->addMetric(METRIC_BUILDS, 1) // per project
            ->addMetric(METRIC_BUILDS_STORAGE, $deployment->getAttribute('buildSize', 0))
            ->addMetric(METRIC_BUILDS_COMPUTE, (int) $deployment->getAttribute('buildDuration', 0) * 1000)
            ->addMetric(METRIC_BUILDS_MB_SECONDS, (int) ($memory * $deployment->getAttribute('buildDuration', 0) * $cpus))
            ->addMetric(str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_BUILDS), 1) // per function
            ->addMetric(str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_BUILDS_STORAGE), $deployment->getAttribute('buildSize', 0))
            ->addMetric(str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_BUILDS_COMPUTE), (int) $deployment->getAttribute('buildDuration', 0) * 1000)
            ->addMetric(str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_BUILDS_MB_SECONDS), (int) ($memory * $deployment->getAttribute('buildDuration', 0) * $cpus))
            ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS), 1) // per function
            ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_STORAGE), $deployment->getAttribute('buildSize', 0))
            ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_COMPUTE), (int) $deployment->getAttribute('buildDuration', 0) * 1000)
            ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_MB_SECONDS), (int) ($memory * $deployment->getAttribute('buildDuration', 0) * $cpus));

        // Publish usage metrics
        if (! $usage->isEmpty()) {
            $message = new UsageMessage(
                project: $project,
                metrics: $usage->getMetrics(),
                reduce: $usage->getReduce()
            );
            $publisherForUsage->enqueue($message);
            $usage->reset();
        }
    }

    /**
     * Hook to run after build success
     *
     * @throws Exception
     */
    protected function afterBuildSuccess(Realtime $queueForRealtime, Database $dbForProject, Document &$deployment, array $runtime, ?string $adapter): void
    {
    }

    /**
     * Hook to run after deployment is activated
     */
    protected function afterDeploymentSuccess(
        Document $project,
        Document $deployment,
    ): void {
    }

    protected function getRuntime(Document $resource, string $version): array
    {
        $runtimes = Config::getParam($version === 'v2' ? 'runtimes-v2' : 'runtimes', []);
        $key = $resource->getAttribute('runtime');
        $runtime = match ($resource->getCollection()) {
            'functions' => $runtimes[$resource->getAttribute('runtime')] ?? null,
            'sites' => $runtimes[$resource->getAttribute('buildRuntime')] ?? null,
            default => null
        };
        if (\is_null($runtime)) {
            throw new BuildException('Runtime "' . $resource->getAttribute('runtime', '') . '" is not supported');
        }

        return $runtime;
    }

    protected function getVersion(Document $resource): string
    {
        return match ($resource->getCollection()) {
            'functions' => $resource->getAttribute('version', 'v2'),
            'sites' => 'v5',
            default => throw new \Exception('Unsupported resource type "' . $resource->getCollection() . '".'),
        };
    }

    protected function getCommand(Document $resource, Document $deployment): string
    {
        if ($resource->getCollection() === 'functions') {
            return $deployment->getAttribute('buildCommands', '');
        } elseif ($resource->getCollection() === 'sites') {
            $commands = [];

            $frameworks = Config::getParam('frameworks', []);
            $framework = $frameworks[$resource->getAttribute('framework', '')] ?? null;

            $envCommand = '';
            $bundleCommand = '';
            if (! is_null($framework)) {
                $envCommand = $framework['envCommand'] ?? '';
                $bundleCommand = $framework['bundleCommand'] ?? '';
            }

            $commands[] = $envCommand;
            $commands[] = $deployment->getAttribute('buildCommands', '');
            $commands[] = $bundleCommand;

            $commands = array_filter($commands, fn ($command) => ! empty($command));

            return implode(' && ', $commands);
        }

        return '';
    }

    protected function getNodeModulesCacheKey(Document $project, Document $resource, array $runtime, string $version, string $command): string
    {
        if ($version !== 'v5' || $command === '' || $command === '0') {
            return '';
        }

        $hashContext = [
            'version' => 'v1',
            'projectId' => $project->getId(),
            'resourceType' => $resource->getCollection(),
            'resourceId' => $resource->getId(),
            'runtime' => $runtime['image'] ?? '',
        ];

        return \substr(\hash('sha256', \json_encode($hashContext, JSON_THROW_ON_ERROR)), 0, 48);
    }

    protected function prepareSiteBuildCommand(string $command, string $outputDirectory, string $framework): string
    {
        $listFilesCommand = 'echo "{APPWRITE_DETECTION_SEPARATOR_START}" && cd /usr/local/build';

        if (! empty($outputDirectory)) {
            $listFilesCommand .= ' && cd ' . \escapeshellarg($outputDirectory);
        }

        foreach (SSR::FRAMEWORK_FILES[$framework] ?? [] as $file) {
            $listFilesCommand .= ' && ( [ -e ' . \escapeshellarg($file) . ' ] && echo ' . \escapeshellarg($file) . ' || true )';
        }

        $listFilesCommand .= ' && echo "{APPWRITE_DETECTION_SEPARATOR_END}"';

        if (empty($command)) {
            return $listFilesCommand;
        }

        return "{$command} && {$listFilesCommand}";
    }

    /**
     * @return array{logs: string, detectionLogs: string}
     */
    protected function splitSiteDetectionLogs(string $logs): array
    {
        if (! \str_contains($logs, '{APPWRITE_DETECTION_SEPARATOR_START}')) {
            return [
                'logs' => $logs,
                'detectionLogs' => '',
            ];
        }

        [$logsBefore, $detectionLogsStart] = \explode('{APPWRITE_DETECTION_SEPARATOR_START}', $logs, 2);
        [$detectionLogs, $logsAfter] = \explode('{APPWRITE_DETECTION_SEPARATOR_END}', $detectionLogsStart, 2);

        return [
            'logs' => "{$logsBefore}{$logsAfter}",
            'detectionLogs' => $detectionLogs,
        ];
    }

    protected function detectSiteRendering(string $framework, string $detectionLogs): object
    {
        $files = \explode("\n", $detectionLogs);
        $files = \array_filter($files);
        $files = \array_map(\trim(...), $files);
        $files = \array_map(fn ($file) => \str_starts_with($file, './') ? \substr($file, 2) : $file, $files);

        $detector = new Rendering($framework);
        foreach ($files as $file) {
            $detector->addInput($file);
        }

        return $detector
            ->addOption(new SSR())
            ->addOption(new XStatic())
            ->detect();
    }

    /**
     * @throws Structure
     * @throws \Utopia\Database\Exception
     * @throws Conflict
     * @throws Restricted
     */
    protected function runGitAction(
        string $status,
        GitHub $github,
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
            if ($resource->getAttribute('providerSilentMode', false) === true) {
                return;
            }

            $deployment = $dbForProject->getDocument('deployments', $deploymentId);
            $commentId = $deployment->getAttribute('providerCommentId', '');

            if (! empty($providerCommitHash)) {
                $message = match ($status) {
                    'ready' => 'Build succeeded.',
                    'failed' => 'Build failed.',
                    'processing' => 'Building...',
                    default => $status
                };

                $state = match ($status) {
                    'ready' => 'success',
                    'failed' => 'failure',
                    'processing' => 'pending',
                    default => $status
                };

                $resourceName = $resource->getAttribute('name');
                $projectName = $project->getAttribute('name');

                $name = "{$resourceName} ({$projectName})";

                $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') == 'disabled' ? 'http' : 'https';
                $hostname = System::getEnv('_APP_CONSOLE_DOMAIN', System::getEnv('_APP_DOMAIN', ''));

                $projectId = $project->getId();
                $region = $project->getAttribute('region', 'default');
                $resourceId = $resource->getId();
                $providerTargetUrl = match ($resource->getCollection()) {
                    'functions' => "{$protocol}://{$hostname}/console/project-{$region}-{$projectId}/functions/function-{$resourceId}",
                    'sites' => "{$protocol}://{$hostname}/console/project-{$region}-{$projectId}/sites/site-{$resourceId}",
                    default => throw new \Exception('Invalid resource type')
                };

                $github->updateCommitStatus($repositoryName, $providerCommitHash, $owner, $state, $message, $providerTargetUrl, $name);
            }

            if (! empty($commentId)) {
                $retries = 0;

                while (true) {
                    $retries++;

                    try {
                        $dbForPlatform->createDocument('vcsCommentLocks', new Document([
                            '$id' => $commentId,
                        ]));
                        break;
                    } catch (\Throwable $err) {
                        if ($retries >= 9) {
                            throw $err;
                        }

                        \sleep(1);
                    }
                }

                // Wrap in try/finally to ensure lock file gets deleted
                try {
                    $resourceType = match ($resource->getCollection()) {
                        'functions' => 'function',
                        'sites' => 'site',
                        default => throw new \Exception('Invalid resource type')
                    };

                    $rule = $dbForPlatform->findOne('rules', [
                        Query::equal('projectInternalId', [$project->getSequence()]),
                        Query::equal('type', ['deployment']),
                        Query::equal('deploymentInternalId', [$deployment->getSequence()]),
                    ]);

                    $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') == 'disabled' ? 'http' : 'https';
                    $previewUrl = '';
                    if ($resource->getCollection() === 'sites' && !$rule->isEmpty()) {
                        $previewUrl = "{$protocol}://" . $rule->getAttribute('domain', '');
                    }

                    $comment = new Comment($platform);
                    $comment->parseComment($github->getComment($owner, $repositoryName, $commentId));
                    $comment->addBuild($project, $resource, $resourceType, $status, $deployment->getId(), ['type' => 'logs'], $previewUrl);
                    $github->updateComment($owner, $repositoryName, $commentId, $comment->generateComment());
                } finally {
                    $dbForPlatform->deleteDocument('vcsCommentLocks', $commentId);
                }
            }
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

            $deployment->setAttribute('buildLogs', $logs);
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

                $deployment->setAttribute('buildLogs', $logs);
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
