<?php

namespace Appwrite\Platform\Modules\Functions\Workers;

use Ahc\Jwt\JWT;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Usage;
use Appwrite\Messaging\Adapter\Realtime;
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
use Utopia\Database\Exception\Restricted;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Authorization;
use Utopia\Logger\Log;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
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
            ->inject('message')
            ->inject('dbForConsole')
            ->inject('queueForEvents')
            ->inject('queueForFunctions')
            ->inject('queueForUsage')
            ->inject('cache')
            ->inject('dbForProject')
            ->inject('deviceForFunctions')
            ->inject('log')
            ->callback([$this, 'action']);
    }

    /**
     * @param Message $message
     * @param Database $dbForConsole
     * @param Event $queueForEvents
     * @param Func $queueForFunctions
     * @param Usage $queueForUsage
     * @param Cache $cache
     * @param Database $dbForProject
     * @param Device $deviceForFunctions
     * @param Log $log
     * @return void
     * @throws \Utopia\Database\Exception
     */
    public function action(Message $message, Database $dbForConsole, Event $queueForEvents, Func $queueForFunctions, Usage $queueForUsage, Cache $cache, Database $dbForProject, Device $deviceForFunctions, Log $log): void
    {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new \Exception('Missing payload');
        }

        $type = $payload['type'] ?? '';
        $project = new Document($payload['project'] ?? []);
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
                $this->buildDeployment($deviceForFunctions, $queueForFunctions, $queueForEvents, $queueForUsage, $dbForConsole, $dbForProject, $github, $project, $resource, $deployment, $template, $log);
                break;

            default:
                throw new \Exception('Invalid build type');
        }
    }

    /**
     * @param Device $deviceForFunctions
     * @param Func $queueForFunctions
     * @param Event $queueForEvents
     * @param Usage $queueForUsage
     * @param Database $dbForConsole
     * @param Database $dbForProject
     * @param GitHub $github
     * @param Document $project
     * @param Document $resource
     * @param Document $deployment
     * @param Document $template
     * @param Log $log
     * @return void
     * @throws \Utopia\Database\Exception
     *
     * @throws Exception
     */
    protected function buildDeployment(Device $deviceForFunctions, Func $queueForFunctions, Event $queueForEvents, Usage $queueForUsage, Database $dbForConsole, Database $dbForProject, GitHub $github, Document $project, Document $resource, Document $deployment, Document $template, Log $log): void
    {
        $resourceKey = match($resource->getCollection()) {
            'functions' => 'functionId',
            'sites' => 'siteId',
            default => throw new \Exception('Invalid resource type')
        };

        $executor = new Executor(System::getEnv('_APP_EXECUTOR_HOST'));

        $log->addTag($resourceKey, $resource->getId());

        $resource = $dbForProject->getDocument($resource->getCollection(), $resource->getId());
        if ($resource->isEmpty()) {
            throw new \Exception('Function not found', 404);
        }

        $log->addTag('deploymentId', $deployment->getId());

        $deployment = $dbForProject->getDocument('deployments', $deployment->getId());
        if ($deployment->isEmpty()) {
            throw new \Exception('Deployment not found', 404);
        }

        // todo: figure out a better way, entrypoint is not required for sites
        if ($resource->getCollection() === 'functions' && empty($deployment->getAttribute('entrypoint', ''))) {
            throw new \Exception('Entrypoint for your Appwrite Function is missing. Please specify it when making deployment or update the entrypoint under your function\'s "Settings" > "Configuration" > "Entrypoint".', 500);
        }

        $version = $this->getVersion($resource);
        $runtime = $this->getRuntime($resource, $version);
        $spec = Config::getParam('runtime-specifications')[$resource->getAttribute('specifications', APP_COMPUTE_SPECIFICATION_DEFAULT)];

        // Realtime preparation
        $allEvents = Event::generateEvents("{$resource->getCollection()}.[{$resourceKey}].deployments.[deploymentId].update", [
            $resourceKey => $resource->getId(),
            'deploymentId' => $deployment->getId()
        ]);

        $startTime = DateTime::now();
        $durationStart = \microtime(true);
        $buildId = $deployment->getAttribute('buildId', '');
        $build = $dbForProject->getDocument('builds', $buildId);
        $isNewBuild = empty($buildId);
        if ($build->isEmpty()) {
            $buildId = ID::unique();
            $build = $dbForProject->createDocument('builds', new Document([
                '$id' => $buildId,
                '$permissions' => [],
                'startTime' => $startTime,
                'deploymentInternalId' => $deployment->getInternalId(),
                'deploymentId' => $deployment->getId(),
                'status' => 'processing',
                'path' => '',
                'runtime' => $resource->getAttribute('runtime'),
                'source' => $deployment->getAttribute('path', ''),
                'sourceType' => strtolower($deviceForFunctions->getType()),
                'logs' => '',
                'endTime' => null,
                'duration' => 0,
                'size' => 0
            ]));

            $deployment->setAttribute('buildId', $build->getId());
            $deployment->setAttribute('buildInternalId', $build->getInternalId());
            $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), $deployment);
        } elseif ($build->getAttribute('status') === 'canceled') {
            Console::info('Build has been canceled');
            return;
        } else {
            $build = $dbForProject->getDocument('builds', $buildId);
        }

        $source = $deployment->getAttribute('path', '');
        $installationId = $deployment->getAttribute('installationId', '');
        $providerRepositoryId = $deployment->getAttribute('providerRepositoryId', '');
        $providerCommitHash = $deployment->getAttribute('providerCommitHash', '');
        $isVcsEnabled = !empty($providerRepositoryId);
        $owner = '';
        $repositoryName = '';

        if ($isVcsEnabled) {
            $installation = $dbForConsole->getDocument('installations', $installationId);
            $providerInstallationId = $installation->getAttribute('providerInstallationId');
            $privateKey = System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
            $githubAppId = System::getEnv('_APP_VCS_GITHUB_APP_ID');

            $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);
        }

        try {
            if ($isNewBuild && !$isVcsEnabled) {
                // Non-vcs+Template

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
                    $tmpTemplateDirectory = '/tmp/builds/' . $buildId . '-template';
                    $gitCloneCommandForTemplate = $github->generateCloneCommand($templateOwnerName, $templateRepositoryName, $templateVersion, GitHub::CLONE_TYPE_TAG, $tmpTemplateDirectory, $templateRootDirectory);
                    $exit = Console::execute($gitCloneCommandForTemplate, '', $stdout, $stderr);

                    if ($exit !== 0) {
                        throw new \Exception('Unable to clone code repository: ' . $stderr);
                    }

                    // Ensure directories
                    Console::execute('mkdir -p ' . \escapeshellarg($tmpTemplateDirectory . '/' . $templateRootDirectory), '', $stdout, $stderr);

                    $tmpPathFile = $tmpTemplateDirectory . '/code.tar.gz';

                    $localDevice = new Local();

                    if (substr($tmpTemplateDirectory, -1) !== '/') {
                        $tmpTemplateDirectory .= '/';
                    }

                    $tarParamDirectory = \escapeshellarg($tmpTemplateDirectory . (empty($templateRootDirectory) ? '' : '/' . $templateRootDirectory));
                    Console::execute('tar --exclude code.tar.gz -czf ' . \escapeshellarg($tmpPathFile) . ' -C ' . \escapeshellcmd($tarParamDirectory) . ' .', '', $stdout, $stderr); // TODO: Replace escapeshellcmd with escapeshellarg if we find a way that doesnt break syntax

                    $source = $deviceForFunctions->getPath($deployment->getId() . '.' . \pathinfo('code.tar.gz', PATHINFO_EXTENSION));
                    $result = $localDevice->transfer($tmpPathFile, $source, $deviceForFunctions);

                    if (!$result) {
                        throw new \Exception("Unable to move file");
                    }

                    Console::execute('rm -rf ' . \escapeshellarg($tmpTemplateDirectory), '', $stdout, $stderr);

                    $directorySize = $deviceForFunctions->getFileSize($source);
                    $build = $dbForProject->updateDocument('builds', $build->getId(), $build->setAttribute('source', $source));
                    $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), $deployment->setAttribute('path', $source)->setAttribute('size', $directorySize));
                }
            } elseif ($isNewBuild && $isVcsEnabled) {
                // VCS and VCS+Temaplte
                $tmpDirectory = '/tmp/builds/' . $buildId . '/code';
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

                Console::execute('mkdir -p ' . \escapeshellarg('/tmp/builds/' . $buildId), '', $stdout, $stderr);

                if ($dbForProject->getDocument('builds', $buildId)->getAttribute('status') === 'canceled') {
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
                    $tmpTemplateDirectory = '/tmp/builds/' . $buildId . '/template';

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

                    /**
                     * Send realtime Event
                     */
                    $target = Realtime::fromPayload(
                        // Pass first, most verbose event pattern
                        event: $allEvents[0],
                        payload: $build,
                        project: $project
                    );
                    Realtime::send(
                        projectId: 'console',
                        payload: $build->getArrayCopy(),
                        events: $allEvents,
                        channels: $target['channels'],
                        roles: $target['roles']
                    );
                }

                $tmpPath = '/tmp/builds/' . $buildId;
                $tmpPathFile = $tmpPath . '/code.tar.gz';
                $localDevice = new Local();

                if (substr($tmpDirectory, -1) !== '/') {
                    $tmpDirectory .= '/';
                }

                $directorySize = $localDevice->getDirectorySize($tmpDirectory);
                $sizeLimit = (int)System::getEnv('_APP_COMPUTE_SIZE_LIMIT', '30000000');

                if ($directorySize > $sizeLimit) {
                    throw new \Exception('Repository directory size should be less than ' . number_format($sizeLimit / 1048576, 2) . ' MBs.');
                }

                $tarParamDirectory = '/tmp/builds/' . $buildId . '/code' . (empty($rootDirectory) ? '' : '/' . $rootDirectory);
                Console::execute('tar --exclude code.tar.gz -czf ' . \escapeshellarg($tmpPathFile) . ' -C ' . \escapeshellcmd($tarParamDirectory) . ' .', '', $stdout, $stderr); // TODO: Replace escapeshellcmd with escapeshellarg if we find a way that doesnt break syntax

                $source = $deviceForFunctions->getPath($deployment->getId() . '.' . \pathinfo('code.tar.gz', PATHINFO_EXTENSION));
                $result = $localDevice->transfer($tmpPathFile, $source, $deviceForFunctions);

                if (!$result) {
                    throw new \Exception("Unable to move file");
                }

                Console::execute('rm -rf ' . \escapeshellarg($tmpPath), '', $stdout, $stderr);

                $build = $dbForProject->updateDocument('builds', $build->getId(), $build->setAttribute('source', $source));

                $directorySize = $deviceForFunctions->getFileSize($source);
                $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), $deployment->setAttribute('path', $source)->setAttribute('size', $directorySize));

                $this->runGitAction('processing', $github, $providerCommitHash, $owner, $repositoryName, $project, $resource, $deployment->getId(), $dbForProject, $dbForConsole);
            }

            /** Request the executor to build the code... */
            $build->setAttribute('status', 'building');
            $build = $dbForProject->updateDocument('builds', $buildId, $build);

            if ($isVcsEnabled) {
                $this->runGitAction('building', $github, $providerCommitHash, $owner, $repositoryName, $project, $resource, $deployment->getId(), $dbForProject, $dbForConsole);
            }

            /** Trigger Webhook */
            $deploymentModel = new Deployment();
            $deploymentUpdate =
                $queueForEvents
                    ->setQueue(Event::WEBHOOK_QUEUE_NAME)
                    ->setClass(Event::WEBHOOK_CLASS_NAME)
                    ->setProject($project)
                    ->setEvent("{$resource->getCollection()}.[{$resourceKey}].deployments.[deploymentId].update")
                    ->setParam($resourceKey, $resource->getId())
                    ->setParam('deploymentId', $deployment->getId())
                    ->setPayload($deployment->getArrayCopy(array_keys($deploymentModel->getRules())));

            $deploymentUpdate->trigger();

            /** Trigger Functions */
            $queueForFunctions
                ->from($deploymentUpdate)
                ->trigger();

            /** Trigger Realtime */
            $target = Realtime::fromPayload(
                // Pass first, most verbose event pattern
                event: $allEvents[0],
                payload: $build,
                project: $project
            );

            Realtime::send(
                projectId: 'console',
                payload: $build->getArrayCopy(),
                events: $allEvents,
                channels: $target['channels'],
                roles: $target['roles']
            );

            $vars = [];

            // Shared vars
            foreach ($resource->getAttribute('varsProject', []) as $var) {
                $vars[$var->getAttribute('key')] = $var->getAttribute('value', '');
            }

            // Function vars
            foreach ($resource->getAttribute('vars', []) as $var) {
                $vars[$var->getAttribute('key')] = $var->getAttribute('value', '');
            }

            $cpus = $spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT;
            $memory =  max($spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT, 1024); // We have a minimum of 1024MB here because some runtimes can't compile with less memory than this.
            $timeout = (int) System::getEnv('_APP_COMPUTE_BUILD_TIMEOUT', 900);

            // JWT and API key generation for functions only
            if ($resource->getCollection() === 'functions') {
                $jwtExpiry = (int)System::getEnv('_APP_COMPUTE_BUILD_TIMEOUT', 900);
                $jwtObj = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', $jwtExpiry, 0);

                $apiKey = $jwtObj->encode([
                    'projectId' => $project->getId(),
                    'scopes' => $resource->getAttribute('scopes', [])
                ]);

                $vars = array_merge($vars, ['APPWRITE_FUNCTION_API_KEY' => API_KEY_DYNAMIC . '_' . $apiKey]);
            }

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
                        'APPWRITE_FUNCTION_ID' => $resource->getId(),
                        'APPWRITE_FUNCTION_NAME' => $resource->getAttribute('name'),
                        'APPWRITE_FUNCTION_DEPLOYMENT' => $deployment->getId(),
                        'APPWRITE_FUNCTION_PROJECT_ID' => $project->getId(),
                        'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'] ?? '',
                        'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'] ?? '',
                        'APPWRITE_COMPUTE_CPUS' => $cpus,
                        'APPWRITE_COMPUTE_MEMORY' => $memory
                    ];
                    break;
                case 'sites':
                    $vars = [
                        ...$vars,
                        'APPWRITE_SITE_ID' => $resource->getId(),
                        'APPWRITE_SITE_NAME' => $resource->getAttribute('name'),
                        'APPWRITE_SITE_DEPLOYMENT' => $deployment->getId(),
                        'APPWRITE_SITE_PROJECT_ID' => $project->getId(),
                        'APPWRITE_SITE_RUNTIME_NAME' => $runtime['name'] ?? '',
                        'APPWRITE_SITE_RUNTIME_VERSION' => $runtime['version'] ?? '',
                        'APPWRITE_COMPUTE_CPUS' => $cpus,
                        'APPWRITE_COMPUTE_MEMORY' => $memory
                    ];
                    break;
            }

            $command = $this->getCommand(
                resource: $resource,
                deployment: $deployment
            );

            $response = null;
            $err = null;

            if ($dbForProject->getDocument('builds', $buildId)->getAttribute('status') === 'canceled') {
                Console::info('Build has been canceled');
                return;
            }

            $isCanceled = false;

            Co::join([
                Co\go(function () use ($executor, &$response, $project, $deployment, $source, $resource, $runtime, $vars, $command, $cpus, $memory, $timeout, &$err, $version) {
                    try {
                        $command = $version === 'v2' ? 'tar -zxf /tmp/code.tar.gz -C /usr/code && cd /usr/local/src/ && ./build.sh' : 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "' . \trim(\escapeshellarg($command), "\'") . '"';

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
                            entrypoint: $deployment->getAttribute('entrypoint', 'package.json'), // TODO: change this later so that sites don't need to have an entrypoint
                            destination: APP_STORAGE_BUILDS . "/app-{$project->getId()}",
                            variables: $vars,
                            command: $command,
                            outputDirectory: $resource->getAttribute('outputDirectory', '')
                        );
                    } catch (\Throwable $error) {
                        $err = $error;
                    }
                }),
                Co\go(function () use ($executor, $project, $deployment, &$response, &$build, $dbForProject, $allEvents, $timeout, &$err, &$isCanceled) {
                    try {
                        $executor->getLogs(
                            deploymentId: $deployment->getId(),
                            projectId: $project->getId(),
                            timeout: $timeout,
                            callback: function ($logs) use (&$response, &$err, &$build, $dbForProject, $allEvents, $project, &$isCanceled) {
                                if ($isCanceled) {
                                    return;
                                }

                                // If we have response or error from concurrent coroutine, we already have latest logs
                                if ($response === null && $err === null) {
                                    $build = $dbForProject->getDocument('builds', $build->getId());

                                    if ($build->isEmpty()) {
                                        throw new \Exception('Build not found', 404);
                                    }

                                    if ($build->getAttribute('status') === 'canceled') {
                                        $isCanceled = true;
                                        Console::info('Ignoring realtime logs because build has been canceled');
                                        return;
                                    }

                                    $logs = \mb_substr($logs, 0, null, 'UTF-8'); // Get only valid UTF8 part - removes leftover half-multibytes causing SQL errors

                                    $build = $build->setAttribute('logs', $build->getAttribute('logs', '') . $logs);
                                    $build = $dbForProject->updateDocument('builds', $build->getId(), $build);

                                    /**
                                     * Send realtime Event
                                     */
                                    $target = Realtime::fromPayload(
                                        // Pass first, most verbose event pattern
                                        event: $allEvents[0],
                                        payload: $build,
                                        project: $project
                                    );
                                    Realtime::send(
                                        projectId: 'console',
                                        payload: $build->getArrayCopy(),
                                        events: $allEvents,
                                        channels: $target['channels'],
                                        roles: $target['roles']
                                    );
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

            if ($dbForProject->getDocument('builds', $buildId)->getAttribute('status') === 'canceled') {
                Console::info('Build has been canceled');
                return;
            }

            if ($err) {
                throw $err;
            }

            $endTime = DateTime::now();
            $durationEnd = \microtime(true);

            $buildSizeLimit = (int)System::getEnv('_APP_COMPUTE_BUILD_SIZE_LIMIT', '2000000000');
            if ($response['size'] > $buildSizeLimit) {
                throw new \Exception('Build size should be less than ' . number_format($buildSizeLimit / 1048576, 2) . ' MBs.');
            }

            /** Update the build document */
            $build->setAttribute('startTime', DateTime::format((new \DateTime())->setTimestamp(floor($response['startTime']))));
            $build->setAttribute('endTime', $endTime);
            $build->setAttribute('duration', \intval(\ceil($durationEnd - $durationStart)));
            $build->setAttribute('status', 'ready');
            $build->setAttribute('path', $response['path']);
            $build->setAttribute('size', $response['size']);
            $build->setAttribute('logs', $response['output']);

            $build = $dbForProject->updateDocument('builds', $buildId, $build);

            if ($isVcsEnabled) {
                $this->runGitAction('ready', $github, $providerCommitHash, $owner, $repositoryName, $project, $resource, $deployment->getId(), $dbForProject, $dbForConsole);
            }

            Console::success("Build id: $buildId created");

            /** Set auto deploy */
            if ($deployment->getAttribute('activate') === true) {
                $resource->setAttribute('deploymentInternalId', $deployment->getInternalId());
                $resource->setAttribute('live', true);
                switch ($resource->getCollection()) {
                    case 'functions':
                        $resource->setAttribute('deployment', $deployment->getId());
                        $resource = $dbForProject->updateDocument('functions', $resource->getId(), $resource);
                        break;
                    case 'sites':
                        $resource->setAttribute('deploymentId', $deployment->getId());
                        $resource = $dbForProject->updateDocument('sites', $resource->getId(), $resource);
                        break;
                }
            }

            if ($dbForProject->getDocument('builds', $buildId)->getAttribute('status') === 'canceled') {
                Console::info('Build has been canceled');
                return;
            }

            /** Update function schedule */

            // Inform scheduler if function is still active
            if ($resource->getCollection() === 'functions') {
                $schedule = $dbForConsole->getDocument('schedules', $resource->getAttribute('scheduleId'));
                $schedule
                    ->setAttribute('resourceUpdatedAt', DateTime::now())
                    ->setAttribute('schedule', $resource->getAttribute('schedule'))
                    ->setAttribute('active', !empty($resource->getAttribute('schedule')) && !empty($resource->getAttribute('deployment')));
                Authorization::skip(fn () => $dbForConsole->updateDocument('schedules', $schedule->getId(), $schedule));
            }
        } catch (\Throwable $th) {
            if ($dbForProject->getDocument('builds', $buildId)->getAttribute('status') === 'canceled') {
                Console::info('Build has been canceled');
                return;
            }

            $endTime = DateTime::now();
            $durationEnd = \microtime(true);
            $build->setAttribute('endTime', $endTime);
            $build->setAttribute('duration', \intval(\ceil($durationEnd - $durationStart)));
            $build->setAttribute('status', 'failed');
            $build->setAttribute('logs', $th->getMessage());

            $build = $dbForProject->updateDocument('builds', $buildId, $build);

            if ($isVcsEnabled) {
                $this->runGitAction('failed', $github, $providerCommitHash, $owner, $repositoryName, $project, $resource, $deployment->getId(), $dbForProject, $dbForConsole);
            }
        } finally {
            /**
             * Send realtime Event
             */
            $target = Realtime::fromPayload(
                // Pass first, most verbose event pattern
                event: $allEvents[0],
                payload: $build,
                project: $project
            );
            Realtime::send(
                projectId: 'console',
                payload: $build->getArrayCopy(),
                events: $allEvents,
                channels: $target['channels'],
                roles: $target['roles']
            );
            $this->sendUsage(
                resource:$resource,
                build: $build,
                project: $project,
                queue: $queueForUsage
            );
        }
    }

    protected function sendUsage(Document $resource, Document $build, Document $project, Usage $queue): void
    {
        $key = match($resource->getCollection()) {
            'functions' => 'functionInternalId',
            'sites' => 'siteInternalId',
            default => throw new \Exception('Invalid resource type')
        };

        $metrics = match($resource->getCollection()) {
            'functions' => [
                'builds' => METRIC_FUNCTION_ID_BUILDS,
                'buildsSuccess' => METRIC_FUNCTION_ID_BUILDS_SUCCESS,
                'buildsFailed' => METRIC_FUNCTION_ID_BUILDS_FAILED,
                'buildsComputeSuccess' => METRIC_FUNCTION_ID_BUILDS_COMPUTE_SUCCESS,
                'buildsComputeFailed' => METRIC_FUNCTION_ID_BUILDS_COMPUTE_FAILED,
                'buildsStorage' => METRIC_FUNCTION_ID_BUILDS_STORAGE,
                'buildsCompute' => METRIC_FUNCTION_ID_BUILDS_COMPUTE,
                'buildsMbSeconds' => METRIC_FUNCTION_ID_BUILDS_MB_SECONDS
            ],
            'sites' => [
                'builds' => METRIC_SITES_ID_BUILDS,
                'buildsSuccess' => METRIC_SITES_ID_BUILDS_SUCCESS,
                'buildsFailed' => METRIC_SITES_ID_BUILDS_FAILED,
                'buildsComputeSuccess' => METRIC_SITES_ID_BUILDS_COMPUTE_SUCCESS,
                'buildsComputeFailed' => METRIC_SITES_ID_BUILDS_COMPUTE_FAILED,
                'buildsStorage' => METRIC_SITES_ID_BUILDS_STORAGE,
                'buildsCompute' => METRIC_SITES_ID_BUILDS_COMPUTE,
                'buildsMbSeconds' => METRIC_SITES_ID_BUILDS_MB_SECONDS
            ]
        };

        switch ($build->getAttribute('status')) {
            case 'ready':
                $queue
                    ->addMetric(METRIC_BUILDS_SUCCESS, 1) // per project
                    ->addMetric(METRIC_BUILDS_COMPUTE_SUCCESS, (int)$build->getAttribute('duration', 0) * 1000)
                    ->addMetric(str_replace($key, $resource->getInternalId(), $metrics['buildsSuccess']), 1) // per function
                    ->addMetric(str_replace($key, $resource->getInternalId(), $metrics['buildsComputeSuccess']), (int)$build->getAttribute('duration', 0) * 1000);
                break;
            case 'failed':
                $queue
                    ->addMetric(METRIC_BUILDS_FAILED, 1) // per project
                    ->addMetric(METRIC_BUILDS_COMPUTE_FAILED, (int)$build->getAttribute('duration', 0) * 1000)
                    ->addMetric(str_replace($key, $resource->getInternalId(), $metrics['buildsFailed']), 1) // per function
                    ->addMetric(str_replace($key, $resource->getInternalId(), $metrics['buildsComputeFailed']), (int)$build->getAttribute('duration', 0) * 1000);
                break;
        }

        $queue
            ->addMetric(METRIC_BUILDS, 1) // per project
            ->addMetric(METRIC_BUILDS_STORAGE, $build->getAttribute('size', 0))
            ->addMetric(METRIC_BUILDS_COMPUTE, (int)$build->getAttribute('duration', 0) * 1000)
            ->addMetric(METRIC_BUILDS_MB_SECONDS, (int)(($spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT) * $build->getAttribute('duration', 0) * ($spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT)))
            ->addMetric(str_replace($key, $resource->getInternalId(), $metrics['builds']), 1) // per function
            ->addMetric(str_replace($key, $resource->getInternalId(), $metrics['buildsStorage']), $build->getAttribute('size', 0))
            ->addMetric(str_replace($key, $resource->getInternalId(), $metrics['buildsCompute']), (int)$build->getAttribute('duration', 0) * 1000)
            ->addMetric(str_replace($key, $resource->getInternalId(), $metrics['buildsMbSeconds']), (int)(($spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT) * $build->getAttribute('duration', 0) * ($spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT)))
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
            'sites' => 'v4',
        };
    }

    protected function getCommand(Document $resource, Document $deployment): string
    {
        return match($resource->getCollection()) {
            'functions' => $deployment->getAttribute('commands', ''),
            'sites' => implode(' && ', array_filter([
                $deployment->getAttribute('installCommand'),
                $deployment->getAttribute('buildCommand')
            ]))
        };
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
     * @param Database $dbForConsole
     * @return void
     * @throws Structure
     * @throws \Utopia\Database\Exception
     * @throws Authorization
     * @throws Conflict
     * @throws Restricted
     */
    protected function runGitAction(string $status, GitHub $github, string $providerCommitHash, string $owner, string $repositoryName, Document $project, Document $resource, string $deploymentId, Database $dbForProject, Database $dbForConsole): void
    {
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
            $providerTargetUrl = "{$protocol}://{$hostname}/console/project-{$project->getId()}/functions/function-{$resource->getId()}";

            $github->updateCommitStatus($repositoryName, $providerCommitHash, $owner, $state, $message, $providerTargetUrl, $name);
        }

        if (!empty($commentId)) {
            $retries = 0;

            while (true) {
                $retries++;

                try {
                    $dbForConsole->createDocument('vcsCommentLocks', new Document([
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
                $comment = new Comment();
                $comment->parseComment($github->getComment($owner, $repositoryName, $commentId));
                $comment->addBuild($project, $resource, $status, $deployment->getId(), ['type' => 'logs']);
                $github->updateComment($owner, $repositoryName, $commentId, $comment->generateComment());
            } finally {
                $dbForConsole->deleteDocument('vcsCommentLocks', $commentId);
            }
        }
    }
}
