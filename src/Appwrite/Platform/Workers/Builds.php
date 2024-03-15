<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Usage;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Utopia\Response\Model\Deployment;
use Appwrite\Vcs\Comment;
use Executor\Executor;
use Swoole\Coroutine as Co;
use Utopia\App;
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
            ->callback(fn ($message, Database $dbForConsole, Event $queueForEvents, Func $queueForFunctions, Usage $usage, Cache $cache, Database $dbForProject, Device $deviceForFunctions, Log $log) => $this->action($message, $dbForConsole, $queueForEvents, $queueForFunctions, $usage, $cache, $dbForProject, $deviceForFunctions, $log));
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
     * @param Document $function
     * @param Document $deployment
     * @param Document $template
     * @param Log $log
     * @return void
     * @throws \Utopia\Database\Exception
     * @throws Exception
     */
    protected function buildDeployment(Device $deviceForFunctions, Func $queueForFunctions, Event $queueForEvents, Usage $queueForUsage, Database $dbForConsole, Database $dbForProject, GitHub $github, Document $project, Document $function, Document $deployment, Document $template, Log $log): void
    {
        $executor = new Executor(App::getEnv('_APP_EXECUTOR_HOST'));

        $functionId = $function->getId();
        $log->addTag('functionId', $function->getId());

        $function = $dbForProject->getDocument('functions', $functionId);
        if ($function->isEmpty()) {
            throw new \Exception('Function not found', 404);
        }

        $deploymentId = $deployment->getId();
        $log->addTag('deploymentId', $deploymentId);

        $deployment = $dbForProject->getDocument('deployments', $deploymentId);
        if ($deployment->isEmpty()) {
            throw new \Exception('Deployment not found', 404);
        }

        if (empty($deployment->getAttribute('entrypoint', ''))) {
            throw new \Exception('Entrypoint for your Appwrite Function is missing. Please specify it when making deployment or update the entrypoint under your function\'s "Settings" > "Configuration" > "Entrypoint".', 500);
        }

        $version = $function->getAttribute('version', 'v2');
        $runtimes = Config::getParam($version === 'v2' ? 'runtimes-v2' : 'runtimes', []);
        $key = $function->getAttribute('runtime');
        $runtime = $runtimes[$key] ?? null;
        if (\is_null($runtime)) {
            throw new \Exception('Runtime "' . $function->getAttribute('runtime', '') . '" is not supported');
        }

        // Realtime preparation
        $allEvents = Event::generateEvents('functions.[functionId].deployments.[deploymentId].update', [
            'functionId' => $function->getId(),
            'deploymentId' => $deployment->getId()
        ]);

        $startTime = DateTime::now();
        $durationStart = \microtime(true);
        $buildId = $deployment->getAttribute('buildId', '');
        $isNewBuild = empty($buildId);

        if ($isNewBuild) {
            $buildId = ID::unique();
            $build = $dbForProject->createDocument('builds', new Document([
                '$id' => $buildId,
                '$permissions' => [],
                'startTime' => $startTime,
                'deploymentInternalId' => $deployment->getInternalId(),
                'deploymentId' => $deployment->getId(),
                'status' => 'processing',
                'path' => '',
                'runtime' => $function->getAttribute('runtime'),
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
            $privateKey = App::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
            $githubAppId = App::getEnv('_APP_VCS_GITHUB_APP_ID');

            $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);
        }

        try {
            if ($isNewBuild && $isVcsEnabled) {
                $tmpDirectory = '/tmp/builds/' . $buildId . '/code';
                $rootDirectory = $function->getAttribute('providerRootDirectory', '');
                $rootDirectory = \rtrim($rootDirectory, '/');
                $rootDirectory = \ltrim($rootDirectory, '.');
                $rootDirectory = \ltrim($rootDirectory, '/');

                $owner = $github->getOwnerName($providerInstallationId);
                $repositoryName = $github->getRepositoryName($providerRepositoryId);

                $cloneOwner = $deployment->getAttribute('providerRepositoryOwner', $owner);
                $cloneRepository = $deployment->getAttribute('providerRepositoryName', $repositoryName);

                $branchName = $deployment->getAttribute('providerBranch');
                $commitHash = $deployment->getAttribute('providerCommitHash', '');
                $gitCloneCommand = $github->generateCloneCommand($cloneOwner, $cloneRepository, $branchName, $tmpDirectory, $rootDirectory, $commitHash);
                $stdout = '';
                $stderr = '';
                Console::execute('mkdir -p /tmp/builds/' . \escapeshellcmd($buildId), '', $stdout, $stderr);
                $exit = Console::execute($gitCloneCommand, '', $stdout, $stderr);

                if ($exit !== 0) {
                    throw new \Exception('Unable to clone code repository: ' . $stderr);
                }

                // Build from template
                $templateRepositoryName = $template->getAttribute('repositoryName', '');
                $templateOwnerName = $template->getAttribute('ownerName', '');
                $templateBranch = $template->getAttribute('branch', '');

                $templateRootDirectory = $template->getAttribute('rootDirectory', '');
                $templateRootDirectory = \rtrim($templateRootDirectory, '/');
                $templateRootDirectory = \ltrim($templateRootDirectory, '.');
                $templateRootDirectory = \ltrim($templateRootDirectory, '/');

                if (!empty($templateRepositoryName) && !empty($templateOwnerName) && !empty($templateBranch)) {
                    // Clone template repo
                    $tmpTemplateDirectory = '/tmp/builds/' . \escapeshellcmd($buildId) . '/template';
                    $gitCloneCommandForTemplate = $github->generateCloneCommand($templateOwnerName, $templateRepositoryName, $templateBranch, $tmpTemplateDirectory, $templateRootDirectory);
                    $exit = Console::execute($gitCloneCommandForTemplate, '', $stdout, $stderr);

                    if ($exit !== 0) {
                        throw new \Exception('Unable to clone code repository: ' . $stderr);
                    }

                    // Ensure directories
                    Console::execute('mkdir -p ' . $tmpTemplateDirectory . '/' . $templateRootDirectory, '', $stdout, $stderr);
                    Console::execute('mkdir -p ' . $tmpDirectory . '/' . $rootDirectory, '', $stdout, $stderr);

                    // Merge template into user repo
                    Console::execute('rsync -av --exclude \'.git\' ' . $tmpTemplateDirectory . '/' . $templateRootDirectory . '/ ' . $tmpDirectory . '/' . $rootDirectory, '', $stdout, $stderr);

                    // Commit and push
                    $exit = Console::execute('git config --global user.email "team@appwrite.io" && git config --global user.name "Appwrite" && cd ' . $tmpDirectory . ' && git add . && git commit -m "Create \'' . \escapeshellcmd($function->getAttribute('name', '')) . '\' function" && git push origin ' . \escapeshellcmd($branchName), '', $stdout, $stderr);

                    if ($exit !== 0) {
                        throw new \Exception('Unable to push code repository: ' . $stderr);
                    }

                    $exit = Console::execute('cd ' . $tmpDirectory . ' && git rev-parse HEAD', '', $stdout, $stderr);

                    if ($exit !== 0) {
                        throw new \Exception('Unable to get vcs commit SHA: ' . $stderr);
                    }

                    $providerCommitHash = \trim($stdout);
                    $authorUrl = "https://github.com/$cloneOwner";

                    $deployment->setAttribute('providerCommitHash', $providerCommitHash ?? '');
                    $deployment->setAttribute('providerCommitAuthorUrl', $authorUrl);
                    $deployment->setAttribute('providerCommitAuthor', 'Appwrite');
                    $deployment->setAttribute('providerCommitMessage', "Create '" . $function->getAttribute('name', '') . "' function");
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

                $tmpPath = '/tmp/builds/' . \escapeshellcmd($buildId);
                $tmpPathFile = $tmpPath . '/code.tar.gz';
                $localDevice = new Local();

                if (substr($tmpDirectory, -1) !== '/') {
                    $tmpDirectory .= '/';
                }

                $directorySize = $localDevice->getDirectorySize($tmpDirectory);
                $functionsSizeLimit = (int) App::getEnv('_APP_FUNCTIONS_SIZE_LIMIT', '30000000');
                if ($directorySize > $functionsSizeLimit) {
                    throw new \Exception('Repository directory size should be less than ' . number_format($functionsSizeLimit / 1048576, 2) . ' MBs.');
                }

                Console::execute('tar --exclude code.tar.gz -czf ' . $tmpPathFile . ' -C /tmp/builds/' . \escapeshellcmd($buildId) . '/code' . (empty($rootDirectory) ? '' : '/' . $rootDirectory) . ' .', '', $stdout, $stderr);

                $path = $deviceForFunctions->getPath($deployment->getId() . '.' . \pathinfo('code.tar.gz', PATHINFO_EXTENSION));
                $result = $localDevice->transfer($tmpPathFile, $path, $deviceForFunctions);

                if (!$result) {
                    throw new \Exception("Unable to move file");
                }

                Console::execute('rm -rf ' . $tmpPath, '', $stdout, $stderr);

                $source = $path;

                $build = $dbForProject->updateDocument('builds', $build->getId(), $build->setAttribute('source', $source));

                $this->runGitAction('processing', $github, $providerCommitHash, $owner, $repositoryName, $project, $function, $deployment->getId(), $dbForProject, $dbForConsole);
            }

            /** Request the executor to build the code... */
            $build->setAttribute('status', 'building');
            $build = $dbForProject->updateDocument('builds', $buildId, $build);

            if ($isVcsEnabled) {
                $this->runGitAction('building', $github, $providerCommitHash, $owner, $repositoryName, $project, $function, $deployment->getId(), $dbForProject, $dbForConsole);
            }

            /** Trigger Webhook */
            $deploymentModel = new Deployment();
            $deploymentUpdate =
                $queueForEvents
                    ->setQueue(Event::WEBHOOK_QUEUE_NAME)
                    ->setClass(Event::WEBHOOK_CLASS_NAME)
                    ->setProject($project)
                    ->setEvent('functions.[functionId].deployments.[deploymentId].update')
                    ->setParam('functionId', $function->getId())
                    ->setParam('deploymentId', $deployment->getId())
                    ->setPayload($deployment->getArrayCopy(array_keys($deploymentModel->getRules())))
            ;

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
            foreach ($function->getAttribute('varsProject', []) as $var) {
                $vars[$var->getAttribute('key')] = $var->getAttribute('value', '');
            }

            // Function vars
            foreach ($function->getAttribute('vars', []) as $var) {
                $vars[$var->getAttribute('key')] = $var->getAttribute('value', '');
            }

            // Appwrite vars
            $vars = \array_merge($vars, [
                'APPWRITE_FUNCTION_ID' => $function->getId(),
                'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name'),
                'APPWRITE_FUNCTION_DEPLOYMENT' => $deployment->getId(),
                'APPWRITE_FUNCTION_PROJECT_ID' => $project->getId(),
                'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'] ?? '',
                'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'] ?? '',
            ]);

            $command = $deployment->getAttribute('commands', '');

            $response = null;
            $err = null;

            Co::join([
                Co\go(function () use ($executor, &$response, $project, $deployment, $source, $function, $runtime, $vars, $command, &$err) {
                    try {
                        $version = $function->getAttribute('version', 'v2');
                        $command = $version === 'v2' ? 'tar -zxf /tmp/code.tar.gz -C /usr/code && cd /usr/local/src/ && ./build.sh' : 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "' . \trim(\escapeshellarg($command), "\'") . '"';

                        $response = $executor->createRuntime(
                            deploymentId: $deployment->getId(),
                            projectId: $project->getId(),
                            source: $source,
                            image: $runtime['image'],
                            version: $version,
                            remove: true,
                            entrypoint: $deployment->getAttribute('entrypoint'),
                            destination: APP_STORAGE_BUILDS . "/app-{$project->getId()}",
                            variables: $vars,
                            command: $command
                        );
                    } catch (\Throwable $error) {
                        $err = $error;
                    }
                }),
                Co\go(function () use ($executor, $project, $deployment, &$response, &$build, $dbForProject, $allEvents, &$err) {
                    try {
                        $executor->getLogs(
                            deploymentId: $deployment->getId(),
                            projectId: $project->getId(),
                            callback: function ($logs) use (&$response, &$build, $dbForProject, $allEvents, $project) {
                                if ($response === null) {
                                    $build = $dbForProject->getDocument('builds', $build->getId());

                                    if ($build->isEmpty()) {
                                        throw new \Exception('Build not found', 404);
                                    }

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

            if ($err) {
                throw $err;
            }

            $endTime = DateTime::now();
            $durationEnd = \microtime(true);

            /** Update the build document */
            $build->setAttribute('startTime', DateTime::format((new \DateTime())->setTimestamp(floor($response['startTime']))));
            $build->setAttribute('endTime', $endTime);
            $build->setAttribute('duration', \intval(\ceil($durationEnd - $durationStart)));
            $build->setAttribute('status', 'ready');
            $build->setAttribute('path', $response['path']);
            $build->setAttribute('size', $response['size']);
            $build->setAttribute('logs', $response['output']);

            if ($isVcsEnabled) {
                $this->runGitAction('ready', $github, $providerCommitHash, $owner, $repositoryName, $project, $function, $deployment->getId(), $dbForProject, $dbForConsole);
            }

            Console::success("Build id: $buildId created");

            /** Set auto deploy */
            if ($deployment->getAttribute('activate') === true) {
                $function->setAttribute('deploymentInternalId', $deployment->getInternalId());
                $function->setAttribute('deployment', $deployment->getId());
                $function->setAttribute('live', true);
                $function = $dbForProject->updateDocument('functions', $function->getId(), $function);
            }

            /** Update function schedule */

            // Inform scheduler if function is still active
            $schedule = $dbForConsole->getDocument('schedules', $function->getAttribute('scheduleId'));
            $schedule
                ->setAttribute('resourceUpdatedAt', DateTime::now())
                ->setAttribute('schedule', $function->getAttribute('schedule'))
                ->setAttribute('active', !empty($function->getAttribute('schedule')) && !empty($function->getAttribute('deployment')));
            Authorization::skip(fn () => $dbForConsole->updateDocument('schedules', $schedule->getId(), $schedule));
        } catch (\Throwable $th) {
            $endTime = DateTime::now();
            $durationEnd = \microtime(true);
            $build->setAttribute('endTime', $endTime);
            $build->setAttribute('duration', \intval(\ceil($durationEnd - $durationStart)));
            $build->setAttribute('status', 'failed');
            $build->setAttribute('logs', $th->getMessage() . "\n" . $th->getFile() . ':' . $th->getLine() . "\n" . $th->getTraceAsString());

            if ($isVcsEnabled) {
                $this->runGitAction('failed', $github, $providerCommitHash, $owner, $repositoryName, $project, $function, $deployment->getId(), $dbForProject, $dbForConsole);
            }
        } finally {
            $build = $dbForProject->updateDocument('builds', $buildId, $build);

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

            /** Trigger usage queue */
            $queueForUsage
                ->addMetric(METRIC_BUILDS, 1) // per project
                ->addMetric(METRIC_BUILDS_STORAGE, $build->getAttribute('size', 0))
                ->addMetric(METRIC_BUILDS_COMPUTE, (int)$build->getAttribute('duration', 0) * 1000)
                ->addMetric(str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_BUILDS), 1) // per function
                ->addMetric(str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_BUILDS_STORAGE), $build->getAttribute('size', 0))
                ->addMetric(str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_BUILDS_COMPUTE), (int)$build->getAttribute('duration', 0) * 1000)
                ->setProject($project)
                ->trigger();
        }
    }

    /**
     * @param string $status
     * @param GitHub $github
     * @param string $providerCommitHash
     * @param string $owner
     * @param string $repositoryName
     * @param Document $project
     * @param Document $function
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
    protected function runGitAction(string $status, GitHub $github, string $providerCommitHash, string $owner, string $repositoryName, Document $project, Document $function, string $deploymentId, Database $dbForProject, Database $dbForConsole): void
    {
        if ($function->getAttribute('providerSilentMode', false) === true) {
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

            $functionName = $function->getAttribute('name');
            $projectName = $project->getAttribute('name');

            $name = "{$functionName} ({$projectName})";

            $protocol = App::getEnv('_APP_OPTIONS_FORCE_HTTPS') == 'disabled' ? 'http' : 'https';
            $hostname = App::getEnv('_APP_DOMAIN');
            $functionId = $function->getId();
            $projectId = $project->getId();
            $providerTargetUrl = $protocol . '://' . $hostname . "/console/project-$projectId/functions/function-$functionId";

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
                $comment->addBuild($project, $function, $status, $deployment->getId(), ['type' => 'logs']);
                $github->updateComment($owner, $repositoryName, $commentId, $comment->generateComment());
            } finally {
                $dbForConsole->deleteDocument('vcsCommentLocks', $commentId);
            }
        }
    }
}
