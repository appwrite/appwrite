<?php

use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Resque\Worker;
use Appwrite\Utopia\Response\Model\Deployment;
use Executor\Executor;
use Appwrite\Usage\Stats;
use Utopia\Database\DateTime;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\ID;
use Utopia\DSN\DSN;
use Utopia\Database\Document;
use Utopia\Config\Config;
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
        $SHA = $this->args['SHA'] ?? '';
        $targetUrl = $this->args['targetUrl'] ?? '';

        switch ($type) {
            case BUILD_TYPE_DEPLOYMENT:
            case BUILD_TYPE_RETRY:
                Console::info('Creating build for deployment: ' . $deployment->getId());
                $this->buildDeployment($project, $resource, $deployment, $SHA, $targetUrl);
                break;

            default:
                throw new \Exception('Invalid build type');
                break;
        }
    }

    protected function buildDeployment(Document $project, Document $function, Document $deployment, string $SHA = '', string $targetUrl = '')
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

        $buildId = $deployment->getAttribute('buildId', '');
        $startTime = DateTime::now();
        $durationStart = \microtime(true);
        if (empty($buildId)) {
            $buildId = ID::unique();

            $vcsInstallationId = $deployment->getAttribute('vcsInstallationId', '');
            $vcsRepoId = $deployment->getAttribute('vcsRepoId', '');
            $isVcsEnabled = $vcsRepoId ? true : false;

            if ($isVcsEnabled) {
                $vcsRepos = Authorization::skip(fn () => $dbForConsole
                    ->getDocument('vcs_repos', $vcsRepoId));
                $repositoryId = $vcsRepos->getAttribute('repositoryId');
                $vcsInstallations = Authorization::skip(fn () => $dbForConsole
                    ->getDocument('vcs_installations', $vcsInstallationId));
                $installationId = $vcsInstallations->getAttribute('installationId');

                $privateKey = App::getEnv('VCS_GITHUB_PRIVATE_KEY');
                $githubAppId = App::getEnv('VCS_GITHUB_APP_ID');

                $github = new GitHub();
                $github->initialiseVariables($installationId, $privateKey, $githubAppId);
                $owner = $github->getOwnerName($installationId);
                $repositoryName = $github->getRepositoryName($repositoryId);
                $branchName = $deployment->getAttribute('branch');
                $gitCloneCommand = $github->generateGitCloneCommand($owner, $repositoryId, $branchName);
                $stdout = '';
                $stderr = '';
                Console::execute('mkdir /tmp/builds/' . $buildId, '', $stdout, $stderr);
                Console::execute($gitCloneCommand . ' /tmp/builds/' . $buildId . '/code', '', $stdout, $stderr);
                Console::execute('tar --exclude code.tar.gz -czf /tmp/builds/' . $buildId . '/code.tar.gz -C /tmp/builds/' . $buildId . '/code .', '', $stdout, $stderr);

                $deviceFunctions = $this->getFunctionsDevice($project->getId());

                $fileName = 'code.tar.gz';
                $fileTmpName = '/tmp/builds/' . $buildId . '/code.tar.gz';

                $deploymentId = $deployment->getId();
                $path = $deviceFunctions->getPath($deploymentId . '.' . \pathinfo($fileName, PATHINFO_EXTENSION));

                $result = $deviceFunctions->move($fileTmpName, $path);

                if (!$result) {
                    throw new \Exception("Unable to move file");
                }

                Console::execute('rm -rf /tmp/builds/' . $buildId, '', $stdout, $stderr);

                $build = $dbForProject->createDocument('builds', new Document([
                    '$id' => $buildId,
                    '$permissions' => [],
                    'startTime' => $startTime,
                    'deploymentId' => $deployment->getId(),
                    'status' => 'processing',
                    'path' => '',
                    'runtime' => $function->getAttribute('runtime'),
                    'source' => $path,
                    'sourceType' => strtolower(App::getEnv('_APP_STORAGE_DEVICE', Storage::DEVICE_LOCAL)),
                    'stdout' => '',
                    'stderr' => '',
                    'endTime' => null,
                    'duration' => 0
                ]));
                if ($SHA !== "" && $owner !== "") {
                    $github->updateCommitStatus($repositoryName, $SHA, $owner, "pending", "Deployment is being processed..", $targetUrl, "Appwrite Deployment");
                }
                $commentId = $deployment->getAttribute('vcsCommentId');
                if ($commentId) {
                    $comment = "| Build Status |\r\n | --------------- |\r\n | Processing |";

                    $github->updateComment($owner, $repositoryName, $commentId, $comment);
                }
            } else {
                $build = $dbForProject->createDocument('builds', new Document([
                    '$id' => $buildId,
                    '$permissions' => [],
                    'startTime' => $startTime,
                    'deploymentInternalId' => $deployment->getInternalId(),
                    'deploymentId' => $deployment->getId(),
                    'status' => 'processing',
                    'path' => '',
                    'runtime' => $function->getAttribute('runtime'),
                    'source' => $deployment->getAttribute('path'),
                    'sourceType' => $device,
                    'stdout' => '',
                    'stderr' => '',
                    'endTime' => null,
                    'duration' => 0,
                    'size' => 0
                ]));
            }

            $deployment->setAttribute('buildId', $build->getId());
            $deployment->setAttribute('buildInternalId', $build->getInternalId());
            $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), $deployment);
        } else {
            $build = $dbForProject->getDocument('builds', $buildId);
        }

        /** Request the executor to build the code... */
        $build->setAttribute('status', 'building');
        $build = $dbForProject->updateDocument('builds', $buildId, $build);

        if ($isVcsEnabled) {
            $commentId = $deployment->getAttribute('vcsCommentId');
            if ($commentId) {
                $comment = "| Build Status |\r\n | --------------- |\r\n | Building |";
                $github->updateComment($owner, $repositoryName, $commentId, $comment);
            }
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
        $allEvents = Event::generateEvents('functions.[functionId].deployments.[deploymentId].update', [
            'functionId' => $function->getId(),
            'deploymentId' => $deployment->getId()
        ]);
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

        $source = $deployment->getAttribute('path');

        if ($isVcsEnabled) {
            $source = $path;
        }

        $vars = array_reduce($function->getAttribute('vars', []), function (array $carry, Document $var) {
            $carry[$var->getAttribute('key')] = $var->getAttribute('value');
            return $carry;
        }, []);

        try {
            $command = '';

            if (!empty($deployment->getAttribute('installCommand', ''))) {
                $command .= $deployment->getAttribute('installCommand', '');
            }

            if (!empty($deployment->getAttribute('buildCommand', ''))) {
                $separator = empty($command) ? '' : ' && ';
                $command .= $separator . $deployment->getAttribute('buildCommand', '');
            }

            $command = \str_replace('"', '\\"', $command);

            \var_dump($path);

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
                commands: [
                    'sh', '-c',
                    'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "' . $command . '"'
                ]
            );

            $endTime = DateTime::now();

            /** Update the build document */
            $build->setAttribute('startTime', DateTime::format((new \DateTime())->setTimestamp($response['startTime'])));
            $build->setAttribute('endTime', $endTime);
            $build->setAttribute('duration', \intval(\ceil($response['duration'])));
            $build->setAttribute('status', 'ready');
            $build->setAttribute('path', $response['path']);
            $build->setAttribute('size', $response['size']);
            $build->setAttribute('stderr', $response['stderr']);
            $build->setAttribute('stdout', $response['stdout']);

            if ($isVcsEnabled) {
                $status = $response["status"];

                if ($status === "ready" && $SHA !== "" && $owner !== "") {
                    $github->updateCommitStatus($repositoryName, $SHA, $owner, "success", "Deployment is successful!", $targetUrl, "Appwrite Deployment");
                } elseif ($status === "failed" && $SHA !== "" && $owner !== "") {
                    $github->updateCommitStatus($repositoryName, $SHA, $owner, "failure", "Deployment failed.", $targetUrl, "Appwrite Deployment");
                }

                $commentId = $deployment->getAttribute('vcsCommentId');
                if ($commentId) {
                    $comment = "| Build Status |\r\n | --------------- |\r\n | $status |";
                    $github->updateComment($owner, $repositoryName, $commentId, $comment);
                }
            }

            /* Also update the deployment buildTime */
            $deployment->setAttribute('buildTime', $response['duration']);

            Console::success("Build id: $buildId created");

            /** Set auto deploy */
            if ($deployment->getAttribute('activate') === true) {
                $function->setAttribute('deploymentInternalId', $deployment->getInternalId());
                $function->setAttribute('deployment', $deployment->getId());
                $function = $dbForProject->updateDocument('functions', $function->getId(), $function);
            }

            /** Update function schedule */
            $dbForConsole = $this->getConsoleDB();
            $schedule = $dbForConsole->getDocument('schedules', $function->getAttribute('scheduleId'));
            $schedule->setAttribute('resourceUpdatedAt', DateTime::now());

            $schedule
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

    public function shutdown(): void
    {
    }
}
