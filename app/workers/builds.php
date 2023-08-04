<?php

use Swoole\Coroutine as Co;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Resque\Worker;
use Appwrite\Utopia\Response\Model\Deployment;
use Executor\Executor;
use Appwrite\Usage\Stats;
use Appwrite\Vcs\Comment;
use Utopia\Database\DateTime;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\ID;
use Utopia\DSN\DSN;
use Utopia\Database\Document;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Storage\Storage;
use Utopia\Database\Validator\Authorization;
use Utopia\VCS\Adapter\Git\GitHub;

require_once __DIR__ . '/../init.php';

Console::title('Builds V1 Worker');
Console::success(APP_NAME . ' build worker v1 has started');

// TODO: Executor should return appropriate response codes.
class BuildsV1 extends Worker
{
    private ?Executor $executor = null;

    public function getName(): string
    {
        return "builds";
    }

    public function init(): void
    {
        $this->executor = new Executor(App::getEnv('_APP_EXECUTOR_HOST'));
    }

    public function run(): void
    {
        $type = $this->args['type'] ?? '';
        $project = new Document($this->args['project'] ?? []);
        $resource = new Document($this->args['resource'] ?? []);
        $deployment = new Document($this->args['deployment'] ?? []);
        $template = new Document($this->args['template'] ?? []);
        $providerCommitHash = $this->args['providerCommitHash'] ?? '';
        $providerTargetUrl = $this->args['providerTargetUrl'] ?? '';
        $providerContribution = new Document($this->args['providerContribution'] ?? []);

        switch ($type) {
            case BUILD_TYPE_DEPLOYMENT:
            case BUILD_TYPE_RETRY:
                Console::info('Creating build for deployment: ' . $deployment->getId());
                $github = new GitHub($this->getCache());
                $this->buildDeployment($github, $project, $resource, $deployment, $template, $providerCommitHash, $providerTargetUrl, $providerContribution);
                break;

            default:
                throw new \Exception('Invalid build type');
                break;
        }
    }

    protected function buildDeployment(GitHub $github, Document $project, Document $function, Document $deployment, Document $template, string $providerCommitHash = '', string $providerTargetUrl = '', Document $providerContribution = null)
    {
        global $register;

        $dbForProject = $this->getProjectDB($project);
        $dbForConsole = $this->getConsoleDB();

        $function = $dbForProject->getDocument('functions', $function->getId());
        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        $deployment = $dbForProject->getDocument('deployments', $deployment->getId());
        if ($deployment->isEmpty()) {
            throw new Exception('Deployment not found', 404);
        }

        $runtimes = Config::getParam('runtimes', []);
        $key = $function->getAttribute('runtime');
        $runtime = isset($runtimes[$key]) ? $runtimes[$key] : null;
        if (\is_null($runtime)) {
            throw new Exception('Runtime "' . $function->getAttribute('runtime', '') . '" is not supported');
        }

        $connection = App::getEnv('_APP_CONNECTIONS_STORAGE', '');
        /** @TODO : move this to the registry or someplace else */
        $device = Storage::DEVICE_LOCAL;
        try {
            $dsn = new DSN($connection);
            $device = $dsn->getScheme();
        } catch (\Exception $e) {
            Console::error($e->getMessage() . 'Invalid DSN. Defaulting to Local device.');
        }

        // Realtime preparation
        $allEvents = Event::generateEvents('functions.[functionId].deployments.[deploymentId].update', [
            'functionId' => $function->getId(),
            'deploymentId' => $deployment->getId()
        ]);

        $startTime = DateTime::now();
        $durationStart = \microtime(true);

        $buildId = $deployment->getAttribute('buildId', '');
        $build = null;

        $isNewBuild = empty($buildId);

        if (empty($buildId)) {
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
                'sourceType' => strtolower(App::getEnv('_APP_STORAGE_DEVICE', Storage::DEVICE_LOCAL)),
                'stdout' => '',
                'stderr' => '',
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
        $isVcsEnabled = $providerRepositoryId ? true : false;
        $owner = '';
        $repositoryName = '';
        $branchName = '';

        try {
            if ($isNewBuild) {
                if ($isVcsEnabled) {
                    $installation = $dbForConsole->getDocument('installations', $installationId, [
                        Query::equal('projectInternalId', [$project->getInternalId()])
                    ]);
                    $providerInstallationId = $installation->getAttribute('providerInstallationId');

                    $privateKey = App::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
                    $githubAppId = App::getEnv('_APP_VCS_GITHUB_APP_ID');

                    $tmpDirectory = '/tmp/builds/' . $buildId . '/code';
                    $rootDirectory = $function->getAttribute('providerRootDirectory', '');
                    $rootDirectory = \rtrim($rootDirectory, '/');
                    $rootDirectory = \ltrim($rootDirectory, '.');
                    $rootDirectory = \ltrim($rootDirectory, '/');

                    $github->initialiseVariables($providerInstallationId, $privateKey, $githubAppId);

                    $owner = $github->getOwnerName($providerInstallationId);
                    $repositoryName = $github->getRepositoryName($providerRepositoryId);

                    $cloneOwner = !empty($providerContribution) ?  $providerContribution->getAttribute('owner', $owner) : $owner;
                    $cloneRepository = !empty($providerContribution) ?  $providerContribution->getAttribute('repository', $repositoryName) : $repositoryName;

                    $branchName = $deployment->getAttribute('providerBranch');
                    $gitCloneCommand = $github->generateCloneCommand($cloneOwner, $cloneRepository, $branchName, $tmpDirectory, $rootDirectory);
                    $stdout = '';
                    $stderr = '';
                    Console::execute('mkdir -p /tmp/builds/' . $buildId, '', $stdout, $stderr);
                    $exit = Console::execute($gitCloneCommand, '', $stdout, $stderr);

                    if ($exit !== 0) {
                        throw new \Exception('Unable to clone code repository: ' . $stderr);
                    }

                    // Build from template
                    $templateRepositoryName = $template->getAttribute('repositoryName', '');
                    $templateOwnerName = $template->getAttribute('ownerName', '');
                    $templateBranch = $template->getAttribute('branch', '');

                    $templateRootDirectory =  $template->getAttribute('rootDirectory', '');
                    $templateRootDirectory = \rtrim($templateRootDirectory, '/');
                    $templateRootDirectory = \ltrim($templateRootDirectory, '.');
                    $templateRootDirectory = \ltrim($templateRootDirectory, '/');

                    if (!empty($templateRepositoryName) && !empty($templateOwnerName) && !empty($templateBranch)) {
                        // Clone template repo
                        $tmpTemplateDirectory = '/tmp/builds/' . $buildId . '/template';
                        $gitCloneCommandForTemplate = $github->generateCloneCommand($templateOwnerName, $templateRepositoryName, $templateBranch, $tmpTemplateDirectory, $templateRootDirectory);
                        $exit = Console::execute($gitCloneCommandForTemplate, '', $stdout, $stderr);

                        if ($exit !== 0) {
                            throw new \Exception('Unable to clone template repository: ' . $stderr);
                        }

                        // Ensure directories
                        Console::execute('mkdir -p ' . $tmpTemplateDirectory . '/' . $templateRootDirectory, '', $stdout, $stderr);
                        Console::execute('mkdir -p ' . $tmpDirectory . '/' . $rootDirectory, '', $stdout, $stderr);

                        // Merge template into user repo
                        Console::execute('cp -rfn ' . $tmpTemplateDirectory . '/' . $templateRootDirectory . '/* ' . $tmpDirectory . '/' . $rootDirectory, '', $stdout, $stderr);

                        // Commit and push
                        $exit = Console::execute('git config --global user.email "security@appwrite.io" && git config --global user.name "Appwrite" && cd ' . $tmpDirectory . ' && git add . && git commit -m "Create \'' . $function->getAttribute('name', '') .  '\' function" && git push origin ' . $branchName, '', $stdout, $stderr);

                        if ($exit !== 0) {
                            throw new \Exception('Unable to push code repository: ' . $stderr);
                        }

                        $exit = Console::execute('cd ' . $tmpDirectory . ' && git rev-parse HEAD', '', $stdout, $stderr);

                        if ($exit !== 0) {
                            throw new \Exception('Unable to get vcs commit SHA: ' . $stderr);
                        }

                        $providerCommitHash = \trim($stdout);
                    }

                    Console::execute('tar --exclude code.tar.gz -czf /tmp/builds/' . $buildId . '/code.tar.gz -C /tmp/builds/' . $buildId . '/code' . (empty($rootDirectory) ? '' : '/' . $rootDirectory) . ' .', '', $stdout, $stderr);

                    $deviceFunctions = $this->getFunctionsDevice($project->getId());

                    $fileName = 'code.tar.gz';
                    $fileTmpName = '/tmp/builds/' . $buildId . '/code.tar.gz';

                    $path = $deviceFunctions->getPath($deployment->getId() . '.' . \pathinfo($fileName, PATHINFO_EXTENSION));

                    $result = $deviceFunctions->move($fileTmpName, $path);

                    if (!$result) {
                        throw new \Exception("Unable to move file");
                    }

                    Console::execute('rm -rf /tmp/builds/' . $buildId, '', $stdout, $stderr);

                    $source = $path;

                    $build = $dbForProject->updateDocument('builds', $build->getId(), $build->setAttribute('source', $source));

                    if ($isVcsEnabled) {
                        $this->runGitAction('processing', $github, $providerCommitHash, $owner, $repositoryName, $providerTargetUrl, $project, $function, $deployment->getId(), $dbForProject, $dbForConsole);
                    }
                }
            }

            /** Request the executor to build the code... */
            $build->setAttribute('status', 'building');
            $build = $dbForProject->updateDocument('builds', $buildId, $build);

            if ($isVcsEnabled) {
                $this->runGitAction('building', $github, $providerCommitHash, $owner, $repositoryName, $providerTargetUrl, $project, $function, $deployment->getId(), $dbForProject, $dbForConsole);
            }

            /** Trigger Webhook */
            $deploymentModel = new Deployment();

            $deploymentUpdate = new Event(Event::WEBHOOK_QUEUE_NAME, Event::WEBHOOK_CLASS_NAME);
            $deploymentUpdate
                ->setProject($project)
                ->setEvent('functions.[functionId].deployments.[deploymentId].update')
                ->setParam('functionId', $function->getId())
                ->setParam('deploymentId', $deployment->getId())
                ->setPayload($deployment->getArrayCopy(array_keys($deploymentModel->getRules())))
                ->trigger();

            /** Trigger Functions */
            $pools = $register->get('pools');
            $connection = $pools->get('queue')->pop();

            $functions = new Func($connection->getResource());
            $functions
                ->from($deploymentUpdate)
                ->trigger();

            $connection->reclaim();

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

            // global vars
            $vars = \array_merge($vars, \array_reduce($dbForProject->find('variables', [
                Query::equal('resourceType', ['project']),
                Query::limit(APP_LIMIT_SUBQUERY)
            ]), function (array $carry, Document $var) {
                $carry[$var->getAttribute('key')] = $var->getAttribute('value') ?? '';
                return $carry;
            }, []));

            // Function vars
            $vars = \array_merge($vars, array_reduce($function->getAttribute('vars', []), function (array $carry, Document $var) {
                $carry[$var->getAttribute('key')] = $var->getAttribute('value');
                return $carry;
            }, []));

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
            $command = \str_replace('"', '\\"', $command);

            $response = null;

            $err = null;

            // TODO: Remove run() wrapper when switching to new utopia queue. That should be done on Swoole adapter in the libary
            Co\run(function () use ($project, $deployment, &$response, $source, $function, $runtime, $vars, $command, &$build, $dbForProject, $allEvents, &$err) {
                Co::join([
                    Co\go(function () use (&$response, $project, $deployment, $source, $function, $runtime, $vars, $command, &$err) {
                        try {
                            $response = $this->executor->createRuntime(
                                projectId: $project->getId(),
                                deploymentId: $deployment->getId(),
                                source: $source,
                                version: $function->getAttribute('version'),
                                image: $runtime['image'],
                                remove: true,
                                entrypoint: $deployment->getAttribute('entrypoint'),
                                destination: APP_STORAGE_BUILDS . "/app-{$project->getId()}",
                                variables: $vars,
                                command: 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "' . $command . '"'
                            );
                        } catch (Exception $error) {
                            $err = $error;
                        }
                    }),
                    Co\go(function () use ($project, $deployment, &$response, &$build, $dbForProject, $allEvents, &$err) {
                        try {
                            $this->executor->getLogs(
                                projectId: $project->getId(),
                                deploymentId: $deployment->getId(),
                                callback: function ($logs) use (&$response, &$build, $dbForProject, $allEvents, $project) {
                                    if ($response === null) {
                                        $build = $build->setAttribute('stdout', $build->getAttribute('stdout', '') . $logs);
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
                        } catch (Exception $error) {
                            if (empty($err)) {
                                $err = $error;
                            }
                        }
                    }),
                ]);
            });

            if ($err) {
                throw $err;
            }

            $endTime = DateTime::now();
            $durationEnd = \microtime(true);

            /** Update the build document */
            $build->setAttribute('startTime', DateTime::format((new \DateTime())->setTimestamp($response['startTime'])));
            $build->setAttribute('endTime', $endTime);
            $build->setAttribute('duration', \intval(\ceil($durationEnd - $durationStart)));
            $build->setAttribute('status', 'ready');
            $build->setAttribute('path', $response['path']);
            $build->setAttribute('size', $response['size']);
            $build->setAttribute('stderr', $response['stderr']);
            $build->setAttribute('stdout', $response['stdout']);

            if ($isVcsEnabled) {
                $this->runGitAction('ready', $github, $providerCommitHash, $owner, $repositoryName, $providerTargetUrl, $project, $function, $deployment->getId(), $dbForProject, $dbForConsole);
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
            $dbForConsole = $this->getConsoleDB();
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
            $build->setAttribute('stderr', $th->getMessage());
            Console::error($th->getMessage());

            if ($isVcsEnabled) {
                $this->runGitAction('failed', $github, $providerCommitHash, $owner, $repositoryName, $providerTargetUrl, $project, $function, $deployment->getId(), $dbForProject, $dbForConsole);
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

            /** Update usage stats */
            if (App::getEnv('_APP_USAGE_STATS', 'enabled') === 'enabled') {
                $statsd = $register->get('statsd');
                $usage = new Stats($statsd);
                $usage
                    ->setParam('projectInternalId', $project->getInternalId())
                    ->setParam('projectId', $project->getId())
                    ->setParam('functionId', $function->getId())
                    ->setParam('builds.{scope}.compute', 1)
                    ->setParam('buildStatus', $build->getAttribute('status', ''))
                    ->setParam('buildTime', $build->getAttribute('duration'))
                    ->setParam('networkRequestSize', 0)
                    ->setParam('networkResponseSize', 0)
                    ->submit();
            }
        }
    }

    protected function runGitAction(string $status, GitHub $github, string $providerCommitHash, string $owner, string $repositoryName, string $providerTargetUrl, Document $project, Document $function, string $deploymentId, Database $dbForProject, Database $dbForConsole)
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

            $github->updateCommitStatus($repositoryName, $providerCommitHash, $owner, $state, $message, $providerTargetUrl, $name);
        }

        if (!empty($commentId)) {
            $retries = 0;

            while ($retries < 10) {
                $retries++;

                try {
                    $dbForConsole->createDocument('vcsCommentLocks', new Document([
                        '$id' => $commentId
                    ]));
                    break;
                } catch (Exception $err) {
                    if ($retries >= 9) {
                        throw $err;
                    }
                }

                \sleep(1);
            }

            // Wrap in try/catch to ensure lock file gets deleted
            $error = null;
            try {
                $comment = new Comment();
                $comment->parseComment($github->getComment($owner, $repositoryName, $commentId));
                $comment->addBuild($project, $function, $status, $deployment->getId());
                $github->updateComment($owner, $repositoryName, $commentId, $comment->generateComment());
            } catch (\Exception $e) {
                $error = $e;
            } finally {
                $dbForConsole->deleteDocument('vcsCommentLocks', $commentId);
            }

            if (!empty($error)) {
                throw $error;
            }
        }
    }

    public function shutdown(): void
    {
    }
}
