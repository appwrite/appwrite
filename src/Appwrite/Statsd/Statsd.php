<?php

namespace Appwrite\Statsd;

use Utopia\App;

class Statsd
{
    /**
     * @var array
     */
    protected $params = [];

    /**
     * @var mixed
     */
    protected $statsd;

    /**
     * Event constructor.
     *
     * @param mixed $statsd
     */
    public function __construct($statsd)
    {
        $this->statsd = $statsd;
    }

    /**
     * @param string $key
     * @param mixed  $value
     *
     * @return $this
     */
    public function setParam(string $key, $value): self
    {
        $this->params[$key] = $value;

        return $this;
    }

    /**
     * Save to statsd.
     */
    public function save(): void
    {
        $projectId = $this->params['projectId'] ?? '';

        $storage = $this->params['storage'] ?? 0;

        $networkRequestSize = $this->params['networkRequestSize'] ?? 0;
        $networkResponseSize = $this->params['networkResponseSize'] ?? 0;

        $httpMethod = $this->params['httpMethod'] ?? '';
        $httpRequest = $this->params['httpRequest'] ?? 0;

        $functionId = $this->params['functionId'] ?? '';
        $functionExecution = $this->params['functionExecution'] ?? 0;
        $functionExecutionTime = $this->params['functionExecutionTime'] ?? 0;
        $functionStatus = $this->params['functionStatus'] ?? '';

        $tags = ",project={$projectId},version=" . App::getEnv('_APP_VERSION', 'UNKNOWN');

        // the global namespace is prepended to every key (optional)
        $this->statsd->setNamespace('appwrite.usage');

        if ($httpRequest >= 1) {
            $this->statsd->increment('requests.all' . $tags . ',method=' . \strtolower($httpMethod));
        }

        if ($functionExecution >= 1) {
            $this->statsd->increment('executions.all' . $tags . ',functionId=' . $functionId . ',functionStatus=' . $functionStatus);
            $this->statsd->count('executions.time' . $tags . ',functionId=' . $functionId, $functionExecutionTime);
        }

        $this->statsd->count('network.inbound' . $tags, $networkRequestSize);
        $this->statsd->count('network.outbound' . $tags, $networkResponseSize);
        $this->statsd->count('network.all' . $tags, $networkRequestSize + $networkResponseSize);

        if ($storage >= 1) {
            $this->statsd->count('storage.all' . $tags, $storage);
        }

        $this->reset();
    }

    public function reset(): self
    {
        $this->params = [];

        return $this;
    }
}
