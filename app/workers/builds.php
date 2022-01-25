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

    public function getName(): string {
        return "builds";
    }

    public function init(): void
    {
        Console::success("Initializing...");
    }

    public function run(): void
    {
        $type = $this->args['type'] ?? '';
        $projectId = $this->args['projectId'] ?? '';

        switch ($type) {
            case BUILD_TYPE_TAG:
                $functionId = $this->args['functionId'] ?? '';
                $tagId = $this->args['tagId'] ?? '';
                Console::info("[ INFO ] Creating build for tag: $tagId");
                $this->buildTag($projectId, $functionId, $tagId);
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
        \curl_setopt($ch, CURLOPT_URL, "http://appwrite-executor/v1/build/$buildId");
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

    protected function triggerCreateRuntimeServer(string $projectId, string $functionId, string $tagId) 
    {
        $ch = \curl_init();
        \curl_setopt($ch, CURLOPT_URL, "http://appwrite-executor:8080/v1/executor/runtime");
        \curl_setopt($ch, CURLOPT_POST, true);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_TIMEOUT, 900);
        \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-appwrite-project: '.$projectId,
            'x-appwrite-executor-key: '. App::getEnv('_APP_EXECUTOR_SECRET', '')
        ]);
        \curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'functionId' => $functionId,
            'tagId' => $tagId
        ]));

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

    protected function buildTag(string $projectId, string $functionId, string $tagId) 
    {
        $dbForProject = $this->getProjectDB($projectId);
        
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        // Get tag document
        $tag = $dbForProject->getDocument('tags', $tagId);
        if ($tag->isEmpty()) {
            throw new Exception('Tag not found', 404);
        }

        $runtimes = Config::getParam('runtimes', []);
        $key = $function->getAttribute('runtime');
        $runtime = isset($runtimes[$key]) ? $runtimes[$key] : null;
        if (\is_null($runtime)) {
            throw new Exception('Runtime "' . $function->getAttribute('runtime', '') . '" is not supported');
        }

        $buildId = $tag->getAttribute('buildId', '');

        // If build ID is empty, create a new build
        if (empty($buildId)) {
            try {
                $buildId = $dbForProject->getId();
                // TODO : There is no way to associate a build with a tag. So we need to add a tagId attribute to the build document
                // TODO : What should be the read and write permissions for a build ? 
                $dbForProject->createDocument('builds', new Document([
                    '$id' => $buildId,
                    '$read' => [],
                    '$write' => [],
                    'dateCreated' => time(),
                    'status' => 'processing',
                    'runtime' => $function->getAttribute('runtime'),
                    'outputPath' => '',
                    'source' => $tag->getAttribute('path'),
                    'sourceType' => Storage::DEVICE_LOCAL,
                    'stdout' => '',
                    'stderr' => '',
                    'buildTime' => 0,
                    'envVars' => [
                        'ENTRYPOINT_NAME' => $tag->getAttribute('entrypoint'),
                        'APPWRITE_FUNCTION_ID' => $function->getId(),
                        'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name', ''),
                        'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'],
                        'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'],
                        'APPWRITE_FUNCTION_PROJECT_ID' => $projectId,
                    ]
                ]));

                $tag->setAttribute('buildId', $buildId);
                $tag = $dbForProject->updateDocument('tags', $tagId, $tag);

            } catch (\Throwable $th) {
                Console::error($th->getMessage());
                $tag->setAttribute('status', 'failed');
                $tag->setAttribute('buildId', '');
                $tag = $dbForProject->updateDocument('tags', $tagId, $tag);
                return;
            }
        }

        // Build the Code
        try {
            Console::info("[ INFO ] Creating build with id: $buildId");
            $tag->setAttribute('status', 'building');
            $tag = $dbForProject->updateDocument('tags', $tagId, $tag);
            $this->triggerBuild($projectId, $buildId);
        } catch (\Throwable $th) {
            Console::error($th->getMessage());
            $tag->setAttribute('status', 'failed');
            $tag = $dbForProject->updateDocument('tags', $tagId, $tag);
            return;
        }
        
        Console::success("[ SUCCESS ] Build id: $buildId completed");

        // Update the schedule
        $schedule = $function->getAttribute('schedule', '');
        $cron = (empty($function->getAttribute('tag')) && !empty($schedule)) ? new CronExpression($schedule) : null;
        $next = (empty($function->getAttribute('tag')) && !empty($schedule)) ? $cron->getNextRunDate()->format('U') : 0;

        // Grab build
        $build = $dbForProject->getDocument('builds', $buildId);

        // If the build failed, it won't be possible to deploy
        if ($build->getAttribute('status') !== 'ready') {
            throw new Exception('Build failed', 500);
        }

        if ($tag->getAttribute('automaticDeploy') === true) {
            // Update the function document setting the tag as the active one
            $function
                ->setAttribute('tag', $tag->getId())
                ->setAttribute('scheduleNext', (int)$next);

            $function = $dbForProject->updateDocument('functions', $functionId, $function);
        }

        // Deploy Runtime Server
        try {
            Console::info("[ INFO ] Creating runtime server");
            $this->triggerCreateRuntimeServer($projectId, $functionId, $tagId, $dbForProject);
        } catch (\Throwable $th) {
            Console::error($th->getMessage());
            $tag->setAttribute('status', 'failed');
            $tag = $dbForProject->updateDocument('tags', $tagId, $tag);
            return;
        }

        Console::success("Runtime Server created");
    }

    public function shutdown(): void
    {
        Console::success("Shutting Down...");
    }
}
