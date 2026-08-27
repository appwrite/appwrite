<?php

namespace Appwrite\Deployment;

use Ahc\Jwt\JWT;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Validator\VariableKey;
use OpenRuntimes\Orchestrator\Enum\CallbackEvent;
use OpenRuntimes\Orchestrator\Enum\ReadFormat;
use OpenRuntimes\Orchestrator\Jobs;
use OpenRuntimes\Orchestrator\Model\Artifact\CloneArtifact;
use OpenRuntimes\Orchestrator\Model\Artifact\DownloadArtifact;
use OpenRuntimes\Orchestrator\Model\Artifact\ReadArtifact;
use OpenRuntimes\Orchestrator\Model\Artifact\StatArtifact;
use OpenRuntimes\Orchestrator\Model\Artifact\UnarchiveArtifact;
use OpenRuntimes\Orchestrator\Model\Callback;
use OpenRuntimes\Orchestrator\Model\Volume;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git;

/**
 * Owns a deployment's lifecycle: upload bookkeeping, creating it and
 * dispatching the build as an open-runtimes jobs-service job, and canceling one
 * in flight.
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
 * upload, duplicate/rebuild, VCS commits, and templates (public GitHub tarball
 * resolved from a git reference). Site builds also emit a JSON build manifest,
 * read back post-job as an artifact callback for adapter detection.
 */
readonly class Deployments
{
    public function __construct(
        private Jobs $jobs,
        protected Database $dbForProject,
        protected Document $project,
        private array $platform,
    ) {
    }

    /**
     * Saves chunked-upload progress onto the deployment — source path/size,
     * chunk counters, metadata. Never triggers a build; call createFromUpload()
     * once the upload is complete. Pass a single `Document` carrying every field
     * the deployment should end up with: either a fresh, not-yet-persisted
     * one (a plain `new Document([...])`), or the existing one fetched from
     * the database with more attributes set via `setAttributes()`. A new
     * document (one with no $sequence, i.e. not yet persisted — a document
     * only gets one from the database, never from `setAttributes()`) gets
     * the standard $permissions and resourceId/resourceInternalId/
     * resourceType merged in automatically.
     */
    public function upload(Document $resource, Document $deployment): Document
    {
        if ($deployment->getSequence() === null) {
            return $this->dbForProject->createDocument('deployments', new Document([
                '$permissions' => self::permissions(),
                ...self::resourceFields($resource),
                ...$deployment->getArrayCopy(),
            ]));
        }

        return $this->dbForProject->updateDocument('deployments', $deployment->getId(), $deployment);
    }

    /**
     * Finalizes the deployment document (see upload() for what `$deployment`
     * should carry) and dispatches it for building from its own uploaded
     * source. Marks it queued, writes its buildPath and deactivates any other
     * active deployment for $resource. A deployment canceled before it is
     * queued is left canceled and never dispatched. Returns the persisted,
     * updated deployment.
     */
    public function createFromUpload(Document $resource, Document $deployment): Document
    {
        return $this->submit($resource, $deployment, null);
    }

    /**
     * Same as createFromUpload(), but builds from a public git reference
     * (a template's repository) instead of the deployment's own uploaded
     * source. $reference is already resolved to a concrete commit/branch/tag
     * — resolving a version range (e.g. "0.3.*") is the caller's job, since
     * only it holds the GitHub client that can do so.
     */
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

    /**
     * Same as createFromUpload(), but builds from a remote tarball at $url
     * (a VCS presigned URL) instead of the deployment's own uploaded source.
     */
    /**
     * @param array<string, string> $headers Sent with the source fetch, for a
     *                                       url whose provider authenticates by header
     */
    public function createFromUrl(
        Document $resource,
        Document $deployment,
        string $url,
        string $rootDirectory = '',
        array $headers = [],
    ): Document {
        return $this->submit($resource, $deployment, ['url' => $url, 'subdir' => $rootDirectory, 'headers' => $headers]);
    }

    /**
     * Same as createFromUpload(), but builds from a repository on a VCS
     * provider: a presigned archive URL when the provider hands those out, or
     * a git clone through the jobs-service's clone artifact when the provider
     * serves content over the git protocol only (Origin).
     *
     * @param string $ref Branch, tag, or commit the deployment builds from
     */
    public function createFromVcs(
        Document $resource,
        Document $deployment,
        Git $vcs,
        string $owner,
        string $repository,
        string $ref,
        string $rootDirectory = '',
    ): Document {
        if ($vcs->supportsRepositoryArchives()) {
            return $this->createFromUrl(
                $resource,
                $deployment,
                $vcs->getRepositoryPresignedUrl($owner, $repository, $ref),
                $rootDirectory,
                $vcs->getRepositoryPresignedUrlHeaders(),
            );
        }

        return $this->submit($resource, $deployment, [
            'clone' => $vcs->getRepositoryCloneUrl($owner, $repository),
            'ref' => $ref,
            'subdir' => $rootDirectory,
            'headers' => $vcs->getRepositoryCloneHeaders(),
        ]);
    }

    private function submit(Document $resource, Document $deployment, ?array $source): Document
    {
        // The caller may have been holding this deployment for a while (the
        // Builds worker pushes a template commit first), so its status is stale
        // by now — dropping it keeps upload() from writing a cancel away, and
        // the queued transition below is guarded instead.
        $deployment->removeAttribute('status');
        $deployment = $this->upload($resource, $deployment);

        $queued = $this->dbForProject->updateDocuments('deployments', new Document([
            'status' => 'waiting',
            'buildPath' => static::buildPath($this->project->getId(), $deployment->getId()),
        ]), [
            Query::equal('$id', [$deployment->getId()]),
            Query::notEqual('status', 'canceled'),
        ]);

        $deployment = $this->dbForProject->getDocument('deployments', $deployment->getId());

        // Canceled before it could be queued: leave it canceled, submit nothing,
        // and leave the currently active deployment alone.
        if ($queued === 0) {
            return $deployment;
        }

        // Claiming activation takes it away from the other pending deployments,
        // so a cancel landing while we do it would leave nothing able to go
        // live. Hand the claim back to exactly the deployments it was taken
        // from, and stop before submitting a job for a canceled deployment.
        $deactivated = $this->deactivateOthers($resource, $deployment);
        if ($deactivated !== [] && $this->status($deployment->getId()) === 'canceled') {
            foreach ($deactivated as $other) {
                $this->dbForProject->updateDocument('deployments', $other, new Document([
                    'activate' => true,
                ]));
            }

            return $this->dbForProject->getDocument('deployments', $deployment->getId());
        }

        try {
            $this->jobs->create(...static::payload($this->project, $resource, $deployment, $this->platform, $source));
        } catch (\Throwable $error) {
            // A refused variable key is the owner's to fix, so the build log
            // carries the actual reason; anything else stays a generic
            // internal error.
            $buildLogs = $error instanceof Exception && $error->getType() === Exception::VARIABLE_INVALID_KEY
                ? "\n" . $error->getMessage() . "\n"
                : "\nAn internal error occurred while building. Please try again, and contact support if the problem persists.\n";

            // Guarded like the transition above: a cancel that landed while the
            // job was being submitted must not be reported as a failure.
            $this->dbForProject->updateDocuments('deployments', new Document([
                'status' => 'failed',
                'buildLogs' => $buildLogs,
                'buildEndedAt' => DateTime::now(),
            ]), [
                Query::equal('$id', [$deployment->getId()]),
                Query::notEqual('status', 'canceled'),
            ]);

            throw $error;
        }

        return $deployment;
    }

    /**
     * Best-effort cancel of an in-flight build. The deployment is already
     * marked canceled by the caller; this only needs to stop the backend from
     * still writing to it.
     */
    public function cancel(string $deploymentId): void
    {
        $this->jobs->delete(static::id($this->project->getId(), $deploymentId));
    }

    /**
     * Deactivates any other active deployment for $resource before this one
     * goes live, called once the deployment is queued for building.
     *
     * @return array<string> The ids it deactivated, so the caller can hand the
     *                       claim back if this deployment never gets to build.
     */
    protected function deactivateOthers(Document $resource, Document $deployment): array
    {
        if (!$deployment->getAttribute('activate', false)) {
            return [];
        }

        $others = $this->dbForProject->find('deployments', [
            Query::equal('activate', [true]),
            Query::equal('resourceId', [$resource->getId()]),
            Query::equal('resourceType', [$resource->getCollection()]),
            Query::notEqual('$id', $deployment->getId()),
        ]);

        $deactivated = [];
        foreach ($others as $other) {
            $this->dbForProject->updateDocument('deployments', $other->getId(), new Document([
                'activate' => false,
            ]));
            $deactivated[] = $other->getId();
        }

        return $deactivated;
    }

    private function status(string $deploymentId): string
    {
        return $this->dbForProject->getDocument('deployments', $deploymentId)->getAttribute('status', '');
    }

    /**
     * The build command for a deployment: its buildCommands, wrapped for
     * sites with the framework's env and bundle commands.
     */
    public static function command(Document $resource, Document $deployment): string
    {
        $command = $deployment->getAttribute('buildCommands', '');
        if ($resource->getCollection() !== 'sites') {
            return $command;
        }

        $framework = Config::getParam('frameworks', [])[$resource->getAttribute('framework', '')] ?? [];

        return \implode(' && ', \array_filter([
            $framework['envCommand'] ?? '',
            $command,
            $framework['bundleCommand'] ?? '',
        ], fn ($command) => !empty($command)));
    }

    /**
     * Resolve the command passed to helpers/start.sh.
     *
     * Framework and runtime defaults use relative paths such as
     * `bash helpers/server.sh`, which must be resolved from `/usr/local/server`.
     * Console creation flows persist that same default onto the deployment. Always
     * wrapping a non-empty deployment startCommand with `cd .../src/function`
     * breaks those relative helper paths and causes the runtime to crash-loop
     * until the request times out (HTTP 408).
     *
     * Only truly custom deployment start commands are prefixed with a cd into
     * the function source directory.
     */
    public static function startCommand(Document $deployment, string $default): string
    {
        $command = $deployment->getAttribute('startCommand', '');

        if ($command === '' || $command === $default) {
            return $default;
        }

        $escaped = \str_replace(['"', '`', '$'], ['\\"', '\\`', '\\$'], $command);

        return 'cd /usr/local/server/src/function/ && ' . $escaped;
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

        // Source artifacts, all ending in /mnt/code/source:
        //  - remote tarball ($source with url): templates (public codeload URL)
        //    and VCS (a short-lived presigned URL). Git-forge archives wrap the
        //    tree in a "{repo}-{ref}/" root the caller can't predict, so strip
        //    drops it and subdir then extracts just the rootDirectory from the
        //    unwrapped tree. Uploaded tarballs (the else branch) are flat — no
        //    strip.
        //  - git clone ($source with clone): a provider without archive
        //    downloads; the sidecar clones over Git HTTPS and checks the tree
        //    out directly, so there is no archive to unarchive — or to stat,
        //    which is why this path reports no sourceSize.
        //  - otherwise: the deployment's uploaded tarball, fetched from Appwrite
        //    over a presigned GET (manual upload / duplicate).
        if (isset($source['clone'])) {
            $subdir = \trim($source['subdir'] ?? '', '/');
            $sourceArtifacts = [
                new CloneArtifact(id: 'source', in: $source['clone'], out: 'source', ref: $source['ref'] ?? '', subdir: $subdir, headers: $source['headers'] ?? []),
            ];
        } elseif ($source !== null) {
            $subdir = \trim($source['subdir'] ?? '', '/');
            $sourceArtifacts = [
                new DownloadArtifact(id: 'source', in: $source['url'], out: 'source.tar.gz', headers: $source['headers'] ?? []),
                new UnarchiveArtifact(id: 'extract', in: 'source.tar.gz', out: 'source', subdir: $subdir !== '' ? $subdir : null, strip: true, depends: 'source'),
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
                new UnarchiveArtifact(id: 'extract', in: 'source.tar.gz', out: 'source', depends: 'source'),
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

    /**
     * Scopes encoded into the resource's auto-generated ephemeral API keys
     *
     * @return array<string>
     */
    public static function scopes(Document $resource): array
    {
        $granted = Config::getParam('computeScopes', [])[$resource->getCollection()] ?? [];

        return \array_values(\array_unique(\array_merge($resource->getAttribute('scopes', []), $granted)));
    }

    protected static function runtime(Document $resource, string $version): array
    {
        $key = $resource->getAttribute($resource->getCollection() === 'sites' ? 'buildRuntime' : 'runtime');
        $runtime = Config::getParam($version === 'v2' ? 'runtimes-v2' : 'runtimes', [])[$key] ?? null;
        if ($runtime === null) {
            throw new Exception(Exception::FUNCTION_RUNTIME_UNSUPPORTED, 'Runtime "' . $key . '" is not supported');
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

        // Keys that predate the VariableKey endpoint guard can hold bytes the
        // orchestrator refuses in an env var name (a stray tab, UTF-16 text),
        // which would reject the whole build job after submission. Refuse only
        // what the cluster would refuse, before the job leaves this process.
        foreach (\array_keys($vars) as $key) {
            if (!VariableKey::isEnvVarName((string) $key)) {
                throw new Exception(
                    Exception::VARIABLE_INVALID_KEY,
                    'Variable key ' . \json_encode((string) $key) . ' is not a valid environment variable name. Update or delete this variable, then retry the deployment.'
                );
            }
        }

        $apiKey = (new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', $timeout, 0))->encode([
            'projectId' => $project->getId(),
            'scopes' => static::scopes($resource),
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

    /**
     * @return array<string>
     */
    private static function permissions(): array
    {
        return [
            Permission::read(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function resourceFields(Document $resource): array
    {
        return [
            'resourceInternalId' => $resource->getSequence(),
            'resourceId' => $resource->getId(),
            'resourceType' => $resource->getCollection(),
        ];
    }
}
