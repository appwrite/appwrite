<?php

use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Resque\Worker;
use Cron\CronExpression;
use Executor\Executor;
use Utopia\Database\Validator\Authorization;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Storage\Storage;
use Utopia\Database\Document;
use Utopia\Config\Config;

require_once __DIR__.'/../init.php';

// Disable Auth since we already validate it in the API
Authorization::disable();

Console::title('Builds V1 Worker');
Console::success(APP_NAME.' build worker v1 has started');

// TODO: Executor should return appropriate response codes.
class BuildsV1 extends Worker
{ 
    /**
     * @var Executor
     */
    private $executor = null;

    public function getName(): string 
    {
        return "builds";
    }

    public function init(): void {
        $this->executor = new Executor();
    }

    public function run(): void
    {
        $type = $this->args['type'] ?? '';
        $projectId = $this->args['projectId'] ?? '';
        $functionId = $this->args['resourceId'] ?? '';
        $deploymentId = $this->args['deploymentId'] ?? '';
        
        switch ($type) {
            case BUILD_TYPE_DEPLOYMENT:
            case BUILD_TYPE_RETRY:
                Console::info("Creating build for deployment: $deploymentId");
                $this->buildDeployment($projectId, $functionId, $deploymentId);
                break;

            default:
                throw new \Exception('Invalid build type');
                break;
        }
    }

    protected function buildDeployment(string $projectId, string $functionId, string $deploymentId) 
    {
        $dbForProject = $this->getProjectDB($projectId);
        $dbForConsole = $this->getConsoleDB();
        $project = $dbForConsole->getDocument('projects', $projectId);
        
        $function = $dbForProject->getDocument('functions', $functionId);
        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        $deployment = $dbForProject->getDocument('deployments', $deploymentId);
        if ($deployment->isEmpty()) {
            throw new Exception('Deployment not found', 404);
        }

        $runtimes = Config::getParam('runtimes', []);
        $key = $function->getAttribute('runtime');
        $runtime = isset($runtimes[$key]) ? $runtimes[$key] : null;
        if (\is_null($runtime)) {
            throw new Exception('Runtime "' . $function->getAttribute('runtime', '') . '" is not supported');
        }

        $buildId = $deployment->getAttribute('buildId', '');
        $build = null;
        $startTime = \time();
        if (empty($buildId)) {
            $buildId = $dbForProject->getId();
            $build = $dbForProject->createDocument('builds', new Document([
                '$id' => $buildId,
                '$read' => [],
                '$write' => [],
                'startTime' => $startTime,
                'deploymentId' => $deploymentId,
                'status' => 'processing',
                'outputPath' => '',
                'runtime' => $function->getAttribute('runtime'),
                'source' => $deployment->getAttribute('path'),
                'sourceType' => App::getEnv('_APP_STORAGE_DEVICE', Storage::DEVICE_LOCAL),
                'stdout' => '',
                'stderr' => '',
                'endTime' => 0,
                'duration' => 0
            ]));
            $deployment->setAttribute('buildId', $buildId);
            $deployment = $dbForProject->updateDocument('deployments', $deploymentId, $deployment);
        } else {
            $build = $dbForProject->getDocument('builds', $buildId);
        }

        /** Request the executor to build the code... */
        $build->setAttribute('status', 'building');
        $build = $dbForProject->updateDocument('builds', $buildId, $build);

        /** Send realtime event */
        $target = Realtime::fromPayload('functions.deployments.update', $build, $project);
        Realtime::send(
            projectId: 'console',
            payload: $build->getArrayCopy(),
            event: 'functions.deployments.update',
            channels: $target['channels'],
            roles: $target['roles']
        );

        $source = $deployment->getAttribute('path');
        $vars = $function->getAttribute('vars', []);
        $baseImage = $runtime['image'];

        try {
            $response = $this->executor->createRuntime(
                projectId: $projectId, 
                deploymentId: $deploymentId, 
                entrypoint: $deployment->getAttribute('entrypoint'),
                source: $source,
                destination: APP_STORAGE_BUILDS . "/app-$projectId",
                vars: $vars, 
                runtime: $key, 
                baseImage: $baseImage,
                workdir: '/usr/code',
                remove: true,
                commands: [
                    'sh', '-c',
                    'tar -zxf /tmp/code.tar.gz -C /usr/code && \
                    cd /usr/local/src/ && ./build.sh'
                ]
            );

            /** Update the build document */
            $build->setAttribute('endTime', $response['endTime']);
            $build->setAttribute('duration', $response['duration']);
            $build->setAttribute('status', $response['status']);
            $build->setAttribute('outputPath', $response['outputPath']);
            $build->setAttribute('stderr', $response['stderr']);
            $build->setAttribute('stdout', $response['stdout']);

            Console::success("Build id: $buildId created");

            /** Set auto deploy */
            if ($deployment->getAttribute('activate') === true) {
                $function->setAttribute('deployment', $deployment->getId());
                $function = $dbForProject->updateDocument('functions', $functionId, $function);
            }

            /** Update function schedule */
            $schedule = $function->getAttribute('schedule', '');
            $cron = (empty($function->getAttribute('deployment')) && !empty($schedule)) ? new CronExpression($schedule) : null;
            $next = (empty($function->getAttribute('deployment')) && !empty($schedule)) ? $cron->getNextRunDate()->format('U') : 0;
            $function->setAttribute('scheduleNext', (int)$next);
            $function = $dbForProject->updateDocument('functions', $functionId, $function);

        } catch (\Throwable $th) {
            $endtime = \time();
            $build->setAttribute('endTime', $endtime);
            $build->setAttribute('duration', $endtime - $startTime);
            $build->setAttribute('status', 'failed');
            $build->setAttribute('stderr', $th->getMessage());
            Console::error($th->getMessage());
        } finally {
            $build = $dbForProject->updateDocument('builds', $buildId, $build);

            /** 
             * Send realtime Event 
             */
            $target = Realtime::fromPayload('functions.deployments.update', $build, $project);
            Realtime::send(
                projectId: 'console',
                payload: $build->getArrayCopy(),
                event: 'functions.deployments.update',
                channels: $target['channels'],
                roles: $target['roles']
            );
        }
    }

    public function shutdown(): void {}
}
