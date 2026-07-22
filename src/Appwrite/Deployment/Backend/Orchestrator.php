<?php

namespace Appwrite\Deployment\Backend;

use Ahc\Jwt\JWT;
use Appwrite\Deployment\Backend;
use Appwrite\Deployment\Token;
use OpenRuntimes\Orchestrator\Enum\CallbackEvent;
use OpenRuntimes\Orchestrator\Enum\ReadFormat;
use OpenRuntimes\Orchestrator\Jobs;
use OpenRuntimes\Orchestrator\Model\Artifact\DownloadArtifact;
use OpenRuntimes\Orchestrator\Model\Artifact\ReadArtifact;
use OpenRuntimes\Orchestrator\Model\Artifact\StatArtifact;
use OpenRuntimes\Orchestrator\Model\Artifact\UnarchiveArtifact;
use OpenRuntimes\Orchestrator\Model\Callback;
use OpenRuntimes\Orchestrator\Model\Volume;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\System\System;

/**
 * Builds and submits an open-runtimes jobs-service job for a deployment.
 *
 * Source crosses the boundary via the artifacts system (presigned GET download
 * + unarchive, run by the sidecar) — a GET has no request-body cap, so large
 * sources are fine. The build output and package-manager cache, by default,
 * go on a mounted volume: the builds storage volume is attached to the build
 * worker at its Appwrite path, so build.sh writes its artifact + the cache
 * squashfs straight onto the volume Appwrite already reads. That keeps the
 * multi-hundred-MB output off the (capped) HTTP upload path and out of the
 * Appwrite process. Deployments that need a different strategy (e.g. S3
 * upload/download artifacts instead of a shared volume) override storage()
 * — everything else about the payload stays the same.
 *
 * Covers function and site deployments whose source is a tarball: manual
 * upload, duplicate/rebuild, and templates (public GitHub tarball resolved
 * from a git reference). Site builds also emit a JSON build manifest, read
 * back post-job as an artifact callback for adapter detection.
 */
readonly class Orchestrator extends Backend
{
    public function __construct(
        private Jobs $jobs,
        Database $dbForProject,
        Document $project,
        private array $platform,
    ) {
        parent::__construct($dbForProject, $project);
    }


    public function createFromUpload(Document $resource, Document $deployment): Document
    {
        return $this->submit($resource, $deployment, null);
    }

    public function createFromRef(
        Document $resource,
        Document $deployment,
        string $owner,
        string $repository,
        string $type,
        string $reference,
        string $rootDirectory = '',
    ): Document {
        // The jobs-service has no GitHub client of its own — it only fetches
        // tarballs — so $reference must already be a concrete commit/branch/
        // tag; codeload only understands one ref per tarball, not a range.
        $url = "https://codeload.github.com/{$owner}/{$repository}/tar.gz/{$reference}";

        return $this->submit($resource, $deployment, ['url' => $url, 'subdir' => $rootDirectory]);
    }

    public function createFromUrl(
        Document $resource,
        Document $deployment,
        string $url,
        string $rootDirectory = '',
    ): Document {
        return $this->submit($resource, $deployment, ['url' => $url, 'subdir' => $rootDirectory]);
    }

    private function submit(Document $resource, Document $deployment, ?array $source): Document
    {
        $deployment = $this->upload($resource, $deployment);
        $this->deactivateOthers($resource, $deployment);

        $deployment = $this->dbForProject->updateDocument('deployments', $deployment->getId(), new Document([
            'status' => 'waiting',
            'buildPath' => static::buildPath($this->project->getId(), $deployment->getId()),
        ]));

        $this->jobs->create(...static::payload($this->project, $resource, $deployment, $this->platform, $source));

        return $deployment;
    }

    public function cancel(string $deploymentId): void
    {
        $this->jobs->delete(static::id($this->project->getId(), $deploymentId));
    }

    /**
     * @return array<string, mixed> Named arguments for OpenRuntimes\Orchestrator\Jobs::create().
     */
    protected static function payload(
        Document $project,
        Document $resource,
        Document $deployment,
        array $platform,
        ?array $source = null,
    ): array {
        $projectId = $project->getId();
        $deploymentId = $deployment->getId();
        $isSite = $resource->getCollection() === 'sites';
        $timeout = (int) System::getEnv('_APP_COMPUTE_BUILD_TIMEOUT', 900);

        $runtime = self::runtime($resource, self::version($resource));
        $spec = Config::getParam('specifications')[$resource->getAttribute('buildSpecification', APP_COMPUTE_SPECIFICATION_DEFAULT)];
        $cpus = (float) ($spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT);

        // Some runtimes/frameworks can't compile with less memory than this.
        $minMemory = $isSite ? 2048 : 1024;
        if (\in_array($resource->getAttribute('framework', ''), ['analog', 'tanstack-start'], true)) {
            $minMemory = 4096;
        }
        $memory = \max((int) ($spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT), $minMemory);

        // The jobs-service (and the containers it spawns) reach Appwrite over
        // the internal Docker network, so the presigned + callback URLs use an
        // internal endpoint when configured, falling back to the public host.
        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
        $endpoint = System::getEnv('_APP_JOBS_ENDPOINT', "$protocol://{$platform['apiHostname']}");

        // Source artifacts, both ending in /mnt/code/source:
        //  - remote tarball ($source): templates (public codeload URL) and VCS
        //    (a short-lived presigned URL). Git-forge archives wrap the tree in
        //    a "{repo}-{ref}/" root the caller can't predict, so strip drops it
        //    and subdir then extracts just the rootDirectory from the unwrapped
        //    tree. Uploaded tarballs (the else branch) are flat — no strip.
        //  - otherwise: the deployment's uploaded tarball, fetched from Appwrite
        //    over a presigned GET (manual upload / duplicate).
        if ($source !== null) {
            $subdir = \trim($source['subdir'] ?? '', '/');
            $sourceArtifacts = [
                new DownloadArtifact(id: 'source', in: $source['url'], out: 'source.tar.gz'),
                new UnarchiveArtifact(id: 'extract', in: 'source.tar.gz', out: 'source', subdir: $subdir !== '' ? $subdir : null, strip: true),
                // Appwrite never sees the remote source (the sidecar fetches it),
                // so unlike the uploaded-tarball path it can't size it. Stat the
                // downloaded archive so the orchestrator reports its byte size in
                // an artifact callback, which the worker records as sourceSize.
                new StatArtifact(id: 'sourceSize', in: 'source.tar.gz', depends: 'source'),
            ];
        } else {
            // Presigned source-download URL (GET, no request-body cap), fetched by
            // the sidecar. Bound to this deployment + direction; valid for the whole
            // build window plus transfer slack.
            $ttl = $timeout + 300;
            $base = "{$endpoint}/v1/{$resource->getCollection()}/{$resource->getId()}/deployments/{$deploymentId}";
            $sourceUrl = "{$base}/download?" . \http_build_query([
                'type' => Token::TYPE_SOURCE,
                'project' => $projectId,
                'token' => Token::sign($deploymentId, Token::TYPE_SOURCE, $ttl),
            ]);
            $sourceArtifacts = [
                new DownloadArtifact(id: 'source', in: $sourceUrl, out: 'source.tar.gz'),
                new UnarchiveArtifact(id: 'extract', in: 'source.tar.gz', out: 'source'),
            ];
        }

        // Where output + cache land is a swappable strategy (see storage()) —
        // the default mounts the shared builds volume; nothing else here cares
        // which strategy is active.
        $output = static::storage($project, $resource, $deployment);

        // Site builds write a JSON build manifest into the workspace, read
        // back post-job so the Jobs worker can run adapter detection.
        $manifestArtifacts = $isSite ? [new ReadArtifact(id: 'manifest', in: 'manifest.json', format: ReadFormat::Json, depends: 'job')] : [];

        $command = self::command($resource, $deployment);
        $env = self::variables($project, $resource, $deployment, $runtime, $cpus, $memory, $endpoint, $timeout) + [
            'OPEN_RUNTIMES_BUILD_INPUT_DIR' => '/mnt/code/source',
            'OPEN_RUNTIMES_BUILD_COMPRESSION' => static::compression(),
        ] + ($isSite ? ['OPEN_RUNTIMES_BUILD_MANIFEST' => '/mnt/code/manifest.json'] : []) + $output['environment'];

        // Two terminal callbacks: exit carries the code (fires before
        // post-job artifacts), complete confirms artifact delivery — the
        // worker joins them, so readiness holds on any storage strategy.
        // Artifact callbacks carry the source-size stat and the site manifest.
        $events = [CallbackEvent::Log, CallbackEvent::Exit, CallbackEvent::Complete];
        if ($source !== null || $isSite) {
            $events[] = CallbackEvent::Artifact;
        }

        return [
            'id' => static::id($projectId, $deploymentId),
            'image' => $runtime['image'],
            'command' => '/usr/local/server/helpers/build.sh ' . \escapeshellarg($command),
            'cpu' => $cpus,
            'memory' => $memory,
            'timeoutSeconds' => $timeout,
            'workspace' => '/mnt/code',
            'meta' => [
                'projectId' => $projectId,
                'deploymentId' => $deploymentId,
                'resourceId' => $resource->getId(),
                'resourceType' => $resource->getCollection(),
            ],
            // The orchestrator expects environment as a string->string map.
            'environment' => \array_map('strval', $env),
            'artifacts' => [...$sourceArtifacts, ...$manifestArtifacts, ...$output['artifacts']],
            'volumes' => $output['volumes'],
            'callback' => new Callback(
                url: "{$endpoint}/v1/jobs/event?" . \http_build_query(['project' => $projectId]),
                events: $events,
                key: System::getEnv('_APP_JOBS_SECRET', ''),
            ),
        ];
    }

    /**
     * The jobs-service job id for a deployment build (used to submit and cancel).
     */
    public static function id(string $projectId, string $deploymentId): string
    {
        return "{$projectId}-{$deploymentId}-build";
    }

    /**
     * The build output directory on the builds volume. The produced artifact's
     * complete path is discovered and persisted after the job finishes.
     */
    public static function outputDirectory(string $projectId, string $deploymentId): string
    {
        return APP_STORAGE_BUILDS . "/app-{$projectId}/{$deploymentId}";
    }

    /**
     * The build output path on the builds volume, declared at submission.
     */
    public static function buildPath(string $projectId, string $deploymentId): string
    {
        return static::outputDirectory($projectId, $deploymentId) . '/' . static::artifact();
    }

    /**
     * The artifact filename build.sh produces for the configured compression.
     */
    public static function artifact(): string
    {
        return match (static::compression()) {
            'none' => 'code.tar',
            'squashfs' => 'code.sqfs',
            default => 'code.tar.gz',
        };
    }

    protected static function compression(): string
    {
        return System::getEnv('_APP_COMPUTE_BUILD_COMPRESSION', 'gzip');
    }

    /**
     * Deterministic build-cache key, shared across a resource's deployments so
     * package-manager caches (npm/yarn/pnpm) survive between builds.
     */
    public static function cacheKey(string $projectId, string $resourceId, string $image): string
    {
        return \substr(\hash('sha256', "{$projectId}:{$resourceId}:{$image}"), 0, 48);
    }

    public static function cachePath(string $projectId, string $cacheKey): string
    {
        return APP_STORAGE_BUILDS . "/app-{$projectId}/cache/{$cacheKey}.sqfs";
    }

    /**
     * Where build.sh's output artifact and package-manager cache
     * (a squashfs) land, and what the job needs to get them there. The
     * default mounts the shared builds volume at outputDirectory()/cachePath();
     * build.sh only cares that OPEN_RUNTIMES_BUILD_OUTPUT_DIR/_CACHE_ARTIFACT
     * point somewhere on its local filesystem, volume-backed or not — so a
     * strategy without a shared volume (e.g. S3) instead points them at a
     * local tmp path and moves things in/out via 'artifacts':
     *   - cache pull, before the build: a plain DownloadArtifact (no
     *     `depends`, so it runs before the command) into the local cache path.
     *   - cache push and output upload, after the build: an UploadArtifact
     *     with `depends: 'job'` — 'job' is the orchestrator's sentinel id for
     *     "after the build command finishes", not an id of another artifact.
     *
     * @return array{volumes: array<Volume>, artifacts: array<mixed>, environment: array<string, string>}
     */
    protected static function storage(Document $project, Document $resource, Document $deployment): array
    {
        $projectId = $project->getId();
        $deploymentId = $deployment->getId();
        $runtime = self::runtime($resource, self::version($resource));
        $cacheKey = static::cacheKey($projectId, $resource->getId(), $runtime['image'] ?? '');

        return [
            // Docker volume / K8s PVC named by _APP_BUILDS_VOLUME, attached
            // to the worker at its Appwrite path so build.sh writes output +
            // cache straight onto it.
            'volumes' => [
                new Volume(source: System::getEnv('_APP_BUILDS_VOLUME', 'appwrite-builds'), path: APP_STORAGE_BUILDS),
            ],
            'artifacts' => [],
            'environment' => [
                'OPEN_RUNTIMES_BUILD_OUTPUT_DIR' => static::outputDirectory($projectId, $deploymentId),
                'OPEN_RUNTIMES_BUILD_CACHE_ARTIFACT' => static::cachePath($projectId, $cacheKey),
            ],
        ];
    }

    protected static function version(Document $resource): string
    {
        return $resource->getCollection() === 'sites' ? 'v5' : $resource->getAttribute('version', 'v2');
    }

    protected static function runtime(Document $resource, string $version): array
    {
        $key = $resource->getAttribute($resource->getCollection() === 'sites' ? 'buildRuntime' : 'runtime');
        $runtime = Config::getParam($version === 'v2' ? 'runtimes-v2' : 'runtimes', [])[$key] ?? null;
        if ($runtime === null) {
            throw new \Exception('Runtime "' . $key . '" is not supported');
        }

        return $runtime;
    }

    private static function variables(
        Document $project,
        Document $resource,
        Document $deployment,
        array $runtime,
        float $cpus,
        int $memory,
        string $endpoint,
        int $timeout,
    ): array {
        $vars = [];

        foreach ($resource->getAttribute('varsProject', []) as $var) {
            $vars[$var->getAttribute('key')] = $var->getAttribute('value', '');
        }
        foreach ($resource->getAttribute('vars', []) as $var) {
            $vars[$var->getAttribute('key')] = $var->getAttribute('value', '');
        }

        $apiKey = (new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', $timeout, 0))->encode([
            'projectId' => $project->getId(),
            'scopes' => $resource->getAttribute('scopes', []),
        ]);

        $prefix = $resource->getCollection() === 'sites' ? 'SITE' : 'FUNCTION';

        return \array_merge($vars, [
            // Consumed by the open-runtimes build helper (build.sh).
            'OPEN_RUNTIMES_ENTRYPOINT' => $deployment->getAttribute('entrypoint', ''),
            'OPEN_RUNTIMES_OUTPUT_DIRECTORY' => $deployment->getAttribute('buildOutput', '') ?: $resource->getAttribute('outputDirectory', ''),
            'APPWRITE_VERSION' => APP_VERSION_STABLE,
            'APPWRITE_REGION' => $project->getAttribute('region'),
            'APPWRITE_DEPLOYMENT_TYPE' => $deployment->getAttribute('type', ''),
            'APPWRITE_VCS_REPOSITORY_ID' => $deployment->getAttribute('providerRepositoryId', ''),
            'APPWRITE_VCS_REPOSITORY_NAME' => $deployment->getAttribute('providerRepositoryName', ''),
            'APPWRITE_VCS_REPOSITORY_OWNER' => $deployment->getAttribute('providerRepositoryOwner', ''),
            'APPWRITE_VCS_REPOSITORY_URL' => $deployment->getAttribute('providerRepositoryUrl', ''),
            'APPWRITE_VCS_REPOSITORY_BRANCH' => $deployment->getAttribute('providerBranch', ''),
            'APPWRITE_VCS_REPOSITORY_BRANCH_URL' => $deployment->getAttribute('providerBranchUrl', ''),
            'APPWRITE_VCS_COMMIT_HASH' => $deployment->getAttribute('providerCommitHash', ''),
            'APPWRITE_VCS_COMMIT_MESSAGE' => $deployment->getAttribute('providerCommitMessage', ''),
            'APPWRITE_VCS_COMMIT_URL' => $deployment->getAttribute('providerCommitUrl', ''),
            'APPWRITE_VCS_COMMIT_AUTHOR_NAME' => $deployment->getAttribute('providerCommitAuthor', ''),
            'APPWRITE_VCS_COMMIT_AUTHOR_URL' => $deployment->getAttribute('providerCommitAuthorUrl', ''),
            'APPWRITE_VCS_ROOT_DIRECTORY' => $deployment->getAttribute('providerRootDirectory', ''),
            "APPWRITE_{$prefix}_API_ENDPOINT" => "{$endpoint}/v1",
            "APPWRITE_{$prefix}_API_KEY" => API_KEY_EPHEMERAL . '_' . $apiKey,
            "APPWRITE_{$prefix}_ID" => $resource->getId(),
            "APPWRITE_{$prefix}_NAME" => $resource->getAttribute('name'),
            "APPWRITE_{$prefix}_DEPLOYMENT" => $deployment->getId(),
            "APPWRITE_{$prefix}_PROJECT_ID" => $project->getId(),
            "APPWRITE_{$prefix}_RUNTIME_NAME" => $runtime['name'] ?? '',
            "APPWRITE_{$prefix}_RUNTIME_VERSION" => $runtime['version'] ?? '',
            "APPWRITE_{$prefix}_CPUS" => $cpus,
            "APPWRITE_{$prefix}_MEMORY" => $memory,
            'OPEN_RUNTIMES_NFT' => System::getEnv('_APP_OPEN_RUNTIMES_NFT', 'enabled'),
        ]);
    }
}
