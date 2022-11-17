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
use Utopia\Storage\Storage;
use Utopia\Database\Document;
use Utopia\Config\Config;
use Utopia\Database\Validator\Authorization;

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
        global $register;

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

        $connection = App::getEnv('_APP_CONNECTIONS_STORAGE', '');
        $device = STORAGE_DEVICE_LOCAL;
        try {
            $dsn = new DSN($connection);
            $device = $dsn->getScheme();
        } catch (\Exception $e) {
            $device = STORAGE_DEVICE_LOCAL;
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
                'sourceType' => $device,
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

        $vars = array_reduce($function->getAttribute('vars', []), function (array $carry, Document $var) {
            $carry[$var->getAttribute('key')] = $var->getAttribute('value');
            return $carry;
        }, []);

        try {
            $response = $this->executor->createRuntime(
                projectId: $project->getId(),
                deploymentId: $deployment->getId(),
                source: $source,
                image: $runtime['image'],
                remove: true,
                entrypoint: $deployment->getAttribute('entrypoint'),
                workdir: '/usr/code',
                destination: APP_STORAGE_BUILDS . "/app-{$project->getId()}",
                variables: $vars,
                commands: [
                    'sh', '-c',
                    'tar -zxf /tmp/code.tar.gz -C /usr/code && \
                    cd /usr/local/src/ && ./build.sh'
                ]
            );

            $endTime = new \DateTime();
            $endTime->setTimestamp($response['endTimeUnix']);

            /** Update the build document */
            $build->setAttribute('endTime', DateTime::format($endTime));
            $build->setAttribute('duration', \intval($response['duration']));
            $build->setAttribute('status', $response['status']);
            $build->setAttribute('outputPath', $response['outputPath']);
            $build->setAttribute('stderr', $response['stderr']);
            $build->setAttribute('stdout', $response['stdout']);

            Console::success("Build id: $buildId created");

            $function->setAttribute('scheduleUpdatedAt', DateTime::now());

            /** Set auto deploy */
            if ($deployment->getAttribute('activate') === true) {
                $function->setAttribute('deployment', $deployment->getId());
                $function = $dbForProject->updateDocument('functions', $function->getId(), $function);
            }

            /** Update function schedule */
            $dbForConsole = $this->getConsoleDB();
            $schedule = $dbForConsole->getDocument('schedules', $function->getAttribute('scheduleId'));
            $schedule->setAttribute('resourceUpdatedAt', $function->getAttribute('scheduleUpdatedAt'));

            $schedule
                ->setAttribute('schedule', $function->getAttribute('schedule'))
                ->setAttribute('active', !empty($function->getAttribute('schedule')) && !empty($function->getAttribute('deployment')));


            Authorization::skip(fn () => $dbForConsole->updateDocument('schedules', $schedule->getId(), $schedule));
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
