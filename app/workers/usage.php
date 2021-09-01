<?php

use Appwrite\Resque\Worker;
use Utopia\App;
use Utopia\CLI\Console;

require_once __DIR__ . '/../workers.php';

Console::title('Usage V1 Worker');
Console::success(APP_NAME . ' usage worker v1 has started');

class UsageV1 extends Worker
{
    public function init(): void
    {
    }

    public function run(): void
    {
        global $register;

        /** @var \Domnikl\Statsd\Client $statsd */
        $statsd = $register->get('statsd', true);

        $projectId = $this->args['projectId'] ?? '';

        $storage = $this->args['storage'] ?? 0;

        $networkRequestSize = $this->args['networkRequestSize'] ?? 0;
        $networkResponseSize = $this->args['networkResponseSize'] ?? 0;

        $httpMethod = $this->args['httpMethod'] ?? '';
        $httpRequest = $this->args['httpRequest'] ?? 0;

        $functionId = $this->args['functionId'] ?? '';
        $functionExecution = $this->args['functionExecution'] ?? 0;
        $functionExecutionTime = $this->args['functionExecutionTime'] ?? 0;
        $functionStatus = $this->args['functionStatus'] ?? '';

        $tags = ",project={$projectId},version=" . App::getEnv('_APP_VERSION', 'UNKNOWN');

        // the global namespace is prepended to every key (optional)
        $statsd->setNamespace('appwrite.usage');

        if ($httpRequest >= 1) {
            $statsd->increment('requests.all' . $tags . ',method=' . \strtolower($httpMethod));
        }

        if ($functionExecution >= 1) {
            $statsd->increment('executions.all' . $tags . ',functionId=' . $functionId . ',functionStatus=' . $functionStatus);
            $statsd->count('executions.time' . $tags . ',functionId=' . $functionId, $functionExecutionTime);
        }

        $statsd->count('network.inbound' . $tags, $networkRequestSize);
        $statsd->count('network.outbound' . $tags, $networkResponseSize);
        $statsd->count('network.all' . $tags, $networkRequestSize + $networkResponseSize);

        if ($storage >= 1) {
            $statsd->count('storage.all' . $tags, $storage);
        }
    }

    public function shutdown(): void
    {
    }
}
