<?php

namespace Appwrite\Stats;

use Utopia\App;

class Stats
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
     * @var string
     */
    protected $namespace = 'appwrite.usage';

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
     * @param string $key
     *
     * @return mixed|null
     */
    public function getParam(string $key)
    {
        return (isset($this->params[$key])) ? $this->params[$key] : null;
    }

    /**
     * @param string $namespace
     *
     * @return $this
     */
    public function setNamespace(string $namespace): self
    {
        $this->namespace = $namespace;

        return $this;
    }

    /**
     * @return string
     */
    public function getNamespace()
    {
        return $this->namespace;
    }

    /**
     * Submit data to StatsD.
     */
    public function submit(): void
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

        $tags = ",projectId={$projectId},version=" . App::getEnv('_APP_VERSION', 'UNKNOWN');

        // the global namespace is prepended to every key (optional)
        $this->statsd->setNamespace($this->namespace);

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

        $dbMetrics = [
            'database.collections.create',
            'database.collections.read',
            'database.collections.update',
            'database.collections.delete',
            'database.documents.create',
            'database.documents.read',
            'database.documents.update',
            'database.documents.delete',
        ];

        foreach ($dbMetrics as $metric) {
            $value = $this->params[$metric] ?? 0;
            if ($value >= 1) {
                $tags = ",projectId={$projectId},collectionId=" . ($this->params['collectionId'] ?? '');
                $this->statsd->increment($metric . $tags);
            }
        }

        $storageMertics = [
            'storage.files.create',
            'storage.files.read',
            'storage.files.update',
            'storage.files.delete',
        ];

        foreach ($storageMertics as $metric) {
            $value = $this->params[$metric] ?? 0;
            if ($value >= 1) {
                $tags = ",projectId={$projectId},bucketId=" . ($this->params['bucketId'] ?? '');
                $this->statsd->increment($metric . $tags);
            }
        }

        $usersMetrics = [
            'users.create',
            'users.read',
            'users.update',
            'users.delete',
        ];

        foreach ($usersMetrics as $metric) {
            $value = $this->params[$metric] ?? 0;
            if ($value >= 1) {
                $tags = ",projectId={$projectId}";
                $this->statsd->increment($metric . $tags);
            }
        }

        $sessionsMetrics = [
            'users.sessions.create',
            'users.sessions.delete',
        ];

        foreach ($sessionsMetrics as $metric) {
            $value = $this->params[$metric] ?? 0;
            if ($value >= 1) {
                $tags = ",projectId={$projectId},provider=". ($this->params['provider'] ?? '');
                $this->statsd->count($metric . $tags, $value);
            }
        }

        if ($storage >= 1) {
            $tags = ",projectId={$projectId},bucketId={($this->params['bucketId'] ?? '')}";
            $this->statsd->count('storage.all' . $tags, $storage);
        }

        $this->reset();
    }

    public function reset(): self
    {
        $this->params = [];
        $this->namespace = 'appwrite.usage';

        return $this;
    }
}
