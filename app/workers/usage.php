<?php

use Utopia\App;
use Utopia\CLI\Console;

require_once __DIR__.'/../init.php';

\cli_set_process_title('Usage V1 Worker');

Console::success(APP_NAME.' usage worker v1 has started');

class UsageV1
{
    /**
     * @var array
     */
    public $args = [];

    public function setUp(): void
    {
    }

    public function perform()
    {
        global $register;

        $statsd = $register->get('statsd', true);

        $projectId = $this->args['projectId'];
        $httpMethod = $this->args['httpMethod'];
        $httpRequest = $this->args['httpRequest'];
        
        $networkRequestSize = $this->args['networkRequestSize'];
        $networkResponseSize = $this->args['networkResponseSize'];
        
        $storage = $this->args['storage'];

        $functionExecution = $this->args['functionExecution'];
        $functionExecutionTime = $this->args['functionExecutionTime'];
        $functionId = $this->args['functionId'];

        $tags = ",project={$projectId},version=".App::getEnv('_APP_VERSION', 'UNKNOWN').'';

        // the global namespace is prepended to every key (optional)
        $statsd->setNamespace('appwrite.usage');

        if($httpRequest >= 1) {
            $statsd->increment('requests.all'.$tags.',method='.\strtolower($httpMethod));
        }
        
        if($functionExecution >= 1) {
            $statsd->increment('executions.all'.$tags.',functionId='.$functionId);
            $statsd->count('executions.time'.$tags.',functionId='.$functionId, $functionExecutionTime);
        }

        $statsd->count('network.all'.$tags, $networkRequestSize + $networkResponseSize);
        $statsd->count('network.inbound'.$tags, $networkRequestSize);
        $statsd->count('network.outbound'.$tags, $networkResponseSize);
        $statsd->count('storage.all'.$tags, $storage);
    }

    public function tearDown(): void
    {
        // ... Remove environment for this job
    }
}
