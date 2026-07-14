<?php

namespace Appwrite\Compute;

use Ahc\Jwt\JWT;
use Appwrite\Deployment\Token;
use OpenRuntimes\Orchestrator\Enum\CallbackEvent;
use OpenRuntimes\Orchestrator\Model\Artifact\DownloadArtifact;
use OpenRuntimes\Orchestrator\Model\Artifact\StatArtifact;
use OpenRuntimes\Orchestrator\Model\Artifact\UnarchiveArtifact;
use OpenRuntimes\Orchestrator\Model\Callback;
use OpenRuntimes\Orchestrator\Model\Volume;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git;

/**
 * Builds an open-runtimes jobs-service job payload for a function deployment.
 *
 * Source crosses the boundary via the artifacts system (presigned GET download
 * + unarchive, run by the sidecar) — a GET has no request-body cap, so large
 * sources are fine. The build output and package-manager cache instead go on a
 * mounted volume: the builds storage volume is attached to the build worker at
 * its Appwrite path, so build.sh writes code.tar.gz + the cache squashfs
 * straight onto the volume Appwrite already reads. That keeps the multi-hundred-MB
 * output off the (capped) HTTP upload path and out of the Appwrite process.
 *
 * Covers function deployments whose source is a tarball: manual upload,
 * duplicate/rebuild, and templates (public GitHub tarball via `$template`).
 */
final class Job
{
    /**
     * @return array<string, mixed> Named arguments for OpenRuntimes\Orchestrator\Jobs::create().
     */
    /**
     * @param  ?array{url: string, subdir?: string}  $source Remote tarball source
     *   (template codeload URL, or a VCS presigned URL). When null, the source is
     *   the deployment's own uploaded tarball, fetched from Appwrite via a
     *   presigned token (manual upload / duplicate).
     */
    public static function build(
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

        // Output + cache live on the mounted builds volume (see class doc): the
        // build writes code.tar.gz to the deployment's build dir and the cache
        // squashfs to its per-function keyed path — both directly on the volume
        // Appwrite reads, so nothing large crosses the HTTP boundary.
        $cacheKey = self::cacheKey($projectId, $function->getId(), $runtime['image'] ?? '');
        $command = $deployment->getAttribute('buildCommands', '');
        $env = self::variables($project, $function, $deployment, $runtime, $cpus, $memory, $endpoint, $timeout) + [
            'OPEN_RUNTIMES_BUILD_INPUT_DIR' => '/mnt/code/source',
            'OPEN_RUNTIMES_BUILD_OUTPUT_DIR' => \dirname(self::buildPath($projectId, $deploymentId)),
            'OPEN_RUNTIMES_BUILD_CACHE_ARTIFACT' => self::cachePath($projectId, $cacheKey),
        ];

        // TODO: Temporary diagnostic for the intermittent Gitea "No source
        // code found" CI failure. Appwrite can fetch the exact same presigned
        // URL fine (confirmed via a separate external probe), so this checks
        // reachability from inside the sidecar/job container itself, right
        // before build.sh runs. Uses `node` (guaranteed present in a runtime
        // image) rather than curl, which turned out to be absent here. Only
        // prints status/size (never the URL, which carries the access token)
        // into buildLogs. Remove once root-caused.
        $probeScript = "require('http').get(process.argv[1], r => { let s=0; r.on('data',c=>s+=c.length); r.on('end',()=>console.error('[vcs-source-probe-sidecar] status='+r.statusCode+' size='+s)); }).on('error', e => console.error('[vcs-source-probe-sidecar] error='+e.message));";
        $sourceProbe = $source !== null
            ? 'node -e ' . \escapeshellarg($probeScript) . ' ' . \escapeshellarg($source['url']) . ' || echo "[vcs-source-probe-sidecar] node unavailable or failed"; '
            : '';

        return [
            'id' => self::id($projectId, $deploymentId),
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
            'artifacts' => $sourceArtifacts,
            // Attach the builds storage volume (Docker volume / K8s PVC named by
            // _APP_BUILDS_VOLUME) to the worker at its Appwrite path so build.sh
            // writes output + cache straight onto it.
            'volumes' => [
                new Volume(source: System::getEnv('_APP_BUILDS_VOLUME', 'appwrite-builds'), path: APP_STORAGE_BUILDS),
            ],
            'callback' => new Callback(
                url: "{$endpoint}/v1/jobs/event?" . \http_build_query(['project' => $projectId]),
                // Artifact callbacks only carry the source-size stat, which exists
                // only for remote-source builds (templates / VCS).
                events: $source !== null
                    ? [CallbackEvent::Log, CallbackEvent::Artifact, CallbackEvent::Exit]
                    : [CallbackEvent::Log, CallbackEvent::Exit],
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
     * TODO: Under active investigation for the intermittent Gitea "No source
     * code found" CI failure. Confirmed via a live archive inspection that
     * Gitea's tarball is NOT auto-stripped of its `{repo}/` wrapper the way
     * GitHub's `{repo}-{ref}/` apparently is when no subdir is given (omitting
     * subdir entirely left the entrypoint nested one level too deep instead).
     * So the repository-name descent is genuinely required for Gitea -- but
     * with it, the real CI run still found nothing. Trying a trailing slash
     * here since tar directory entries are typically stored with one and the
     * orchestrator's subdir match may be doing exact rather than prefix
     * comparison. Remove this comment once confirmed either way.
     */
    public static function sourceSubdirectory(Git $vcs, string $repositoryName, string $rootDirectory): string
    {
        $rootDirectory = \trim($rootDirectory, '/');

        if ($vcs->getName() === 'gitea') {
            $subdir = \trim($repositoryName . '/' . $rootDirectory, '/');
            return $subdir . '/';
        }

        return $rootDirectory;
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

    private static function runtime(Document $function, string $version): array
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
