<?php

use Appwrite\Event\Event;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Resque\Worker;
use Appwrite\Utopia\Response\Model\Deployment;
use Cron\CronExpression;
use Executor\Executor;
use Appwrite\Usage\Stats;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\ID;
use Utopia\Storage\Storage;
use Utopia\Database\Document;
use Utopia\Config\Config;
use Utopia\Database\Query;

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

        switch ($type) {
            case BUILD_TYPE_DEPLOYMENT:
            case BUILD_TYPE_RETRY:
                Console::info('Creating build for deployment: ' . $deployment->getId());
                $this->buildDeployment($project, $resource, $deployment);
                break;

            default:
                throw new \Exception('Invalid build type');
                break;
        }
    }

    protected function buildDeployment(Document $project, Document $function, Document $deployment)
    {
        $dbForProject = $this->getProjectDB($project);

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

        $buildId = $deployment->getAttribute('buildId', '');
        $startTime = DateTime::now();
        if (empty($buildId)) {
            $buildId = ID::unique();
            $build = $dbForProject->createDocument('builds', new Document([
                '$id' => $buildId,
                '$permissions' => [],
                'startTime' => $startTime,
                'deploymentId' => $deployment->getId(),
                'status' => 'processing',
                'outputPath' => '',
                'runtime' => $function->getAttribute('runtime'),
                'source' => $deployment->getAttribute('path'),
                'sourceType' => App::getEnv('_APP_STORAGE_DEVICE', Storage::DEVICE_LOCAL),
                'stdout' => '',
                'stderr' => '',
                'endTime' => null,
                'duration' => 0
            ]));
            $deployment->setAttribute('buildId', $buildId);
            $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), $deployment);
        } else {
            $build = $dbForProject->getDocument('builds', $buildId);
        }

        /** Request the executor to build the code... */
        $build->setAttribute('status', 'building');
        $build = $dbForProject->updateDocument('builds', $buildId, $build);

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
        $deploymentUpdate
            ->setClass(Event::FUNCTIONS_CLASS_NAME)
            ->setQueue(Event::FUNCTIONS_QUEUE_NAME)
            ->trigger();

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

        $vars = array_reduce($function['vars'] ?? [], function (array $carry, Document $var) {
            $carry[$var->getAttribute('key')] = $var->getAttribute('value');
            return $carry;
        }, []);

        $baseImage = $runtime['image'];

        try {
            $response = $this->executor->createRuntime(
                projectId: $project->getId(),
                deploymentId: $deployment->getId(),
                entrypoint: $deployment->getAttribute('entrypoint'),
                source: $source,
                destination: APP_STORAGE_BUILDS . "/app-{$project->getId()}",
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
            $build->setAttribute('stdout', $response['response']);

            Console::success("Build id: $buildId created");

            /** Set auto deploy */
            if ($deployment->getAttribute('activate') === true) {
                $function->setAttribute('deployment', $deployment->getId());
                $function = $dbForProject->updateDocument('functions', $function->getId(), $function);
            }

            /** Update function schedule */
            $schedule = $function->getAttribute('schedule', '');
            $cron = (empty($function->getAttribute('deployment')) && !empty($schedule)) ? new CronExpression($schedule) : null;
            $next = (empty($function->getAttribute('deployment')) && !empty($schedule)) ? DateTime::format($cron->getNextRunDate()) : null;
            $function->setAttribute('scheduleNext', $next);
            $function = $dbForProject->updateDocument('functions', $function->getId(), $function);
        } catch (\Throwable $th) {
            $endTime = DateTime::now();
            $interval = (new \DateTime($endTime))->diff(new \DateTime($startTime));
            $build->setAttribute('endTime', $endTime);
            $build->setAttribute('duration', $interval->format('%s') + 0);
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
            global $register;
            if (App::getEnv('_APP_USAGE_STATS', 'enabled') === 'enabled') {
                $statsd = $register->get('statsd');
                $usage = new Stats($statsd);
                $usage
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
