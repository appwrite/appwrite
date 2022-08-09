<?php

namespace Appwrite\Usage;

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
        $functionBuildTime = $this->params['functionBuildTime'] ?? 0;
        $functionBuild = $this->params['functionBuild'] ?? 0;
        $functionBuildStatus = $this->params['functionBuildStatus'] ?? '';
        $functionCompute = $functionExecutionTime + $functionBuildTime;

        $tags = ",projectId={$projectId},version=" . App::getEnv('_APP_VERSION', 'UNKNOWN');

        // the global namespace is prepended to every key (optional)
        $this->statsd->setNamespace($this->namespace);

        if ($httpRequest >= 1) {
            $this->statsd->increment('network.requests' . $tags . ',method=' . \strtolower($httpMethod));
        }
        $this->statsd->count('network.inbound' . $tags, $networkRequestSize);
        $this->statsd->count('network.outbound' . $tags, $networkResponseSize);
        $this->statsd->count('network.bandwidth' . $tags, $networkRequestSize + $networkResponseSize);

        $usersMetrics = [
            'users.requests.create',
            'users.requests.read',
            'users.requests.update',
            'users.requests.delete',
        ];

        foreach ($usersMetrics as $metric) {
            $value = $this->params[$metric] ?? 0;
            if ($value >= 1) {
                $this->statsd->increment($metric . $tags);
            }
        }

        $dbMetrics = [
            'databases.requests.create',
            'databases.requests.read',
            'databases.requests.update',
            'databases.requests.delete',
            'collections.requests.create',
            'collections.requests.read',
            'collections.requests.update',
            'collections.requests.delete',
            'documents.requests.create',
            'documents.requests.read',
            'documents.requests.update',
            'documents.requests.delete',
        ];

        foreach ($dbMetrics as $metric) {
            $value = $this->params[$metric] ?? 0;
            if ($value >= 1) {
                $dbTags = $tags . ",collectionId=" . ($this->params['collectionId'] ?? '') . ",databaseId=" . ($this->params['databaseId'] ?? '');
                $this->statsd->increment($metric . $dbTags);
            }
        }

        $storageMertics = [
            'buckets.requests.create',
            'buckets.requests.read',
            'buckets.requests.update',
            'buckets.requests.delete',
            'files.requests.create',
            'files.requests.read',
            'files.requests.update',
            'files.requests.delete',
        ];

        foreach ($storageMertics as $metric) {
            $value = $this->params[$metric] ?? 0;
            if ($value >= 1) {
                $storageTags = $tags . ",bucketId=" . ($this->params['bucketId'] ?? '');
                $this->statsd->increment($metric . $storageTags);
            }
        }

        $sessionsMetrics = [
            'users.sessions.create',
            'users.sessions.delete',
        ];

        foreach ($sessionsMetrics as $metric) {
            $value = $this->params[$metric] ?? 0;
            if ($value >= 1) {
                $sessionTags = $tags . ",provider=" . ($this->params['provider'] ?? '');
                $this->statsd->count($metric . $sessionTags, $value);
            }
        }

        if ($storage >= 1) {
            $storageTags = $tags . ",bucketId=" . ($this->params['bucketId'] ?? '');
            $this->statsd->count('storage.all' . $storageTags, $storage);
        }

        if ($functionExecution >= 1) {
            $this->statsd->increment('executions.compute' . $tags . ',functionId=' . $functionId . ',functionStatus=' . $functionStatus);
            $this->statsd->count('executions.compute.time' . $tags . ',functionId=' . $functionId, $functionExecutionTime);
        }
        if ($functionBuild >= 1) {
            $this->statsd->increment('builds.compute' . $tags . ',functionId=' . $functionId . ',functionBuildStatus=' . $functionBuildStatus);
            $this->statsd->count('builds.compute.time' . $tags . ',functionId=' . $functionId, $functionExecutionTime);
        }
        if ($functionBuild + $functionExecution >= 1) {
            $this->statsd->count('compute.time' . $tags . ',functionId=' . $functionId, $functionCompute);
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
