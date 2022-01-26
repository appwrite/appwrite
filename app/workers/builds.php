<?php

use Appwrite\Resque\Worker;
use Cron\CronExpression;
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

    public function getName(): string 
    {
        return "builds";
    }

    public function init(): void {}

    public function run(): void
    {
        $type = $this->args['type'] ?? '';
        $projectId = $this->args['projectId'] ?? '';

        switch ($type) {
            case BUILD_TYPE_DEPLOYMENT:
                $functionId = $this->args['functionId'] ?? '';
                $deploymentId = $this->args['deploymentId'] ?? '';
                Console::info("[ INFO ] Creating build for deployment: $deploymentId");
                $this->buildDeployment($projectId, $functionId, $deploymentId);
                break;

            case BUILD_TYPE_RETRY:
                $buildId = $this->args['buildId'] ?? '';
                Console::info("[ INFO ] Retrying build for id: $buildId");
                $this->triggerBuild($projectId, $buildId);
                break;

            default:
                throw new \Exception('Invalid build type');
                break;
        }
    }

    protected function triggerBuild(string $projectId, string $buildId)
    {
        // TODO: What is a reasonable time to wait for a build to complete?
        $ch = \curl_init();
        \curl_setopt($ch, CURLOPT_URL, "http://appwrite-executor/v1/builds/$buildId");
        \curl_setopt($ch, CURLOPT_POST, true);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_TIMEOUT, 900);
        \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-appwrite-project: '.$projectId,
            'x-appwrite-executor-key: '. App::getEnv('_APP_EXECUTOR_SECRET', '')
        ]);

        $response = \curl_exec($ch);
        $responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $error = \curl_error($ch);
        if (!empty($error)) {
            throw new \Exception($error);
        }

        \curl_close($ch);

        if ($responseStatus >= 400) {
            throw new \Exception("Build failed with status code: $responseStatus");
        }
    }

    protected function triggerCreateRuntimeServer(string $projectId, string $functionId, string $deploymentId) 
    {
        $ch = \curl_init();
        \curl_setopt($ch, CURLOPT_URL, "http://appwrite-executor/v1/functions/$functionId/deployments/$deploymentId/runtime");
        \curl_setopt($ch, CURLOPT_POST, true);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_TIMEOUT, 900);
        \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-appwrite-project: '.$projectId,
            'x-appwrite-executor-key: '. App::getEnv('_APP_EXECUTOR_SECRET', '')
        ]);

        $response = \curl_exec($ch);
        $responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $error = \curl_error($ch);
        if (!empty($error)) {
            throw new \Exception($error);
        }

        \curl_close($ch);

        if ($responseStatus >= 400) {
            throw new \Exception("Build failed with status code: $responseStatus");
        }
    }

    protected function buildDeployment(string $projectId, string $functionId, string $deploymentId) 
    {
        $dbForProject = $this->getProjectDB($projectId);
        
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        // Get deployment document
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

        // If build ID is empty, create a new build
        if (empty($buildId)) {
            try {
                $buildId = $dbForProject->getId();
                // TODO : There is no way to associate a build with a deployment. So we need to add a deploymentId attribute to the build document
                // TODO : What should be the read and write permissions for a build ? 
                $dbForProject->createDocument('builds', new Document([
                    '$id' => $buildId,
                    '$read' => [],
                    '$write' => [],
                    'dateCreated' => time(),
                    'status' => 'processing',
                    'runtime' => $function->getAttribute('runtime'),
                    'outputPath' => '',
                    'source' => $deployment->getAttribute('path'),
                    'sourceType' => Storage::DEVICE_LOCAL,
                    'stdout' => '',
                    'stderr' => '',
                    'time' => 0,
                    'vars' => [
                        'ENTRYPOINT_NAME' => $deployment->getAttribute('entrypoint'),
                        'APPWRITE_FUNCTION_ID' => $function->getId(),
                        'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name', ''),
                        'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'],
                        'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'],
                        'APPWRITE_FUNCTION_PROJECT_ID' => $projectId,
                    ]
                ]));

                $deployment->setAttribute('buildId', $buildId);
                $deployment = $dbForProject->updateDocument('deployments', $deploymentId, $deployment);

            } catch (\Throwable $th) {
                Console::error($th->getMessage());
                $deployment->setAttribute('status', 'failed');
                $deployment->setAttribute('buildId', '');
                $deployment = $dbForProject->updateDocument('deployments', $deploymentId, $deployment);
                return;
            }
        }

        // Build the Code
        try {
            Console::info("[ INFO ] Creating build with id: $buildId");
            $deployment->setAttribute('status', 'building');
            $deployment = $dbForProject->updateDocument('deployments', $deploymentId, $deployment);
            $this->triggerBuild($projectId, $buildId);
        } catch (\Throwable $th) {
            Console::error($th->getMessage());
            $deployment->setAttribute('status', 'failed');
            $deployment = $dbForProject->updateDocument('deployments', $deploymentId, $deployment);
            return;
        }
        
        Console::success("[ SUCCESS ] Build id: $buildId completed");

        // Update the schedule
        $schedule = $function->getAttribute('schedule', '');
        $cron = (empty($function->getAttribute('deployment')) && !empty($schedule)) ? new CronExpression($schedule) : null;
        $next = (empty($function->getAttribute('deployment')) && !empty($schedule)) ? $cron->getNextRunDate()->format('U') : 0;

        // Grab build
        $build = $dbForProject->getDocument('builds', $buildId);

        // If the build failed, it won't be possible to deploy
        if ($build->getAttribute('status') !== 'ready') {
            throw new Exception('Build failed', 500);
        }

        if ($deployment->getAttribute('deploy') === true) {
            // Update the function document setting the deployment as the active one
            $function
                ->setAttribute('deployment', $deployment->getId())
                ->setAttribute('scheduleNext', (int)$next);

            $function = $dbForProject->updateDocument('functions', $functionId, $function);
        }

        // Deploy Runtime Server
        try {
            Console::info("[ INFO ] Creating runtime server");
            $this->triggerCreateRuntimeServer($projectId, $functionId, $deploymentId, $dbForProject);
        } catch (\Throwable $th) {
            Console::error($th->getMessage());
            $deployment->setAttribute('status', 'failed');
            $deployment = $dbForProject->updateDocument('deployments', $deploymentId, $deployment);
            return;
        }

        Console::success("[ SUCCESS ] Runtime Server created");
    }

    public function shutdown(): void {}
}
