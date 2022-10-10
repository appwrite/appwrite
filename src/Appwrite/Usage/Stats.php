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
        $projectInternalId = $this->params['projectInternalId'];
        $tags = ",projectInternalId={$projectInternalId},projectId={$projectId},version=" . App::getEnv('_APP_VERSION', 'UNKNOWN');

        // the global namespace is prepended to every key (optional)
        $this->statsd->setNamespace($this->namespace);

        $httpRequest = $this->params['project.{scope}.network.requests'] ?? 0;
        $httpMethod = $this->params['httpMethod'] ?? '';
        if ($httpRequest >= 1) {
            $this->statsd->increment('project.{scope}.network.requests' . $tags . ',method=' . \strtolower($httpMethod));
        }

        $inbound = $this->params['networkRequestSize'] ?? 0;
        $outbound = $this->params['networkResponseSize'] ?? 0;
        $this->statsd->count('project.{scope}.network.inbound' . $tags, $inbound);
        $this->statsd->count('project.{scope}.network.outbound' . $tags, $outbound);
        $this->statsd->count('project.{scope}.network.bandwidth' . $tags, $inbound + $outbound);

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
                if ($metric == $usersMetrics[0]) {
                    $this->statsd->increment('users.{scope}.count.total' . $tags);
                }
                if ($metric == $usersMetrics[3]) {
                    $this->statsd->decrement('users.{scope}.count.total' . $tags);
                }
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

                if ($metric == $dbMetrics[0]) {
                    $this->statsd->increment('databases.{scope}.count.total' . $dbTags);
                }
                if ($metric == $dbMetrics[3]) {
                    $this->statsd->decrement('databases.{scope}.count.total' . $dbTags);
                }

                if ($metric == $dbMetrics[4]) {
                    $this->statsd->increment('collections.{scope}.count.total' . $dbTags);
                }
                if ($metric == $dbMetrics[7]) {
                    $this->statsd->decrement('collections.{scope}.count.total' . $dbTags);
                }

                if ($metric == $dbMetrics[8]) {
                    $this->statsd->increment('documents.{scope}.count.total' . $dbTags);
                }
                if ($metric == $dbMetrics[11]) {
                    $this->statsd->decrement('documents.{scope}.count.total' . $dbTags);
                }
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

                if ($metric == $storageMertics[0]) {
                    $this->statsd->increment('buckets.{scope}.count.total' . $storageTags);
                }
                if ($metric == $storageMertics[3]) {
                    $this->statsd->decrement('buckets.{scope}.count.total' . $storageTags);
                }

                if ($metric == $storageMertics[4]) {
                    $this->statsd->increment('files.{scope}.count.total' . $storageTags);
                }
                if ($metric == $storageMertics[7]) {
                    $this->statsd->decrement('files.{scope}.count.total' . $storageTags);
                }
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

        $functionId = $this->params['functionId'] ?? '';
        $functionExecution = $this->params['executions.{scope}.compute'] ?? 0;
        $functionExecutionTime = ($this->params['executionTime'] ?? 0) * 1000; // ms
        $functionExecutionStatus = $this->params['executionStatus'] ?? '';

        $functionBuild = $this->params['builds.{scope}.compute'] ?? 0;
        $functionBuildTime = ($this->params['buildTime'] ?? 0) * 1000; // ms
        $functionBuildStatus = $this->params['buildStatus'] ?? '';
        $functionCompute = $functionExecutionTime + $functionBuildTime;

        if ($functionExecution >= 1) {
            $this->statsd->increment('executions.{scope}.compute' . $tags . ',functionId=' . $functionId . ',functionStatus=' . $functionExecutionStatus);
            if ($functionExecutionTime > 0) {
                $this->statsd->count('executions.{scope}.compute.time' . $tags . ',functionId=' . $functionId, $functionExecutionTime);
            }
        }
        if ($functionBuild >= 1) {
            $this->statsd->increment('builds.{scope}.compute' . $tags . ',functionId=' . $functionId . ',functionBuildStatus=' . $functionBuildStatus);
            $this->statsd->count('builds.{scope}.compute.time' . $tags . ',functionId=' . $functionId, $functionBuildTime);
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
