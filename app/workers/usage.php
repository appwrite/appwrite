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

    public function setUp()
    {
    }

    public function perform()
    {
        global $register;

        $statsd = $register->get('statsd', true);

        $projectId = $this->args['projectId'];
        $method = $this->args['method'];
        $request = $this->args['request'];
        $response = $this->args['response'];
        $storage = $this->args['storage'];

        $tags = ",project={$projectId},version=".App::getEnv('_APP_VERSION', 'UNKNOWN').'';

        // the global namespace is prepended to every key (optional)
        $statsd->setNamespace('appwrite.usage');

        $statsd->increment('requests.all'.$tags.',method='.\strtolower($method));

        $statsd->count('network.all'.$tags, $request + $response);
        $statsd->count('network.inbound'.$tags, $request);
        $statsd->count('network.outbound'.$tags, $response);
        $statsd->count('storage.all'.$tags, $storage);
    }

    public function tearDown()
    {
        // ... Remove environment for this job
    }
}
