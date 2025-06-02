<?php

namespace Appwrite\Platform\Modules\Functions\Workers;

use Ahc\Jwt\JWT;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Realtime;
use Appwrite\Event\StatsUsage;
use Appwrite\Event\Webhook;
use Appwrite\Permission;
use Appwrite\Role;
use Appwrite\Utopia\Response\Model\Deployment;
use Appwrite\Vcs\Comment;
use Exception;
use Executor\Executor;
use Swoole\Coroutine as Co;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Exception\Restricted;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Detector\Detection\Rendering\SSR;
use Utopia\Detector\Detection\Rendering\XStatic;
use Utopia\Detector\Detector\Rendering;
use Utopia\Fetch\Client as FetchClient;
use Utopia\Logger\Log;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Storage\Compression\Compression;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git\GitHub;

use function Swoole\Coroutine\batch;

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
            ->inject('queueForWebhooks')
            ->inject('queueForFunctions')
            ->inject('queueForRealtime')
            ->inject('queueForStatsUsage')
            ->inject('cache')
            ->inject('dbForProject')
            ->inject('deviceForFunctions')
            ->inject('deviceForSites')
            ->inject('isResourceBlocked')
            ->inject('deviceForFiles')
            ->inject('log')
            ->inject('executor')
            ->inject('plan')
            ->callback([$this, 'action']);
    }

    /**
     * @param Message $message
     * @param Document $project
     * @param Database $dbForPlatform
     * @param Event $queueForEvents
     * @param Webhook $queueForWebhooks
     * @param Func $queueForFunctions
     * @param Realtime $queueForRealtime
     * @param StatsUsage $queueForStatsUsage
     * @param Cache $cache
     * @param Database $dbForProject
     * @param Device $deviceForFunctions
     * @param Device $deviceForSites
     * @param Device $deviceForFiles
     * @param Log $log
     * @param Executor $executor
     * @param array $plan
     * @return void
     * @throws \Utopia\Database\Exception
     */
    public function action(
        Message $message,
        Document $project,
        Database $dbForPlatform,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        Func $queueForFunctions,
        Realtime $queueForRealtime,
        StatsUsage $queueForStatsUsage,
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
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new \Exception('Missing payload');
        }

        $type = $payload['type'] ?? '';
        $resource = new Document($payload['resource'] ?? []);
        $deployment = new Document($payload['deployment'] ?? []);
        $template = new Document($payload['template'] ?? []);

        $log->addTag('projectId', $project->getId());
        $log->addTag('type', $type);

        switch ($type) {
            case BUILD_TYPE_DEPLOYMENT:
            case BUILD_TYPE_RETRY:
                Console::info('Creating build for deployment: ' . $deployment->getId());
                $github = new GitHub($cache);
                $this->buildDeployment(
                    $deviceForFunctions,
                    $deviceForSites,
                    $deviceForFiles,
                    $queueForWebhooks,
                    $queueForFunctions,
                    $queueForRealtime,
                    $queueForEvents,
                    $queueForStatsUsage,
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
                    $plan
                );
                break;

            default:
                throw new \Exception('Invalid build type');
        }
    }

    /**
     * @param Device $deviceForFunctions
     * @param Device $deviceForSites
     * @param Device $deviceForFiles
     * @param Webhook $queueForWebhooks
     * @param Func $queueForFunctions
     * @param Realtime $queueForRealtime
     * @param Event $queueForEvents
     * @param StatsUsage $queueForStatsUsage
     * @param Database $dbForPlatform
     * @param Database $dbForProject
     * @param GitHub $github
     * @param Document $project
     * @param Document $resource
     * @param Document $deployment
     * @param Document $template
     * @param Log $log
     * @param Executor $executor
     * @param array $plan
     * @return void
     * @throws \Utopia\Database\Exception
     *
     * @throws Exception
     */
    protected function buildDeployment(
        Device $deviceForFunctions,
        Device $deviceForSites,
        Device $deviceForFiles,
        Webhook $queueForWebhooks,
        Func $queueForFunctions,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        StatsUsage $queueForStatsUsage,
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
        array $plan
    ): void {
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

        if ($isResourceBlocked($project, $resourceKey === 'functions' ? RESOURCE_TYPE_FUNCTIONS : RESOURCE_TYPE_SITES, $resource->getId())) {
            throw new \Exception('Resource is blocked');
        }

        $log->addTag('deploymentId', $deployment->getId());

        $deployment = $dbForProject->getDocument('deployments', $deployment->getId());
        if ($deployment->isEmpty()) {
            throw new \Exception('Deployment not found');
        }

        if ($resource->getCollection() === 'functions' && empty($deployment->getAttribute('entrypoint', ''))) {
            throw new \Exception('Entrypoint for your Appwrite Function is missing. Please specify it when making deployment or update the entrypoint under your function\'s "Settings" > "Configuration" > "Entrypoint".');
        }

        $version = $this->getVersion($resource);
        $runtime = $this->getRuntime($resource, $version);

        $spec = Config::getParam('specifications')[$resource->getAttribute('specification', APP_COMPUTE_SPECIFICATION_DEFAULT)];

        if ($resource->getCollection() === 'functions' && \is_null($runtime)) {
            throw new \Exception('Runtime "' . $resource->getAttribute('runtime', '') . '" is not supported');
        }

        // Realtime preparation
        $event = "{$resource->getCollection()}.[{$resourceKey}].deployments.[deploymentId].update";
        $queueForRealtime
            ->setSubscribers(['console'])
            ->setProject($project)
            ->setEvent($event)
            ->setParam($resourceKey, $resource->getId())
            ->setParam('deploymentId', $deployment->getId());

        $startTime = DateTime::now();
        $durationStart = \microtime(true);

        if ($deployment->getAttribute('status') === 'canceled') {
            Console::info('Build has been canceled');
            return;
        }

        $deploymentId = $deployment->getId();

        $deployment->setAttribute('buildStartedAt', $startTime);
        $deployment->setAttribute('status', 'processing');
        $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), $deployment);

        if ($deployment->getInternalId() === $resource->getAttribute('latestDeploymentInternalId', '')) {
            $resource = $resource->setAttribute('latestDeploymentStatus', $deployment->getAttribute('status', ''));
            $dbForProject->updateDocument($resource->getCollection(), $resource->getId(), $resource);
        }

        $queueForRealtime
            ->setPayload($deployment->getArrayCopy())
            ->trigger();

        $source = $deployment->getAttribute('sourcePath', '');
        $installationId = $deployment->getAttribute('installationId', '');
        $providerRepositoryId = $deployment->getAttribute('providerRepositoryId', '');
        $providerCommitHash = $deployment->getAttribute('providerCommitHash', '');
        $isVcsEnabled = !empty($providerRepositoryId);
        $owner = '';
        $repositoryName = '';

        if ($isVcsEnabled) {
            $installation = $dbForPlatform->getDocument('installations', $installationId);
            $providerInstallationId = $installation->getAttribute('providerInstallationId');
            $privateKey = System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
            $githubAppId = System::getEnv('_APP_VCS_GITHUB_APP_ID');

            $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);
        }

        try {
            if (!$isVcsEnabled) {
                // Non-VCS + Template
                $templateRepositoryName = $template->getAttribute('repositoryName', '');
                $templateOwnerName = $template->getAttribute('ownerName', '');
                $templateVersion = $template->getAttribute('version', '');

                $templateRootDirectory = $template->getAttribute('rootDirectory', '');
                $templateRootDirectory = \rtrim($templateRootDirectory, '/');
                $templateRootDirectory = \ltrim($templateRootDirectory, '.');
                $templateRootDirectory = \ltrim($templateRootDirectory, '/');

                if (!empty($templateRepositoryName) && !empty($templateOwnerName) && !empty($templateVersion)) {
                    $stdout = '';
                    $stderr = '';

                    // Clone template repo
                    $tmpTemplateDirectory = '/tmp/builds/' . $deploymentId . '-template';
                    $gitCloneCommandForTemplate = $github->generateCloneCommand($templateOwnerName, $templateRepositoryName, $templateVersion, GitHub::CLONE_TYPE_TAG, $tmpTemplateDirectory, $templateRootDirectory);
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

                    if (!$result) {
                        throw new \Exception("Unable to move file");
                    }

                    Console::execute('rm -rf ' . \escapeshellarg($tmpTemplateDirectory), '', $stdout, $stderr);

                    $directorySize = $device->getFileSize($source);
                    $deployment
                        ->setAttribute('sourcePath', $source)
                        ->setAttribute('sourceSize', $directorySize)
                        ->setAttribute('totalSize', $directorySize);
                    $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), $deployment);

                    $queueForRealtime
                        ->setPayload($deployment->getArrayCopy())
                        ->trigger();
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
                if (!empty($commitHash)) {
                    $cloneVersion = $commitHash;
                    $cloneType = GitHub::CLONE_TYPE_COMMIT;
                }

                $gitCloneCommand = $github->generateCloneCommand($cloneOwner, $cloneRepository, $cloneVersion, $cloneType, $tmpDirectory, $rootDirectory);
                $stdout = '';
                $stderr = '';

                Console::execute('mkdir -p ' . \escapeshellarg('/tmp/builds/' . $deploymentId), '', $stdout, $stderr);

                if ($dbForProject->getDocument('deployments', $deploymentId)->getAttribute('status') === 'canceled') {
                    Console::info('Build has been canceled');
                    return;
                }

                $exit = Console::execute($gitCloneCommand, '', $stdout, $stderr);

                if ($exit !== 0) {
                    throw new \Exception('Unable to clone code repository: ' . $stderr);
                }

                // Local refactoring for function folder with spaces
                if (str_contains($rootDirectory, ' ')) {
                    $rootDirectoryWithoutSpaces = str_replace(' ', '', $rootDirectory);
                    $from = $tmpDirectory . '/' . $rootDirectory;
                    $to = $tmpDirectory . '/' . $rootDirectoryWithoutSpaces;
                    $exit = Console::execute('mv "' . \escapeshellarg($from) . '" "' . \escapeshellarg($to) . '"', '', $stdout, $stderr);

                    if ($exit !== 0) {
                        throw new \Exception('Unable to move function with spaces' . $stderr);
                    }
                    $rootDirectory = $rootDirectoryWithoutSpaces;
                }


                // Build from template
                $templateRepositoryName = $template->getAttribute('repositoryName', '');
                $templateOwnerName = $template->getAttribute('ownerName', '');
                $templateVersion = $template->getAttribute('version', '');

                $templateRootDirectory = $template->getAttribute('rootDirectory', '');
                $templateRootDirectory = \rtrim($templateRootDirectory, '/');
                $templateRootDirectory = \ltrim($templateRootDirectory, '.');
                $templateRootDirectory = \ltrim($templateRootDirectory, '/');

                if (!empty($templateRepositoryName) && !empty($templateOwnerName) && !empty($templateVersion)) {
                    // Clone template repo
                    $tmpTemplateDirectory = '/tmp/builds/' . $deploymentId . '/template';

                    $gitCloneCommandForTemplate = $github->generateCloneCommand($templateOwnerName, $templateRepositoryName, $templateVersion, GitHub::CLONE_TYPE_TAG, $tmpTemplateDirectory, $templateRootDirectory);
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
                    $exit = Console::execute('git config --global user.email "team@appwrite.io" && git config --global user.name "Appwrite" && cd ' . \escapeshellarg($tmpDirectory) . ' && git add . && git commit -m "Create ' . \escapeshellarg($resource->getAttribute('name', '')) . ' function" && git push origin ' . \escapeshellarg($branchName), '', $stdout, $stderr);

                    if ($exit !== 0) {
                        throw new \Exception('Unable to push code repository: ' . $stderr);
                    }

                    $exit = Console::execute('cd ' . \escapeshellarg($tmpDirectory) . ' && git rev-parse HEAD', '', $stdout, $stderr);

                    if ($exit !== 0) {
                        throw new \Exception('Unable to get vcs commit SHA: ' . $stderr);
                    }

                    $providerCommitHash = \trim($stdout);
                    $authorUrl = "https://github.com/$cloneOwner";

                    $deployment->setAttribute('providerCommitHash', $providerCommitHash ?? '');
                    $deployment->setAttribute('providerCommitAuthorUrl', $authorUrl);
                    $deployment->setAttribute('providerCommitAuthor', 'Appwrite');
                    $deployment->setAttribute('providerCommitMessage', "Create '" . $resource->getAttribute('name', '') . "' function");
                    $deployment->setAttribute('providerCommitUrl', "https://github.com/$cloneOwner/$cloneRepository/commit/$providerCommitHash");
                    $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), $deployment);

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
                $sizeLimit = (int)System::getEnv('_APP_COMPUTE_SIZE_LIMIT', '30000000');

                if (isset($plan['deploymentSize'])) {
                    $sizeLimit = (int) $plan['deploymentSize'] * 1000 * 1000;
                }

                if ($directorySize > $sizeLimit && $sizeLimit !== 0) {
                    throw new \Exception('Repository directory size should be less than ' . number_format($sizeLimit / (1000 * 1000), 2) . ' MBs.');
                }

                Console::execute('find ' . \escapeshellarg($tmpDirectory) . ' -type d -name ".git" -exec rm -rf {} +', '', $stdout, $stderr);

                $tarParamDirectory = '/tmp/builds/' . $deploymentId . '/code' . (empty($rootDirectory) ? '' : '/' . $rootDirectory);
                Console::execute('tar --exclude code.tar.gz -czf ' . \escapeshellarg($tmpPathFile) . ' -C ' . \escapeshellcmd($tarParamDirectory) . ' .', '', $stdout, $stderr); // TODO: Replace escapeshellcmd with escapeshellarg if we find a way that doesnt break syntax

                $source = $device->getPath($deployment->getId() . '.' . \pathinfo('code.tar.gz', PATHINFO_EXTENSION));
                $result = $localDevice->transfer($tmpPathFile, $source, $device);

                if (!$result) {
                    throw new \Exception("Unable to move file");
                }

                Console::execute('rm -rf ' . \escapeshellarg($tmpPath), '', $stdout, $stderr);

                $directorySize = $device->getFileSize($source);

                $deployment
                    ->setAttribute('sourcePath', $source)
                    ->setAttribute('sourceSize', $directorySize)
                    ->setAttribute('totalSize', $directorySize);
                $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), $deployment);

                $queueForRealtime
                    ->setPayload($deployment->getArrayCopy())
                    ->trigger();

                $this->runGitAction('processing', $github, $providerCommitHash, $owner, $repositoryName, $project, $resource, $deployment->getId(), $dbForProject, $dbForPlatform);
            }

            /** Request the executor to build the code... */
            $deployment->setAttribute('status', 'building');
            $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), $deployment);

            if ($deployment->getInternalId() === $resource->getAttribute('latestDeploymentInternalId', '')) {
                $resource = $resource->setAttribute('latestDeploymentStatus', $deployment->getAttribute('status', ''));
                $dbForProject->updateDocument($resource->getCollection(), $resource->getId(), $resource);
            }

            $queueForRealtime
                ->setPayload($deployment->getArrayCopy())
                ->trigger();

            if ($isVcsEnabled) {
                $this->runGitAction('building', $github, $providerCommitHash, $owner, $repositoryName, $project, $resource, $deployment->getId(), $dbForProject, $dbForPlatform);
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
            $queueForFunctions
                ->from($deploymentUpdate)
                ->trigger();

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

            if ($resource->getAttribute('framework', '') === 'analog') {
                $minMemory = 4096;
            }

            $cpus = $spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT;
            $memory =  max($spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT, $minMemory);
            $timeout = (int) System::getEnv('_APP_COMPUTE_BUILD_TIMEOUT', 900);


            $jwtExpiry = (int)System::getEnv('_APP_COMPUTE_BUILD_TIMEOUT', 900);
            $jwtObj = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', $jwtExpiry, 0);

            $apiKey = $jwtObj->encode([
                'projectId' => $project->getId(),
                'scopes' => $resource->getAttribute('scopes', [])
            ]);

            $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') == 'disabled' ? 'http' : 'https';
            $hostname = System::getEnv('_APP_DOMAIN');
            $endpoint = $protocol . '://' . $hostname . "/v1";

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

            switch ($resource->getCollection()) {
                case 'functions':
                    $vars = [
                        ...$vars,
                        'APPWRITE_FUNCTION_API_ENDPOINT' => $endpoint,
                        'APPWRITE_FUNCTION_API_KEY' => API_KEY_DYNAMIC . '_' . $apiKey,
                        'APPWRITE_FUNCTION_ID' => $resource->getId(),
                        'APPWRITE_FUNCTION_NAME' => $resource->getAttribute('name'),
                        'APPWRITE_FUNCTION_DEPLOYMENT' => $deployment->getId(),
                        'APPWRITE_FUNCTION_PROJECT_ID' => $project->getId(),
                        'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'] ?? '',
                        'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'] ?? '',
                        'APPWRITE_FUNCTION_CPUS' => $cpus,
                        'APPWRITE_FUNCTION_MEMORY' => $memory,
                    ];
                    break;
                case 'sites':
                    $vars = [
                        ...$vars,
                        'APPWRITE_SITE_API_ENDPOINT' => $endpoint,
                        'APPWRITE_SITE_API_KEY' => API_KEY_DYNAMIC . '_' . $apiKey,
                        'APPWRITE_SITE_ID' => $resource->getId(),
                        'APPWRITE_SITE_NAME' => $resource->getAttribute('name'),
                        'APPWRITE_SITE_DEPLOYMENT' => $deployment->getId(),
                        'APPWRITE_SITE_PROJECT_ID' => $project->getId(),
                        'APPWRITE_SITE_RUNTIME_NAME' => $runtime['name'] ?? '',
                        'APPWRITE_SITE_RUNTIME_VERSION' => $runtime['version'] ?? '',
                        'APPWRITE_SITE_CPUS' => $cpus,
                        'APPWRITE_SITE_MEMORY' => $memory,
                    ];
                    break;
            }

            $command = $this->getCommand(
                resource: $resource,
                deployment: $deployment
            );

            $response = null;
            $err = null;

            if ($dbForProject->getDocument('deployments', $deploymentId)->getAttribute('status') === 'canceled') {
                Console::info('Build has been canceled');
                return;
            }

            $isCanceled = false;

            Co::join([
                Co\go(function () use ($executor, &$response, $project, $deployment, $source, $resource, $runtime, $vars, $command, $cpus, $memory, $timeout, &$err, $version) {
                    try {
                        if ($version === 'v2') {
                            $command = 'tar -zxf /tmp/code.tar.gz -C /usr/code && cd /usr/local/src/ && ./build.sh';
                        } else {
                            if ($resource->getCollection() === 'sites') {
                                $listFilesCommand = '';

                                // Start separation, enter build folder
                                $listFilesCommand .= 'echo "{APPWRITE_DETECTION_SEPARATOR_START}" && cd /usr/local/build';

                                // Enter output directory, if set
                                if (!empty($resource->getAttribute('outputDirectory', ''))) {
                                    $listFilesCommand .= ' && cd ' . \escapeshellarg($resource->getAttribute('outputDirectory', ''));
                                }

                                // Print files, and end separation
                                $listFilesCommand .= ' && find . -name \'node_modules\' -prune -o -type f -print && echo "{APPWRITE_DETECTION_SEPARATOR_END}"';

                                // Use SSR file listing
                                if (empty($command)) {
                                    $command = $listFilesCommand;
                                } else {
                                    $command .= ' && ' . $listFilesCommand;
                                }
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
                            remove:  true,
                            entrypoint: $deployment->getAttribute('entrypoint', ''),
                            destination: APP_STORAGE_BUILDS . "/app-{$project->getId()}",
                            variables: $vars,
                            command: $command,
                            outputDirectory: $resource->getAttribute('outputDirectory', '')
                        );
                    } catch (\Throwable $error) {
                        $err = $error;
                    }
                }),
                Co\go(function () use ($executor, $project, &$deployment, &$response, $dbForProject, $timeout, &$err, $queueForRealtime, &$isCanceled) {
                    try {
                        $insideSeparation = false;

                        $executor->getLogs(
                            deploymentId: $deployment->getId(),
                            projectId: $project->getId(),
                            timeout: $timeout,
                            callback: function ($logs) use (&$response, &$err, $dbForProject, &$isCanceled, &$deployment, $queueForRealtime, &$insideSeparation) {
                                if ($isCanceled) {
                                    return;
                                }

                                // If we have response or error from concurrent coroutine, we already have latest logs
                                if ($response === null && $err === null) {
                                    $deployment = $dbForProject->getDocument('deployments', $deployment->getId());

                                    if ($deployment->getAttribute('status') === 'canceled') {
                                        $isCanceled = true;
                                        Console::info('Ignoring realtime logs because build has been canceled');
                                        return;
                                    }

                                    // Get only valid UTF8 part - removes leftover half-multibytes causing SQL errors
                                    $logs = \mb_substr($logs, 0, null, 'UTF-8');

                                    // Do not stream logs added for SSR detection
                                    if (!$insideSeparation) {
                                        $separator = \strpos($logs, '{APPWRITE_DETECTION_SEPARATOR_START}');
                                        if ($separator !== false) {
                                            $logs = \substr($logs, 0, $separator);
                                            $insideSeparation = true;
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

                                    $streamLogs = \str_replace("\\n", "{APPWRITE_LINEBREAK_PLACEHOLDER}", $logs);
                                    foreach (\explode("\n", $streamLogs) as $streamLog) {
                                        if (empty($streamLog)) {
                                            continue;
                                        }

                                        $streamLog = \str_replace("{APPWRITE_LINEBREAK_PLACEHOLDER}", "\n", $streamLog);
                                        $streamParts = \explode(" ", $streamLog, 2);

                                        // TODO: use part[0] as timestamp when switching to dbForLogs for build logs
                                        $currentLogs .= $streamParts[1];

                                        if (!empty($streamParts[1])) {
                                            $affected = true;
                                        }
                                    }

                                    if ($affected) {
                                        $deployment = $deployment->setAttribute('buildLogs', $currentLogs);
                                        $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), $deployment);

                                        $queueForRealtime
                                            ->setPayload($deployment->getArrayCopy())
                                            ->trigger();
                                    }
                                }
                            }
                        );
                    } catch (\Throwable $error) {
                        if (empty($err)) {
                            $err = $error;
                        }
                    }
                }),
            ]);

            if ($dbForProject->getDocument('deployments', $deploymentId)->getAttribute('status') === 'canceled') {
                Console::info('Build has been canceled');
                return;
            }

            if ($err) {
                throw $err;
            }

            $endTime = DateTime::now();
            $durationEnd = \microtime(true);

            $buildSizeLimit = (int)System::getEnv('_APP_COMPUTE_BUILD_SIZE_LIMIT', '2000000000');
            if (isset($plan['buildSize'])) {
                $buildSizeLimit = $plan['buildSize'] * 1000 * 1000;
            }
            if ($response['size'] > $buildSizeLimit && $buildSizeLimit !== 0) {
                throw new \Exception('Build size should be less than ' . number_format($buildSizeLimit / (1000 * 1000), 2) . ' MBs.');
            }

            /** Update the build document */
            $deployment->setAttribute('buildStartedAt', DateTime::format((new \DateTime())->setTimestamp(floor($response['startTime']))));
            $deployment->setAttribute('buildEndedAt', $endTime);
            $deployment->setAttribute('buildDuration', \intval(\ceil($durationEnd - $durationStart)));
            $deployment->setAttribute('buildPath', $response['path']);
            $deployment->setAttribute('buildSize', $response['size']);
            $deployment->setAttribute('totalSize', $deployment->getAttribute('buildSize', 0) + $deployment->getAttribute('sourceSize', 0));

            $logs = '';
            foreach ($response['output'] as $log) {
                $logs .= $log['content'];
            }

            // Separate logs for SSR detection
            $detectionLogs = '';
            $separator = \strpos($logs, '{APPWRITE_DETECTION_SEPARATOR_START}');
            if ($separator !== false) {
                $detectionLogs = \substr($logs, $separator + strlen('{APPWRITE_DETECTION_SEPARATOR}'));
                $separatorEnd = \strpos($detectionLogs, '{APPWRITE_DETECTION_SEPARATOR_END}');
                $logs .= \substr($detectionLogs, $separatorEnd + strlen('{APPWRITE_DETECTION_SEPARATOR_END}'));
                $detectionLogs = \substr($detectionLogs, 0, $separatorEnd);
                $logs = \substr($logs, 0, $separator);
            }

            if ($resource->getCollection() === 'sites') {
                $date = \date('H:i:s');
                $logs .= "[90m[$date] [90m[[0mappwrite[90m][97m Screenshot capturing started. [0m\n";
            }

            $deployment->setAttribute('buildLogs', $logs);

            if ($resource->getCollection() === 'sites' && !empty($detectionLogs)) {
                $files = \explode("\n", $detectionLogs); // Parse output
                $files = \array_filter($files); // Remove empty
                $files = \array_map(fn ($file) => \trim($file), $files); // Remove whitepsaces
                $files = \array_map(fn ($file) => \str_starts_with($file, './') ? \substr($file, 2) : $file, $files); // Remove beginning ./

                $detector = new Rendering($files, $resource->getAttribute('framework', ''));
                $detector
                    ->addOption(new SSR())
                    ->addOption(new XStatic());
                $detection = $detector->detect();

                $adapter = $resource->getAttribute('adapter', '');
                if (empty($adapter)) {
                    $resource->setAttribute('adapter', $detection->getName());
                    $resource->setAttribute('fallbackFile', $detection->getFallbackFile() ?? '');
                    $resource = $dbForProject->updateDocument('sites', $resource->getId(), $resource);

                    $deployment->setAttribute('adapter', $detection->getName());
                    $deployment->setAttribute('fallbackFile', $detection->getFallbackFile() ?? '');
                } elseif ($adapter === 'ssr' && $detection->getName() === 'static') {
                    throw new \Exception('Adapter mismatch. Detected: ' . $detection->getName() . ' does not match with the set adapter: ' . $adapter);
                }
            }

            $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), $deployment);

            $queueForRealtime
                ->setPayload($deployment->getArrayCopy())
                ->trigger();

            /** Screenshot site */
            if ($resource->getCollection() === 'sites') {
                try {
                    $rule = Authorization::skip(fn () => $dbForPlatform->findOne('rules', [
                        Query::equal("projectInternalId", [$project->getInternalId()]),
                        Query::equal("type", ["deployment"]),
                        Query::equal('deploymentInternalId', [$deployment->getInternalId()]),
                    ]));

                    if ($rule->isEmpty()) {
                        throw new \Exception("Rule for build not found");
                    }

                    $client = new FetchClient();
                    $client->setTimeout(\intval($resource->getAttribute('timeout', '15')));
                    $client->addHeader('content-type', FetchClient::CONTENT_TYPE_APPLICATION_JSON);

                    $bucket = Authorization::skip(fn () => $dbForPlatform->getDocument('buckets', 'screenshots'));

                    $configs = [
                        'screenshotLight' => [
                            'headers' => [ 'x-appwrite-hostname' => $rule->getAttribute('domain') ],
                            'url' => 'http://appwrite/?appwrite-preview=1&appwrite-theme=light',
                            'theme' => 'light'
                        ],
                        'screenshotDark' => [
                            'headers' => [ 'x-appwrite-hostname' => $rule->getAttribute('domain') ],
                            'url' => 'http://appwrite/?appwrite-preview=1&appwrite-theme=dark',
                            'theme' => 'dark'
                        ],
                    ];

                    $jwtObj = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 900, 0);
                    $apiKey = $jwtObj->encode([
                        'hostnameOverride' => true,
                        'disabledMetrics' => [
                            METRIC_EXECUTIONS,
                            METRIC_EXECUTIONS_COMPUTE,
                            METRIC_EXECUTIONS_MB_SECONDS,
                            METRIC_NETWORK_REQUESTS,
                            METRIC_NETWORK_INBOUND,
                            METRIC_NETWORK_OUTBOUND,
                            str_replace(["{resourceType}"], [RESOURCE_TYPE_SITES], METRIC_RESOURCE_TYPE_EXECUTIONS),
                            str_replace(["{resourceType}"], [RESOURCE_TYPE_SITES], METRIC_RESOURCE_TYPE_EXECUTIONS_COMPUTE),
                            str_replace(["{resourceType}"], [RESOURCE_TYPE_SITES], METRIC_RESOURCE_TYPE_EXECUTIONS_MB_SECONDS),
                            str_replace(["{resourceType}", "{resourceInternalId}"], [RESOURCE_TYPE_SITES, $resource->getInternalId()], METRIC_RESOURCE_TYPE_ID_EXECUTIONS),
                            str_replace(["{resourceType}", "{resourceInternalId}"], [RESOURCE_TYPE_SITES, $resource->getInternalId()], METRIC_RESOURCE_TYPE_ID_EXECUTIONS_COMPUTE),
                            str_replace(["{resourceType}", "{resourceInternalId}"], [RESOURCE_TYPE_SITES, $resource->getInternalId()], METRIC_RESOURCE_TYPE_ID_EXECUTIONS_MB_SECONDS),
                        ],
                        'bannerDisabled' => true,
                        'projectCheckDisabled' => true,
                        'previewAuthDisabled' => true,
                        'deploymentStatusIgnored' => true
                    ]);

                    $screenshotError = null;
                    $screenshots = batch(\array_map(function ($key) use ($configs, $apiKey, $resource, $client, &$screenshotError) {
                        return function () use ($key, $configs, $apiKey, $resource, $client, &$screenshotError) {
                            try {
                                $config = $configs[$key];

                                $config['headers'] = \array_merge($config['headers'] ?? [], [
                                    'x-appwrite-key' => API_KEY_DYNAMIC . '_' . $apiKey
                                ]);
                                $config['sleep'] = 3000;

                                $frameworks = Config::getParam('frameworks', []);
                                $framework = $frameworks[$resource->getAttribute('framework', '')] ?? null;
                                if (!is_null($framework)) {
                                    $config['sleep'] = $framework['screenshotSleep'];
                                }

                                $fetchResponse = $client->fetch(
                                    url: 'http://appwrite-browser:3000/v1/screenshots',
                                    method: 'POST',
                                    body: $config
                                );

                                if ($fetchResponse->getStatusCode() >= 400) {
                                    throw new \Exception($fetchResponse->getBody());
                                }

                                $screenshot = $fetchResponse->getBody();

                                return ['key' => $key, 'screenshot' => $screenshot];
                            } catch (\Throwable $th) {
                                $screenshotError = $th->getMessage();
                                return;
                            }
                        };
                    }, \array_keys($configs)));

                    if (!\is_null($screenshotError)) {
                        throw new \Exception($screenshotError);
                    }

                    foreach ($screenshots as $data) {
                        $key = $data['key'];
                        $screenshot = $data['screenshot'];

                        $fileId = ID::unique();
                        $fileName = $fileId . '.png';
                        $path = $deviceForFiles->getPath($fileName);
                        $path = str_ireplace($deviceForFiles->getRoot(), $deviceForFiles->getRoot() . DIRECTORY_SEPARATOR . $bucket->getId(), $path); // Add bucket id to path after root
                        $success = $deviceForFiles->write($path, $screenshot, "image/png");

                        if (!$success) {
                            throw new \Exception("Screenshot failed to save");
                        }

                        $teamId = $project->getAttribute('teamId', '');
                        $file = new Document([
                            '$id' => $fileId,
                            '$permissions' => [
                                Permission::read(Role::team(ID::custom($teamId))),
                            ],
                            'bucketId' => $bucket->getId(),
                            'bucketInternalId' => $bucket->getInternalId(),
                            'name' => $fileName,
                            'path' => $path,
                            'signature' => $deviceForFiles->getFileHash($path),
                            'mimeType' => $deviceForFiles->getFileMimeType($path),
                            'sizeOriginal' => \strlen($screenshot),
                            'sizeActual' => $deviceForFiles->getFileSize($path),
                            'algorithm' => Compression::GZIP,
                            'comment' => '',
                            'chunksTotal' => 1,
                            'chunksUploaded' => 1,
                            'openSSLVersion' => null,
                            'openSSLCipher' => null,
                            'openSSLTag' => null,
                            'openSSLIV' => null,
                            'search' => implode(' ', [$fileId, $fileName]),
                            'metadata' => ['content_type' => $deviceForFiles->getFileMimeType($path)],
                        ]);

                        Authorization::skip(fn () => $dbForPlatform->createDocument('bucket_' . $bucket->getInternalId(), $file));

                        $deployment->setAttribute($key, $fileId);
                    }

                    $logs = $deployment->getAttribute('buildLogs', '');
                    $date = \date('H:i:s');
                    $logs .= "[90m[$date] [90m[[0mappwrite[90m][97m Screenshot capturing finished. [0m\n";

                    $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), $deployment);

                    $queueForRealtime
                        ->setPayload($deployment->getArrayCopy())
                        ->trigger();
                } catch (\Throwable $th) {
                    Console::warning("Screenshot failed to generate:");
                    Console::warning($th->getMessage());
                    Console::warning($th->getTraceAsString());

                    $logs = $deployment->getAttribute('buildLogs', '');
                    $date = \date('H:i:s');
                    $logs .= "[90m[$date] [90m[[0mappwrite[90m][33m Screenshot capturing failed. Deployment will continue. [0m\n";

                    $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), $deployment);
                }
            }

            $logs = $deployment->getAttribute('buildLogs', '');
            $date = \date('H:i:s');
            $logs .= "[90m[$date] [90m[[0mappwrite[90m][32m Deployment finished. [0m\n";
            $deployment->setAttribute('buildLogs', $logs);

            /** Update the status */
            $deployment->setAttribute('status', 'ready');
            $deployment = $dbForProject->updateDocument('deployments', $deploymentId, $deployment);

            if ($deployment->getInternalId() === $resource->getAttribute('latestDeploymentInternalId', '')) {
                $resource = $resource->setAttribute('latestDeploymentStatus', $deployment->getAttribute('status', ''));
                $dbForProject->updateDocument($resource->getCollection(), $resource->getId(), $resource);
            }

            $queueForRealtime
                ->setPayload($deployment->getArrayCopy())
                ->trigger();

            if ($isVcsEnabled) {
                $this->runGitAction('ready', $github, $providerCommitHash, $owner, $repositoryName, $project, $resource, $deployment->getId(), $dbForProject, $dbForPlatform);
            }

            Console::success("Build id: $deploymentId created");

            /** Set auto deploy */
            if ($deployment->getAttribute('activate') === true) {
                // Check if current active deployment started later than this deployment
                $currentDeploymentId = $resource->getAttribute('deploymentId', '');
                if (!empty($currentDeploymentId)) {
                    $currentDeployment = $dbForProject->getDocument('deployments', $currentDeploymentId);
                    if (!$currentDeployment->isEmpty()) {
                        $currentStartTime = $currentDeployment->getAttribute('buildStartedAt', '');
                        $newStartTime = $deployment->getAttribute('buildStartedAt', '');

                        if (!empty($currentStartTime) && !empty($newStartTime) && $currentStartTime > $newStartTime) {
                            Console::info('Skipping auto-activation as current deployment started later');
                            return;
                        }
                    }
                }

                $resource->setAttribute('live', true);
                switch ($resource->getCollection()) {
                    case 'functions':
                        $resource->setAttribute('deploymentId', $deployment->getId());
                        $resource->setAttribute('deploymentInternalId', $deployment->getInternalId());
                        $resource->setAttribute('deploymentCreatedAt', $deployment->getCreatedAt());
                        $resource = $dbForProject->updateDocument('functions', $resource->getId(), $resource);

                        $queries = [
                            Query::equal("projectInternalId", [$project->getInternalId()]),
                            Query::equal("type", ["deployment"]),
                            Query::equal("deploymentResourceInternalId", [$resource->getInternalId()]),
                            Query::equal('deploymentResourceType', ['function']),
                            Query::equal('trigger', ['manual']),
                            Query::equal('deploymentVcsProviderBranch', ['']),
                            Query::equal("projectInternalId", [$project->getInternalId()])
                        ];

                        $rulesUpdated = false;
                        $dbForPlatform->forEach('rules', function (Document $rule) use ($dbForPlatform, $deployment, &$rulesUpdated) {
                            $rulesUpdated = true;
                            $rule = $rule
                                ->setAttribute('deploymentId', $deployment->getId())
                                ->setAttribute('deploymentInternalId', $deployment->getInternalId());
                            $dbForPlatform->updateDocument('rules', $rule->getId(), $rule);
                        }, $queries);
                        break;
                    case 'sites':
                        $resource->setAttribute('deploymentId', $deployment->getId());
                        $resource->setAttribute('deploymentInternalId', $deployment->getInternalId());
                        $resource->setAttribute('deploymentScreenshotDark', $deployment->getAttribute('screenshotDark', ''));
                        $resource->setAttribute('deploymentScreenshotLight', $deployment->getAttribute('screenshotLight', ''));
                        $resource->setAttribute('deploymentCreatedAt', $deployment->getCreatedAt());
                        $resource = $dbForProject->updateDocument('sites', $resource->getId(), $resource);
                        $queries = [
                            Query::equal("projectInternalId", [$project->getInternalId()]),
                            Query::equal("type", ["deployment"]),
                            Query::equal("deploymentResourceInternalId", [$resource->getInternalId()]),
                            Query::equal('deploymentResourceType', ['site']),
                            Query::equal('trigger', ['manual']),
                            Query::equal('deploymentVcsProviderBranch', ['']),
                            Query::equal("projectInternalId", [$project->getInternalId()])
                        ];

                        $dbForPlatform->forEach('rules', function (Document $rule) use ($dbForPlatform, $deployment) {
                            $rule = $rule
                                ->setAttribute('deploymentId', $deployment->getId())
                                ->setAttribute('deploymentInternalId', $deployment->getInternalId());
                            $dbForPlatform->updateDocument('rules', $rule->getId(), $rule);
                        }, $queries);

                        break;
                }
            }

            if ($resource->getCollection() === 'sites') {
                // VCS branch
                $branchName = $deployment->getAttribute('providerBranch');
                if (!empty($branchName)) {
                    $sitesDomain = System::getEnv('_APP_DOMAIN_SITES', '');
                    $branchPrefix = substr($branchName, 0, 16);
                    if (strlen($branchName) > 16) {
                        $remainingChars = substr($branchName, 16);
                        $branchPrefix .= '-' . substr(hash('sha256', $remainingChars), 0, 7);
                    }
                    $resourceProjectHash = substr(hash('sha256', $resource->getId() . $project->getId()), 0, 7);
                    $domain = "branch-{$branchPrefix}-{$resourceProjectHash}.{$sitesDomain}";
                    $ruleId = md5($domain);

                    try {
                        $dbForPlatform->createDocument('rules', new Document([
                            '$id' => $ruleId,
                            'projectId' => $project->getId(),
                            'projectInternalId' => $project->getInternalId(),
                            'domain' => $domain,
                            'type' => 'deployment',
                            'trigger' => 'deployment',
                            'deploymentId' => $deployment->getId(),
                            'deploymentInternalId' => $deployment->getInternalId(),
                            'deploymentResourceType' => 'site',
                            'deploymentResourceId' => $deployment->getId(),
                            'deploymentResourceInternalId' => $deployment->getInternalId(),
                            'deploymentVcsProviderBranch' => $branchName,
                            'status' => 'verified',
                            'certificateId' => '',
                            'search' => implode(' ', [$ruleId, $domain]),
                            'owner' => 'Appwrite',
                            'region' => $project->getAttribute('region')
                        ]));
                    } catch (Duplicate $err) {
                        $rule = $dbForPlatform->getDocument('rules', $ruleId);
                        $rule = $rule
                            ->setAttribute('deploymentId', $deployment->getId())
                            ->setAttribute('deploymentInternalId', $deployment->getInternalId());
                        $dbForPlatform->updateDocument('rules', $rule->getId(), $rule);
                    }

                    $queries = [
                        Query::equal("projectInternalId", [$project->getInternalId()]),
                        Query::equal("type", ["deployment"]),
                        Query::equal("deploymentResourceInternalId", [$resource->getInternalId()]),
                        Query::equal('deploymentResourceType', ['site']),
                        Query::equal("deploymentVcsProviderBranch", [$branchName]),
                        Query::equal("trigger", ['manual']),
                        Query::equal("projectInternalId", [$project->getInternalId()])
                    ];

                    $dbForPlatform->foreach('rules', function (Document $rule) use ($dbForPlatform, $deployment) {
                        $rule = $rule
                                ->setAttribute('deploymentId', $deployment->getId())
                                ->setAttribute('deploymentInternalId', $deployment->getInternalId());
                        $dbForPlatform->updateDocument('rules', $rule->getId(), $rule);
                    }, $queries);
                }
            }

            if ($dbForProject->getDocument('deployments', $deploymentId)->getAttribute('status') === 'canceled') {
                Console::info('Build has been canceled');
                return;
            }

            /** Update function schedule */

            // Inform scheduler if function is still active
            if ($resource->getCollection() === 'functions') {
                $schedule = $dbForPlatform->getDocument('schedules', $resource->getAttribute('scheduleId'));
                $schedule
                    ->setAttribute('resourceUpdatedAt', DateTime::now())
                    ->setAttribute('schedule', $resource->getAttribute('schedule'))
                    ->setAttribute('active', !empty($resource->getAttribute('schedule')) && !empty($resource->getAttribute('deploymentId')));
                Authorization::skip(fn () => $dbForPlatform->updateDocument('schedules', $schedule->getId(), $schedule));
            }
        } catch (\Throwable $th) {
            Console::warning('Build failed:');
            Console::error($th->getMessage());
            Console::error($th->getFile());
            Console::error($th->getLine());
            Console::error($th->getTraceAsString());

            if ($dbForProject->getDocument('deployments', $deploymentId)->getAttribute('status') === 'canceled') {
                Console::info('Build has been canceled');
                return;
            }

            // Color message red
            $message = $th->getMessage();
            if (!\str_contains($message, '')) {
                $message = "[31m" . $message;
            }

            $separator = \strpos($message, '{APPWRITE_DETECTION_SEPARATOR_START}');
            if ($separator !== false) {
                $error = \substr($message, $separator + strlen('{APPWRITE_DETECTION_SEPARATOR_START}'));
                $message = \substr($message, 0, $separator);
                $message .= "\n[31m" . $error;
            }

            // Combine with previous logs if deployment got past build process
            $previousLogs = '';
            if (!empty($deployment->getAttribute('buildEndedAt', ''))) {
                $previousLogs = $deployment->getAttribute('buildLogs', '');
                if (!empty($previousLogs)) {
                    $message = $previousLogs . "\n" . $message;
                }
            }

            $endTime = DateTime::now();
            $durationEnd = \microtime(true);
            $deployment->setAttribute('buildEndedAt', $endTime);
            $deployment->setAttribute('buildDuration', \intval(\ceil($durationEnd - $durationStart)));
            $deployment->setAttribute('status', 'failed');

            $deployment->setAttribute('buildLogs', $message);
            $deployment = $dbForProject->updateDocument('deployments', $deploymentId, $deployment);

            if ($deployment->getInternalId() === $resource->getAttribute('latestDeploymentInternalId', '')) {
                $resource = $resource->setAttribute('latestDeploymentStatus', $deployment->getAttribute('status', ''));
                $dbForProject->updateDocument($resource->getCollection(), $resource->getId(), $resource);
            }

            $queueForRealtime
                ->setPayload($deployment->getArrayCopy())
                ->trigger();

            if ($isVcsEnabled) {
                $this->runGitAction('failed', $github, $providerCommitHash, $owner, $repositoryName, $project, $resource, $deployment->getId(), $dbForProject, $dbForPlatform);
            }
        } finally {
            $queueForRealtime
                ->setPayload($deployment->getArrayCopy())
                ->trigger();

            $this->sendUsage(
                resource:$resource,
                deployment: $deployment,
                project: $project,
                queue: $queueForStatsUsage
            );
        }
    }

    protected function sendUsage(Document $resource, Document $deployment, Document $project, StatsUsage $queue): void
    {
        switch ($deployment->getAttribute('status')) {
            case 'ready':
                $queue
                    ->addMetric(METRIC_BUILDS_SUCCESS, 1) // per project
                    ->addMetric(METRIC_BUILDS_COMPUTE_SUCCESS, (int)$deployment->getAttribute('buildDuration', 0) * 1000)
                    ->addMetric(str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_BUILDS_SUCCESS), 1) // per function
                    ->addMetric(str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_BUILDS_COMPUTE_SUCCESS), (int)$deployment->getAttribute('buildDuration', 0) * 1000)
                    ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getInternalId()], METRIC_RESOURCE_TYPE_ID_BUILDS_SUCCESS), 1) // per function
                    ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getInternalId()], METRIC_RESOURCE_TYPE_ID_BUILDS_COMPUTE_SUCCESS), (int)$deployment->getAttribute('buildDuration', 0) * 1000);
                break;
            case 'failed':
                $queue
                    ->addMetric(METRIC_BUILDS_FAILED, 1) // per project
                    ->addMetric(METRIC_BUILDS_COMPUTE_FAILED, (int)$deployment->getAttribute('buildDuration', 0) * 1000)
                    ->addMetric(str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_BUILDS_FAILED), 1) // per function
                    ->addMetric(str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_BUILDS_COMPUTE_FAILED), (int)$deployment->getAttribute('buildDuration', 0) * 1000)
                    ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getInternalId()], METRIC_RESOURCE_TYPE_ID_BUILDS_FAILED), 1) // per function
                    ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getInternalId()], METRIC_RESOURCE_TYPE_ID_BUILDS_COMPUTE_FAILED), (int)$deployment->getAttribute('buildDuration', 0) * 1000);
                break;
        }

        $queue
            ->addMetric(METRIC_BUILDS, 1) // per project
            ->addMetric(METRIC_BUILDS_STORAGE, $deployment->getAttribute('buildSize', 0))
            ->addMetric(METRIC_BUILDS_COMPUTE, (int)$deployment->getAttribute('buildDuration', 0) * 1000)
            ->addMetric(METRIC_BUILDS_MB_SECONDS, (int)(($spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT) * $deployment->getAttribute('buildDuration', 0) * ($spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT)))
            ->addMetric(str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_BUILDS), 1) // per function
            ->addMetric(str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_BUILDS_STORAGE), $deployment->getAttribute('buildSize', 0))
            ->addMetric(str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_BUILDS_COMPUTE), (int)$deployment->getAttribute('buildDuration', 0) * 1000)
            ->addMetric(str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_BUILDS_MB_SECONDS), (int)(($spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT) * $deployment->getAttribute('buildDuration', 0) * ($spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT)))
            ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getInternalId()], METRIC_RESOURCE_TYPE_ID_BUILDS), 1) // per function
            ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getInternalId()], METRIC_RESOURCE_TYPE_ID_BUILDS_STORAGE), $deployment->getAttribute('buildSize', 0))
            ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getInternalId()], METRIC_RESOURCE_TYPE_ID_BUILDS_COMPUTE), (int)$deployment->getAttribute('buildDuration', 0) * 1000)
            ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getInternalId()], METRIC_RESOURCE_TYPE_ID_BUILDS_MB_SECONDS), (int)(($spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT) * $deployment->getAttribute('buildDuration', 0) * ($spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT)))
            ->setProject($project)
            ->trigger();
    }

    protected function getRuntime(Document $resource, string $version): array
    {
        $runtimes = Config::getParam($version === 'v2' ? 'runtimes-v2' : 'runtimes', []);
        $key =  $resource->getAttribute('runtime');
        $runtime = match ($resource->getCollection()) {
            'functions' => $runtimes[$resource->getAttribute('runtime')] ?? null,
            'sites' => $runtimes[$resource->getAttribute('buildRuntime')] ?? null,
            default => null
        };
        if (\is_null($runtime)) {
            throw new \Exception('Runtime "' . $resource->getAttribute('runtime', '') . '" is not supported');
        }

        return $runtime;
    }

    protected function getVersion(Document $resource): string
    {
        return match ($resource->getCollection()) {
            'functions' => $resource->getAttribute('version', 'v2'),
            'sites' => 'v5',
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
            if (!is_null($framework)) {
                $envCommand = $framework['envCommand'] ?? '';
                $bundleCommand = $framework['bundleCommand'] ?? '';
            }

            $commands[] = $envCommand;
            $commands[] = $deployment->getAttribute('buildCommands', '');
            $commands[] = $bundleCommand;

            $commands = array_filter($commands, fn ($command) => !empty($command));

            return implode(' && ', $commands);
        }

        return '';
    }

    /**
     * @param string $status
     * @param GitHub $github
     * @param string $providerCommitHash
     * @param string $owner
     * @param string $repositoryName
     * @param Document $project
     * @param Document $resource
     * @param string $deploymentId
     * @param Database $dbForProject
     * @param Database $dbForPlatform
     * @return void
     * @throws Structure
     * @throws \Utopia\Database\Exception
     * @throws Authorization
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
        Database $dbForPlatform
    ): void {
        if ($resource->getAttribute('providerSilentMode', false) === true) {
            return;
        }

        $deployment = $dbForProject->getDocument('deployments', $deploymentId);
        $commentId = $deployment->getAttribute('providerCommentId', '');

        if (!empty($providerCommitHash)) {
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
            $hostname = System::getEnv('_APP_DOMAIN');

            $projectId = $project->getId();
            $resourceId = $resource->getId();
            $providerTargetUrl = match ($resource->getCollection()) {
                'functions' => "{$protocol}://{$hostname}/console/project-{$projectId}/functions/function-{$resourceId}",
                'sites' => "{$protocol}://{$hostname}/console/project-{$projectId}/sites/site-{$resourceId}",
                default => throw new \Exception('Invalid resource type')
            };

            $github->updateCommitStatus($repositoryName, $providerCommitHash, $owner, $state, $message, $providerTargetUrl, $name);
        }

        if (!empty($commentId)) {
            $retries = 0;

            while (true) {
                $retries++;

                try {
                    $dbForPlatform->createDocument('vcsCommentLocks', new Document([
                        '$id' => $commentId
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
                $resourceType = match($resource->getCollection()) {
                    'functions' => 'function',
                    'sites' => 'site',
                    default => throw new \Exception('Invalid resource type')
                };

                $rule = Authorization::skip(fn () => $dbForPlatform->findOne('rules', [
                    Query::equal("projectInternalId", [$project->getInternalId()]),
                    Query::equal("type", ["deployment"]),
                    Query::equal("deploymentInternalId", [$deployment->getInternalId()]),
                ]));

                $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') == 'disabled' ? 'http' : 'https';
                $previewUrl = match($resource->getCollection()) {
                    'functions' => '',
                    'sites' => !empty($rule) ? ("{$protocol}://" . $rule->getAttribute('domain', '')) : '',
                    default => throw new \Exception('Invalid resource type')
                };

                $comment = new Comment();
                $comment->parseComment($github->getComment($owner, $repositoryName, $commentId));
                $comment->addBuild($project, $resource, $resourceType, $status, $deployment->getId(), ['type' => 'logs'], $previewUrl);
                $github->updateComment($owner, $repositoryName, $commentId, $comment->generateComment());
            } finally {
                $dbForPlatform->deleteDocument('vcsCommentLocks', $commentId);
            }
        }
    }
}
