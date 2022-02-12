<?php

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

        switch ($type) {
            case BUILD_TYPE_DEPLOYMENT:
                $functionId = $this->args['functionId'] ?? '';
                $deploymentId = $this->args['deploymentId'] ?? '';
                Console::info("Creating build for deployment: $deploymentId");
                $this->buildDeployment($projectId, $functionId, $deploymentId);
                break;

            // case BUILD_TYPE_RETRY:
            //     $buildId = $this->args['buildId'] ?? '';
            //     $functionId = $this->args['functionId'] ?? '';
            //     $deploymentId = $this->args['deploymentId'] ?? '';
            //     Console::info("Retrying build for id: $buildId");
            //     $this->createBuild($projectId, $functionId, $deploymentId, $buildId);
            //     break;

            default:
                throw new \Exception('Invalid build type');
                break;
        }
    }

    protected function buildDeployment(string $projectId, string $functionId, string $deploymentId) 
    {
        $dbForProject = $this->getProjectDB($projectId);
        
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
        if (empty($buildId)) {
            $buildId = $dbForProject->getId();
            $build = $dbForProject->createDocument('builds', new Document([
                '$id' => $buildId,
                '$read' => [],
                '$write' => [],
                'startTime' => time(),
                'deploymentId' => $deploymentId,
                'status' => 'processing',
                'outputPath' => '',
                'runtime' => $function->getAttribute('runtime'),
                'source' => $deployment->getAttribute('path'),
                'sourceType' => Storage::DEVICE_LOCAL,
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

        $path = $deployment->getAttribute('path');
        $vars = $function->getAttribute('vars', []);
        $baseImage = $runtime['image'];
        $response = $this->executor->createRuntime(
            projectId: $projectId, 
            functionId: $functionId, 
            deploymentId: $deploymentId, 
            path: $path, 
            vars: $vars, 
            runtime: $key, 
            baseImage: $baseImage
        );
            
        /** Update the build document */
        $build->setAttribute('endTime', $response['endTime']);
        $build->setAttribute('duration', $response['duration']);
        $build->setAttribute('status', $response['status']);
        $build->setAttribute('outputPath', $response['outputPath']);
        $build->setAttribute('stderr', $response['stderr']);
        $build->setAttribute('stdout', $response['stdout']);
        $build = $dbForProject->updateDocument('builds', $buildId, $build);

        /** Set auto deploy */
        if ($deployment->getAttribute('deploy') === true) {
            $function->setAttribute('deployment', $deployment->getId());
            $function = $dbForProject->updateDocument('functions', $functionId, $function);
        }

        /** Update function schedule */
        $schedule = $function->getAttribute('schedule', '');
        $cron = (empty($function->getAttribute('deployment')) && !empty($schedule)) ? new CronExpression($schedule) : null;
        $next = (empty($function->getAttribute('deployment')) && !empty($schedule)) ? $cron->getNextRunDate()->format('U') : 0;
        $function->setAttribute('scheduleNext', (int)$next);
        $function = $dbForProject->updateDocument('functions', $functionId, $function);

        Console::success("Build id: $buildId created");
    }

    public function shutdown(): void {}
}
