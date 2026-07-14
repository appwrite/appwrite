<?php

namespace Appwrite\Deployment\Backend;

use Ahc\Jwt\JWT;
use Appwrite\Deployment\Backend;
use Appwrite\Deployment\Token;
use OpenRuntimes\Orchestrator\Enum\CallbackEvent;
use OpenRuntimes\Orchestrator\Jobs;
use OpenRuntimes\Orchestrator\Model\Artifact\DownloadArtifact;
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
 * worker at its Appwrite path, so build.sh writes code.tar.gz + the cache
 * squashfs straight onto the volume Appwrite already reads. That keeps the
 * multi-hundred-MB output off the (capped) HTTP upload path and out of the
 * Appwrite process. Deployments that need a different strategy (e.g. S3
 * upload/download artifacts instead of a shared volume) override storage()
 * — everything else about the payload stays the same.
 *
 * Covers function deployments whose source is a tarball: manual upload,
 * duplicate/rebuild, and templates (public GitHub tarball resolved from a
 * git reference).
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

        // Pre-declare buildPath so build.sh writes output straight onto the
        // mounted builds volume, at the path this deployment expects.
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
        Document $function,
        Document $deployment,
        array $platform,
        ?array $source = null,
    ): array {
        $projectId = $project->getId();
        $deploymentId = $deployment->getId();
        $timeout = (int) System::getEnv('_APP_COMPUTE_BUILD_TIMEOUT', 900);

        $version = $function->getAttribute('version', 'v2');
        $runtime = self::runtime($function, $version);
        $spec = Config::getParam('specifications')[$function->getAttribute('buildSpecification', APP_COMPUTE_SPECIFICATION_DEFAULT)];
        $cpus = (float) ($spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT);
        $memory = \max((int) ($spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT), 1024);

        // The jobs-service (and the containers it spawns) reach Appwrite over
        // the internal Docker network, so the presigned + callback URLs use an
        // internal endpoint when configured, falling back to the public host.
        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
        $endpoint = System::getEnv('_APP_JOBS_ENDPOINT', "$protocol://{$platform['apiHostname']}");

        // Source artifacts, both ending in /mnt/code/source:
        //  - remote tarball ($source): templates (public codeload URL) and VCS
        //    (a short-lived presigned URL). The unarchive auto-strips the
        //    "{repo}-{ref}/" wrapper and, via subdir, extracts just the
        //    rootDirectory.
        //  - otherwise: the deployment's uploaded tarball, fetched from Appwrite
        //    over a presigned GET (manual upload / duplicate).
        if ($source !== null) {
            $subdir = \trim($source['subdir'] ?? '', '/');
            $sourceArtifacts = [
                new DownloadArtifact(id: 'source', in: $source['url'], out: 'source.tar.gz'),
                new UnarchiveArtifact(id: 'extract', in: 'source.tar.gz', out: 'source', subdir: $subdir !== '' ? $subdir : null),
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
            $base = "{$endpoint}/v1/functions/{$function->getId()}/deployments/{$deploymentId}";
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
        $output = static::storage($project, $function, $deployment);

        $command = $deployment->getAttribute('buildCommands', '');
        $env = self::variables($project, $function, $deployment, $runtime, $cpus, $memory, $endpoint, $timeout) + [
            'OPEN_RUNTIMES_BUILD_INPUT_DIR' => '/mnt/code/source',
        ] + $output['environment'];

        // TODO: Temporary diagnostic for the intermittent Gitea "No source
        // code found" CI failure. Appwrite can fetch the exact same presigned
        // URL fine (confirmed via a separate external probe), so this checks
        // reachability from inside the sidecar/job container itself, right
        // before build.sh runs. Uses `node` (guaranteed present in a runtime
        // image) rather than curl, which turned out to be absent here. Only
        // prints status/size (never the URL, which carries the access token)
        // into buildLogs. Remove once root-caused.
        $probeScript = "require('http').get(process.argv[1], r => { let s=0; r.on('data',c=>s+=c.length); r.on('end',()=>console.error('[vcs-source-probe-sidecar] status='+r.statusCode+' size='+s)); }).on('error', e => console.error('[vcs-source-probe-sidecar] error='+e.message));";
        // Stop guessing about subdir/unarchive semantics -- just show what the
        // artifact system actually placed at OPEN_RUNTIMES_BUILD_INPUT_DIR
        // right before build.sh reads it. `find` over `ls` since we need to
        // see into any unexpected nesting depth.
        $listingProbe = 'echo "[vcs-source-probe-listing]"; find /mnt/code/source -maxdepth 4 2>&1 || echo "[vcs-source-probe-listing] /mnt/code/source missing"; ';
        $sourceProbe = $source !== null
            ? 'node -e ' . \escapeshellarg($probeScript) . ' ' . \escapeshellarg($source['url']) . ' || echo "[vcs-source-probe-sidecar] node unavailable or failed"; ' . $listingProbe
            : '';

        return [
            'id' => static::id($projectId, $deploymentId),
            'image' => $runtime['image'],
            'command' => $sourceProbe . '/usr/local/server/helpers/build.sh ' . \escapeshellarg($command),
            'cpu' => $cpus,
            'memory' => $memory,
            'timeoutSeconds' => $timeout,
            'workspace' => '/mnt/code',
            'meta' => [
                'projectId' => $projectId,
                'deploymentId' => $deploymentId,
                'resourceId' => $function->getId(),
                'resourceType' => 'functions',
            ],
            // The orchestrator expects environment as a string->string map.
            'environment' => \array_map('strval', $env),
            'artifacts' => [...$sourceArtifacts, ...$output['artifacts']],
            'volumes' => $output['volumes'],
            'callback' => new Callback(
                url: "{$endpoint}/v1/jobs/event?" . \http_build_query(['project' => $projectId]),
                // Two terminal callbacks: exit carries the code (fires on
                // command exit, before post-job artifacts), complete confirms
                // artifact delivery (carries only jobId + meta) — the worker
                // joins them, so readiness holds on any storage strategy.
                // Artifact callbacks only carry the source-size stat, which
                // exists only for remote-source builds (templates / VCS).
                events: $source !== null
                    ? [CallbackEvent::Log, CallbackEvent::Artifact, CallbackEvent::Exit, CallbackEvent::Complete]
                    : [CallbackEvent::Log, CallbackEvent::Exit, CallbackEvent::Complete],
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
     * The build output path on the builds volume. build.sh writes code.tar.gz
     * into OPEN_RUNTIMES_BUILD_OUTPUT_DIR (this file's directory); pre-computed
     * so it can be persisted on the deployment before the job is submitted.
     */
    public static function buildPath(string $projectId, string $deploymentId): string
    {
        return APP_STORAGE_BUILDS . "/app-{$projectId}/{$deploymentId}/code.tar.gz";
    }

    /**
     * Deterministic build-cache key, shared across a function's deployments so
     * package-manager caches (npm/yarn/pnpm) survive between builds.
     */
    public static function cacheKey(string $projectId, string $functionId, string $image): string
    {
        return \substr(\hash('sha256', "{$projectId}:{$functionId}:{$image}"), 0, 48);
    }

    public static function cachePath(string $projectId, string $cacheKey): string
    {
        return APP_STORAGE_BUILDS . "/app-{$projectId}/cache/{$cacheKey}.sqfs";
    }

    /**
     * Where build.sh's output (code.tar.gz) and package-manager cache
     * (a squashfs) land, and what the job needs to get them there. The
     * default mounts the shared builds volume at buildPath()/cachePath();
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
        $runtime = self::runtime($resource, $resource->getAttribute('version', 'v2'));
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
                'OPEN_RUNTIMES_BUILD_OUTPUT_DIR' => \dirname(static::buildPath($projectId, $deploymentId)),
                'OPEN_RUNTIMES_BUILD_CACHE_ARTIFACT' => static::cachePath($projectId, $cacheKey),
            ],
        ];
    }

    protected static function runtime(Document $function, string $version): array
    {
        $runtimes = Config::getParam($version === 'v2' ? 'runtimes-v2' : 'runtimes', []);
        $runtime = $runtimes[$function->getAttribute('runtime')] ?? null;
        if ($runtime === null) {
            throw new \Exception('Runtime "' . $function->getAttribute('runtime', '') . '" is not supported');
        }

        return $runtime;
    }

    private static function variables(
        Document $project,
        Document $function,
        Document $deployment,
        array $runtime,
        float $cpus,
        int $memory,
        string $endpoint,
        int $timeout,
    ): array {
        $vars = [];

        foreach ($function->getAttribute('varsProject', []) as $var) {
            $vars[$var->getAttribute('key')] = $var->getAttribute('value', '');
        }
        foreach ($function->getAttribute('vars', []) as $var) {
            $vars[$var->getAttribute('key')] = $var->getAttribute('value', '');
        }

        $apiKey = (new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', $timeout, 0))->encode([
            'projectId' => $project->getId(),
            'scopes' => $function->getAttribute('scopes', []),
        ]);

        return \array_merge($vars, [
            // Consumed by the open-runtimes build helper (build.sh).
            'OPEN_RUNTIMES_ENTRYPOINT' => $deployment->getAttribute('entrypoint', ''),
            'OPEN_RUNTIMES_OUTPUT_DIRECTORY' => $deployment->getAttribute('buildOutput', '') ?: $function->getAttribute('outputDirectory', ''),
            'APPWRITE_VERSION' => APP_VERSION_STABLE,
            'APPWRITE_REGION' => $project->getAttribute('region'),
            'APPWRITE_DEPLOYMENT_TYPE' => $deployment->getAttribute('type', ''),
            'APPWRITE_FUNCTION_API_ENDPOINT' => "{$endpoint}/v1",
            'APPWRITE_FUNCTION_API_KEY' => API_KEY_EPHEMERAL . '_' . $apiKey,
            'APPWRITE_FUNCTION_ID' => $function->getId(),
            'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name'),
            'APPWRITE_FUNCTION_DEPLOYMENT' => $deployment->getId(),
            'APPWRITE_FUNCTION_PROJECT_ID' => $project->getId(),
            'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'] ?? '',
            'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'] ?? '',
            'APPWRITE_FUNCTION_CPUS' => $cpus,
            'APPWRITE_FUNCTION_MEMORY' => $memory,
            'OPEN_RUNTIMES_NFT' => System::getEnv('_APP_OPEN_RUNTIMES_NFT', 'enabled'),
        ]);
    }
}
