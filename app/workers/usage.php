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

        $statsd = $register->get('statsd', true);

        $projectId = $this->args['projectId'];

        $storage = $this->args['storage'];

        $networkRequestSize = $this->args['networkRequestSize'];
        $networkResponseSize = $this->args['networkResponseSize'];
        
        $httpMethod = $this->args['httpMethod'];
        $httpRequest = $this->args['httpRequest'];

        $functionId = $this->args['functionId'] ?? null;
        $functionExecution = $this->args['functionExecution'] ?? null;
        $functionExecutionTime = $this->args['functionExecutionTime'] ?? null;
        $functionStatus = $this->args['functionStatus'] ?? null;

        $tags = ",project={$projectId},version=".App::getEnv('_APP_VERSION', 'UNKNOWN').'';

        // the global namespace is prepended to every key (optional)
        $statsd->setNamespace('appwrite.usage');

        if($httpRequest >= 1) {
            $statsd->increment('requests.all'.$tags.',method='.\strtolower($httpMethod));
        }
        
        if($functionExecution >= 1) {
            $statsd->increment('executions.all'.$tags.',functionId='.$functionId.',functionStatus='.$functionStatus);
            var_dump($tags.',functionId='.$functionId.',functionStatus='.$functionStatus);
            $statsd->count('executions.time'.$tags.',functionId='.$functionId, $functionExecutionTime);
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
