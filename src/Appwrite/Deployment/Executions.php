<?php

namespace Appwrite\Deployment;

use Appwrite\Extend\Exception;
use OpenRuntimes\Orchestrator\Deployments as Orchestrator;
use OpenRuntimes\Orchestrator\Exception\ApiException;
use OpenRuntimes\Orchestrator\Model\Artifact\DownloadArtifact;
use OpenRuntimes\Orchestrator\Model\Autoscaling;
use OpenRuntimes\Orchestrator\Model\Volume;
use Utopia\Client;
use Utopia\Client\Exception\TimeoutException;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Psr7\Request\Factory as RequestFactory;
use Utopia\Storage\Device;
use Utopia\Storage\DeviceType;
use Utopia\System\System;

/**
 * Runs a deployment's build as an orchestrator deployment: one open-runtimes
 * container per Appwrite deployment, applied declaratively before every request
 * (an unchanged spec is a no-op) and called through the orchestrator's data
 * plane, which holds the request across a cold start and scales the container
 * to zero when idle.
 *
 * Code reaches the runtime the way build output left it (see
 * Deployments::storage()): on the local device the runtime mounts its slice of
 * the builds volume and extracts the archive in place, and each request's logs
 * land next to it under the execution id, where Appwrite reads and removes them
 * once the response is in. On a remote device (S3 and friends) the sidecar
 * downloads the archive into the workspace; with no filesystem in common, logs
 * then go to the container's stdout only.
 */
readonly class Executions
{
    /** The port every open-runtimes server listens on. */
    private const int PORT = 3000;

    /** Head of a log file worth reading; the rest is dropped before truncation. */
    private const int LOG_READ_LIMIT = 1_048_576;

    private RequestFactory $factory;

    public function __construct(
        private Orchestrator $orchestrator,
        private Client $client,
    ) {
        $this->factory = new RequestFactory();
    }

    /**
     * Apply the deployment and send it one request.
     *
     * @param array<string, mixed> $platform The platform whose API hostname the runtime is told to call.
     * @param array<string, string> $headers
     * @return array{statusCode: int, headers: array<string, string|array<string>>, body: string, logs: string, errors: string, duration: float}
     *
     * @throws TimeoutException when no response arrives within $requestTimeout seconds
     */
    public function execute(
        Document $project,
        Document $resource,
        Document $deployment,
        array $runtime,
        array $spec,
        array $platform,
        string $executionId,
        string $method,
        string $path,
        array $headers,
        string $body,
        int $timeout,
        int $requestTimeout,
    ): array {
        if (Deployments::version($resource) === 'v2') {
            throw new Exception(Exception::FUNCTION_RUNTIME_UNSUPPORTED, 'Legacy v2 runtimes can no longer be executed. Update the function to a current runtime and redeploy.');
        }

        $projectId = $project->getId();
        $deploymentId = $deployment->getId();
        $id = "{$projectId}-{$deploymentId}";
        $cpus = (float) ($spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT);
        $memory = (int) ($spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT);
        $device = Deployments::device($projectId);
        $local = $device->getType() === DeviceType::Local;
        $directory = Deployments::outputDirectory($projectId, $deploymentId);
        $logs = $local ? "{$directory}/logs" : '';
        $code = $deployment->getAttribute('buildPath', '');

        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
        $endpoint = "{$protocol}://{$platform['apiHostname']}";

        // Derived, not generated: the spec must not change between requests or
        // every execution would roll out a new revision.
        $secret = \hash_hmac('sha256', $id, System::getEnv('_APP_JOBS_SECRET', ''));

        $environment = Deployments::variables($project, $resource, $deployment, $runtime, $cpus, $memory, $endpoint) + [
            'OPEN_RUNTIMES_SECRET' => $secret,
            'OPEN_RUNTIMES_CODE_PATH' => $local ? $code : '/mnt/code/' . Deployments::artifact(),
            'OPEN_RUNTIMES_LOGS_DIRECTORY' => $logs,
        ];

        $status = $this->orchestrator->apply(
            id: $id,
            image: $runtime['image'],
            port: self::PORT,
            command: ($local ? 'mkdir -p ' . \escapeshellarg($logs) . ' && ' : '') . '/usr/local/server/helpers/start.sh "' . $this->startCommand($resource, $deployment, $runtime) . '"',
            cpu: $cpus,
            memory: $memory,
            meta: [
                'projectId' => $projectId,
                'deploymentId' => $deploymentId,
                'resourceId' => $resource->getId(),
                'resourceType' => $resource->getCollection(),
            ],
            workspace: '/mnt/code',
            environment: \array_map('strval', $environment),
            artifacts: $local ? [] : [
                new DownloadArtifact(id: 'code', in: Deployments::objectUrl($device, $code), out: Deployments::artifact()),
            ],
            volumes: $local ? [
                new Volume(source: System::getEnv('_APP_BUILDS_VOLUME', 'appwrite-builds'), path: $directory, subPath: "app-{$projectId}/{$deploymentId}"),
            ] : [],
            autoscaling: new Autoscaling(minReplicas: 0),
            // The runtime enforces the function timeout itself; this only bounds a
            // runtime that never answers.
            timeoutSeconds: $timeout + 15,
        );

        $headers['host'] = \parse_url($status->url, PHP_URL_HOST) ?: $id;
        $headers['x-open-runtimes-secret'] = $secret;
        $headers['x-open-runtimes-timeout'] = (string) \max($timeout, 1);
        $headers['x-open-runtimes-logging'] = $resource->getAttribute('logging', true) ? 'enabled' : 'disabled';
        $headers['x-open-runtimes-log-id'] = $executionId;

        $uri = System::getEnv('_APP_DEPLOYMENTS_HOST', 'http://orchestrator:8081') . '/' . \ltrim($path, '/');
        $request = $body === ''
            ? $this->factory->createRequest($method, $uri)
            : $this->factory->body($method, $uri, $body, $headers['content-type'] ?? 'text/plain');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, (string) $value);
        }

        $start = \microtime(true);
        $response = $this->client->withTimeout($requestTimeout)->sendRequest($request);
        $duration = \microtime(true) - $start;

        $responseHeaders = [];
        foreach ($response->getHeaders() as $name => $values) {
            $name = \strtolower($name);
            if (\str_starts_with($name, 'x-open-runtimes-')) {
                continue;
            }
            $responseHeaders[$name] = \count($values) === 1 ? $values[0] : $values;
        }

        return [
            'statusCode' => $response->getStatusCode(),
            'headers' => $responseHeaders,
            'body' => (string) $response->getBody(),
            'logs' => $local ? $this->collect($device, "{$logs}/{$executionId}_logs.log", APP_FUNCTION_LOG_LENGTH_LIMIT, 'Logs') : '',
            'errors' => $local ? $this->collect($device, "{$logs}/{$executionId}_errors.log", APP_FUNCTION_ERROR_LENGTH_LIMIT, 'Errors') : '',
            'duration' => $duration,
        ];
    }

    /**
     * Tear down a deployment's runtime. Already gone is fine.
     */
    public function delete(string $projectId, string $deploymentId): void
    {
        try {
            $this->orchestrator->delete("{$projectId}-{$deploymentId}");
        } catch (ApiException $e) {
            if ($e->statusCode !== 404) {
                throw $e;
            }
        }
    }

    /**
     * The command handed to helpers/start.sh: the runtime's default, a site
     * adapter's override, or the deployment's own — already escaped for the
     * double quotes it is wrapped in.
     */
    private function startCommand(Document $resource, Document $deployment, array $runtime): string
    {
        $default = $runtime['startCommand'];
        if ($resource->getCollection() === 'sites') {
            $framework = Config::getParam('frameworks', [])[$resource->getAttribute('framework', '')] ?? [];
            $default = ($framework['adapters'] ?? [])[$deployment->getAttribute('adapter', '')]['startCommand'] ?? $default;
        }

        return Deployments::startCommand($deployment, $default);
    }

    /**
     * Read one of the request's log files off the builds volume, remove it, and
     * keep the tail that fits the stored limit.
     */
    private function collect(Device $device, string $file, int $limit, string $kind): string
    {
        if (!$device->exists($file)) {
            return '';
        }

        $text = (string) $device->read($file, 0, self::LOG_READ_LIMIT);
        $device->delete($file);

        if (\strlen($text) <= $limit) {
            return $text;
        }

        $warning = "[WARNING] {$kind} truncated. The output exceeded {$limit} characters.\n";

        return $warning . \substr($text, -($limit - \strlen($warning)));
    }
}
