<?php

use Appwrite\Resque\Worker;
use Utopia\App;
use Utopia\CLI\Console;

require_once __DIR__.'/../workers.php';

Console::title('Usage V1 Worker');

Console::success(APP_NAME.' usage worker v1 has started');

class UsageV1 extends Worker
{
    /**
     * @var array
     */
    public $args = [];

    public function init(): void
    {
    }

    public function run(): void
    {
        global $register;

        /** @var \Domnikl\Statsd\Client $statsd */
        $statsd = $register->get('statsd', true);

        $projectId = $this->args['projectId'];

        $networkRequestSize = $this->args['networkRequestSize'] ?? 0;
        $networkResponseSize = $this->args['networkResponseSize'] ?? 0;

        $storage = $this->args['storage'] ?? null;
        
        $httpMethod = $this->args['httpMethod'] ?? null;
        $httpRequest = $this->args['httpRequest'] ?? null;

        $functionId = $this->args['functionId'] ?? null;
        $functionExecution = $this->args['functionExecution'] ?? null;
        $functionExecutionTime = $this->args['functionExecutionTime'] ?? null;
        $functionStatus = $this->args['functionStatus'] ?? null;

        $realtimeConnections = $this->args['realtimeConnections'] ?? null;
        $realtimeMessages = $this->args['realtimeMessages'] ?? null;

        $tags = ",project={$projectId},version=".App::getEnv('_APP_VERSION', 'UNKNOWN');

        // the global namespace is prepended to every key (optional)
        $statsd->setNamespace('appwrite.usage');

        if($httpRequest >= 1) {
            $statsd->increment('requests.all'.$tags.',method='.\strtolower($httpMethod));
        }
        
        if($functionExecution >= 1) {
            $statsd->increment('executions.all'.$tags.',functionId='.$functionId.',functionStatus='.$functionStatus);
            $statsd->count('executions.time'.$tags.',functionId='.$functionId, $functionExecutionTime);
        }

        if($realtimeConnections >= 1) {
            $statsd->count('realtime.clients'.$tags, $realtimeConnections);
        }

        if($realtimeMessages >= 1) {
            $statsd->count('realtime.message'.$tags, $realtimeMessages);
        }

        $statsd->count('network.inbound'.$tags, $networkRequestSize);
        $statsd->count('network.outbound'.$tags, $networkResponseSize);
        $statsd->count('network.all'.$tags, $networkRequestSize + $networkResponseSize);

        if($storage >= 1) {
            $statsd->count('storage.all'.$tags, $storage);
        }
    }

    public function shutdown(): void
    {
    }
}
