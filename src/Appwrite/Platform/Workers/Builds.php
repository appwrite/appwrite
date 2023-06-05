<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Usage;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Utopia\Response\Model\Deployment;
use Exception;
use Executor\Executor;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Helpers\ID;
use Utopia\DSN\DSN;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Storage\Storage;

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
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('queueForFunctions')
            ->inject('queueForUsage')
            ->callback(fn($message, Database $dbForProject, Event $queueForEvents, Func $queueForFunctions, Usage $queueForUsage) => $this->action($message, $dbForProject, $queueForEvents, $queueForFunctions, $queueForUsage));
    }

    /**
     * @throws Exception|\Throwable
     */
    public function action(Message $message, Database $dbForProject, Event $queueForEvents, Func $queueForFunctions, Usage $queueForUsage): void
    {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $type = $payload['type'] ?? '';
        $project = new Document($payload['project'] ?? []);
        $resource = new Document($payload['resource'] ?? []);
        $deployment = new Document($payload['deployment'] ?? []);

        switch ($type) {
            case BUILD_TYPE_DEPLOYMENT:
            case BUILD_TYPE_RETRY:
                Console::info('Creating build for deployment: ' . $deployment->getId());
                $this->buildDeployment(
                    dbForProject: $dbForProject,
                    queueForEvents: $queueForEvents,
                    queueForFunctions: $queueForFunctions,
                    queueForUsage: $queueForUsage,
                    deployment: $deployment,
                    project: $project,
                    function: $resource
                );
                break;

            default:
                throw new \Exception('Invalid build type');
        }
    }

    /**
     * @throws Authorization
     * @throws \Throwable
     * @throws Structure
     */
    private function buildDeployment(Database $dbForProject, Event $queueForEvents, Func $queueForFunctions, Usage $queueForUsage, Document $deployment, Document $project, Document $function): void
    {
        $executor = new Executor(App::getEnv('_APP_EXECUTOR_HOST'));
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
        $runtime = $runtimes[$key] ?? null;
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

        if (empty($buildId)) {
            $buildId = ID::unique();
            $build = $dbForProject->createDocument('builds', new Document([
                '$id' => $buildId,
                '$permissions' => [],
                'startTime' => $startTime,
                'deploymentId' => $deployment->getId(),
                'status' => 'processing',
                'path' => '',
                'size' => 0,
                'runtime' => $function->getAttribute('runtime'),
                'source' => $deployment->getAttribute('path'),
                'sourceType' => $device,
                'stdout' => '',
                'stderr' => '',
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
        $deploymentUpdate = $queueForEvents
            ->setQueue(Event::WEBHOOK_QUEUE_NAME)
            ->setClass(Event::WEBHOOK_CLASS_NAME)
            ->setProject($project)
            ->setEvent('functions.[functionId].deployments.[deploymentId].update')
            ->setParam('functionId', $function->getId())
            ->setParam('deploymentId', $deployment->getId())
            ->setPayload($deployment->getArrayCopy(
                array_keys(
                    (new Deployment())->getRules()
                )
            ));

        $deploymentUpdate->trigger();

        /** Trigger Functions */
        $queueForFunctions
            ->from($deploymentUpdate)
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

        $vars = array_reduce($function->getAttribute('vars', []), function (array $carry, Document $var) {
            $carry[$var->getAttribute('key')] = $var->getAttribute('value');
            return $carry;
        }, []);

        try {
            $response = $executor->createRuntime(
                deploymentId: $deployment->getId(),
                projectId: $project->getId(),
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

            /** Update the build document */
            $build->setAttribute('startTime', DateTime::format((new \DateTime())->setTimestamp($response['startTime'])));
            $build->setAttribute('duration', \intval($response['duration']));
            $build->setAttribute('status', $response['status']);
            $build->setAttribute('path', $response['path']);
            $build->setAttribute('size', $response['size']);
            $build->setAttribute('stderr', $response['stderr']);
            $build->setAttribute('stdout', $response['stdout']);

            /* Also update the deployment buildTime */
            $deployment->setAttribute('buildTime', $response['duration']);

            Console::success("Build id: $buildId created");

            $function->setAttribute('scheduleUpdatedAt', DateTime::now());

            /** Set auto deploy */
            if ($deployment->getAttribute('activate') === true) {
                $function->setAttribute('deployment', $deployment->getId());
                $function = $dbForProject->updateDocument('functions', $function->getId(), $function);
            }

            /** Update function schedule */
            $dbForConsole = getConsoleDB();
            $schedule = $dbForConsole->getDocument('schedules', $function->getAttribute('scheduleId'));
            $schedule->setAttribute('resourceUpdatedAt', $function->getAttribute('scheduleUpdatedAt'));

            $schedule
                ->setAttribute('schedule', $function->getAttribute('schedule'))
                ->setAttribute('active', !empty($function->getAttribute('schedule')) && !empty($function->getAttribute('deployment')));


            \Utopia\Database\Validator\Authorization::skip(fn() => $dbForConsole->updateDocument('schedules', $schedule->getId(), $schedule));
        } catch (\Throwable $th) {
            $endTime = DateTime::now();
            $interval = (new \DateTime($endTime))->diff(new \DateTime($startTime));
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

            /** Trigger usage queue */
            $queueForUsage
                ->setProject($project)
                ->addMetric(METRIC_BUILDS, 1) // per project
                ->addMetric(METRIC_BUILDS_STORAGE, $build->getAttribute('size', 0))
                ->addMetric(METRIC_BUILDS_COMPUTE, (int)$build->getAttribute('duration', 0) * 1000)
                ->addMetric(str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_BUILDS), 1) // per function
                ->addMetric(str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_BUILDS_STORAGE), $build->getAttribute('size', 0))
                ->addMetric(str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_BUILDS_COMPUTE), (int)$build->getAttribute('duration', 0) * 1000)
                ->trigger();
        }
    }
}
