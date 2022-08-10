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
            $this->statsd->increment('project.{scope}.network.requests' . $tags . ',method=' . \strtolower($httpMethod));
        }
        $this->statsd->count('project.{scope}.network.inbound' . $tags, $networkRequestSize);
        $this->statsd->count('project.{scope}.network.outbound' . $tags, $networkResponseSize);
        $this->statsd->count('project.{scope}.network.bandwidth' . $tags, $networkRequestSize + $networkResponseSize);

        $usersMetrics = [
            'users.{scope}.requests.create',
            'users.{scope}.requests.read',
            'users.{scope}.requests.update',
            'users.{scope}.requests.delete',
        ];

        foreach ($usersMetrics as $metric) {
            $value = $this->params[$metric] ?? 0;
            if ($value >= 1) {
                $this->statsd->increment($metric . $tags);
            }
        }

        $dbMetrics = [
            'databases.{scope}.requests.create',
            'databases.{scope}.requests.read',
            'databases.{scope}.requests.update',
            'databases.{scope}.requests.delete',
            'collections.{scope}.requests.create',
            'collections.{scope}.requests.read',
            'collections.{scope}.requests.update',
            'collections.{scope}.requests.delete',
            'documents.{scope}.requests.create',
            'documents.{scope}.requests.read',
            'documents.{scope}.requests.update',
            'documents.{scope}.requests.delete',
        ];

        foreach ($dbMetrics as $metric) {
            $value = $this->params[$metric] ?? 0;
            if ($value >= 1) {
                $dbTags = $tags . ",collectionId=" . ($this->params['collectionId'] ?? '') . ",databaseId=" . ($this->params['databaseId'] ?? '');
                $this->statsd->increment($metric . $dbTags);
            }
        }

        $storageMertics = [
            'buckets.{scope}.requests.create',
            'buckets.{scope}.requests.read',
            'buckets.{scope}.requests.update',
            'buckets.{scope}.requests.delete',
            'files.{scope}.requests.create',
            'files.{scope}.requests.read',
            'files.{scope}.requests.update',
            'files.{scope}.requests.delete',
        ];

        foreach ($storageMertics as $metric) {
            $value = $this->params[$metric] ?? 0;
            if ($value >= 1) {
                $storageTags = $tags . ",bucketId=" . ($this->params['bucketId'] ?? '');
                $this->statsd->increment($metric . $storageTags);
            }
        }

        $sessionsMetrics = [
            'sessions.{scope}.requests.create',
            'sessions.{scope}.requests.update',
            'sessions.{scope}.requests.delete',
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
            $this->statsd->increment('executions.{scope}.compute' . $tags . ',functionId=' . $functionId . ',functionStatus=' . $functionStatus);
            $this->statsd->count('executions.{scope}.compute.time' . $tags . ',functionId=' . $functionId, $functionExecutionTime);
        }
        if ($functionBuild >= 1) {
            $this->statsd->increment('builds.{scope}.compute' . $tags . ',functionId=' . $functionId . ',functionBuildStatus=' . $functionBuildStatus);
            $this->statsd->count('builds.{scope}.compute.time' . $tags . ',functionId=' . $functionId, $functionExecutionTime);
        }
        if ($functionBuild + $functionExecution >= 1) {
            $this->statsd->count('project.{scope}.compute.time' . $tags . ',functionId=' . $functionId, $functionCompute);
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
