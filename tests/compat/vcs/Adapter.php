<?php

namespace Tests\Compat\Vcs;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Adapter\Redis;
use Utopia\Cache\Cache;
use Utopia\Console\Command;
use Utopia\Console\Console;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Adapter\Git\Bitbucket;
use Utopia\VCS\Adapter\Git\Forgejo;
use Utopia\VCS\Adapter\Git\Gitea;
use Utopia\VCS\Adapter\Git\GitHub;
use Utopia\VCS\Adapter\Git\GitLab;
use Utopia\VCS\Adapter\Git\Gogs;
use Utopia\VCS\Adapter\Git\Origin;
use Utopia\VCS\Tests\LocalGit;

/** GitHub on another API host: the protected endpoint a subclass may set. */
final class FixtureGitHub extends GitHub
{
    public function __construct(Cache $cache, string $endpoint)
    {
        parent::__construct($cache);
        $this->endpoint = $endpoint;
    }
}

/** Bitbucket with another browser and clone host. */
final class FixtureBitbucket extends Bitbucket
{
    public function __construct(Cache $cache, string $web)
    {
        parent::__construct($cache);
        $this->bitbucketUrl = $web;
    }
}

/** Origin on other API, browser and Git hosts. */
final class FixtureOrigin extends Origin
{
    public function __construct(Cache $cache, string $endpoint, string $web, string $git)
    {
        parent::__construct($cache);
        $this->endpoint = $endpoint;
        $this->webEndpoint = $web;
        $this->gitEndpoint = $git;
    }
}

/**
 * Maps tests/compat/vcs/spec.json operations onto utopia-php/vcs. Glue only.
 *
 * `vcs.new` builds an adapter (a handle); `vcs.<method>` calls the method of
 * that name (snake_case of the PHP name) with the case's named arguments
 * and returns its result, or `{"$error": {class, message, code}}`. Its
 * `adapter` is a handle, or a provider's name for a fresh adapter (fuzzing),
 * and a `payload` that is not a string is passed as its json_encode(). URLs in
 * arguments carry `${vcs}` and `${vcs-tls}`, the fixture server's base for
 * this runtime (services `vcs-http`, `vcs-https`), and `${vcs-host}` and
 * `${vcs-tls-host}` the same without the scheme (where a clone URL carries
 * credentials before it); results carry them back
 * in its place, so both runtimes' results compare. `fixture.*` talk to the
 * fixture server's control API: what it recorded, canned answers, its
 * configuration and the last webhook it caught.
 */
final class Adapter implements Base, Benchmarks
{
    /** The adapter methods, as `vcs.<snake_case>` operations. */
    private const array METHODS = [
        'batchGetRepositoryContents', 'batchUpsertCheckRuns', 'compareCommits', 'createBranch', 'createCheckRun', 'createComment',
        'createFile', 'createOrganization', 'createPullRequest', 'createPullRequestReview', 'createRepository', 'createTag',
        'createWebhook', 'deleteInstallation', 'deleteRepository', 'dismissPullRequestReview', 'generateCloneCommand',
        'getAuthenticatedApp', 'getBlob', 'getBranchUrl', 'getCheckRun', 'getCheckSuite', 'getComment', 'getCommit',
        'getCommitStatuses', 'getCommitUrl', 'getEventHeaderName', 'getEvents', 'getFileUrl', 'getInstallUrl',
        'getInstallationRepository', 'getLatestCommit', 'getName', 'getOrganizationUrl', 'getOwnerName', 'getPullRequest',
        'getPullRequestFiles', 'getPullRequestFromBranch', 'getRateLimit', 'getRepository', 'getRepositoryCloneHeaders',
        'getRepositoryCloneUrl', 'getRepositoryContent', 'getRepositoryName', 'getRepositoryPresignedUrl',
        'getRepositoryPresignedUrlHeaders', 'getRepositoryTree', 'getRepositoryUrl', 'getSignatureHeaderName', 'getSigningKeys',
        'getSupportedWebhookScopes', 'getType', 'getUser', 'hasAccessToAllRepositories', 'initializeVariables', 'listBranches',
        'listCheckRunsForCommit', 'listCheckRunsForSuite', 'listCommitFiles', 'listCommits', 'listInstallations',
        'listNamespaces', 'listPullRequestComments', 'listPullRequestCommits', 'listPullRequestReviews', 'listPullRequests',
        'listRepositoryContents', 'listRepositoryLanguages', 'listTags', 'listWebhookDeliveries', 'mergePullRequest',
        'redeliverWebhookDeliveries', 'searchRepositories', 'setEndpoint', 'supportsCommentImages', 'supportsPublicRepositories',
        'supportsRepositoryArchives', 'supportsRepositoryCreation', 'supportsRepositoryDeletion', 'syncMirror', 'updateCheckRun',
        'updateComment', 'updateCommitStatus', 'updatePullRequest', 'updatePullRequestReview', 'validateWebhookEvent', 'verifyReceipt',
    ];

    public function benchmarks(): array
    {
        $push = (string) file_get_contents(__DIR__ . '/benchmarks/github-push.json');
        $secret = 'bench-webhook-secret';
        $signature = 'sha256=' . hash_hmac('sha256', $push, $secret);

        return [
            'github_push_event' => ['Parse a GitHub push delivery into its event', fn (Session $s) => fn () => (new GitHub(new Cache(new None())))->getEvents('push', $push)],
            'github_validate_webhook' => ['Validate a GitHub push delivery\'s HMAC-SHA256 signature', fn (Session $s) => fn () => (new GitHub(new Cache(new None())))->validateWebhookEvent($push, $signature, $secret)],
            'gitlab_merge_request_event' => ['Parse a GitLab merge request delivery into its event', function (Session $s) {
                $payload = (string) file_get_contents(__DIR__ . '/benchmarks/gitlab-merge-request.json');
                $gitlab = new GitLab(new Cache(new None()));

                return fn () => $gitlab->getEvents('Merge Request Hook', $payload);
            }],
            'bitbucket_push_event' => ['Parse a Bitbucket push delivery naming three refs into its events', function (Session $s) {
                $payload = (string) file_get_contents(__DIR__ . '/benchmarks/bitbucket-push.json');
                $bitbucket = new Bitbucket(new Cache(new None()));

                return fn () => $bitbucket->getEvents('repo:push', $payload);
            }],
            'clone_command' => ['Build the sparse shallow clone command of a tag glob', function (Session $s) {
                $gitea = new Gitea(new Cache(new None()));
                $gitea->initializeVariables('', '', accessToken: 'bench-token');

                return fn () => (string) $gitea->generateCloneCommand('acme', 'website', 'v1.*', Git::CLONE_TYPE_TAG, '/tmp/build', 'apps/web');
            }],
            'gitea_latest_commit' => ['Read a branch\'s latest commit from the fixture server (one API call)', function (Session $s) {
                $gitea = self::benchGitea($s);

                return fn () => $gitea->getLatestCommit('bench-org', 'bench-repo', 'main');
            }],
        ];
    }

    /** A Gitea adapter on the fixture server, with one repository holding a commit. */
    private static function benchGitea(Session $s): Gitea
    {
        $gitea = new Gitea(new Cache(new None()));
        $gitea->initializeVariables('', '', accessToken: 'bench-token');
        $gitea->setEndpoint($s->service('vcs-http') . '/~' . $s->ns . '-bench/gitea');
        $gitea->createOrganization('bench-org');
        $gitea->createRepository('bench-org', 'bench-repo', false);
        $gitea->createFile('bench-org', 'bench-repo', 'README.md', '# Bench');

        return $gitea;
    }

    public function operations(): array
    {
        $ops = [
            'vcs.new' => fn (array $a, Session $s) => $s->handle(self::adapter($a, $s)),
            'fixture.requests' => fn (array $a, Session $s) => self::back(self::control($s, 'requests'), $s),
            'fixture.script' => fn (array $a, Session $s) => self::control($s, 'script', ['routes' => self::in($a['routes'] ?? [], $s)]),
            'fixture.config' => fn (array $a, Session $s) => self::control($s, 'config', self::in($a['config'] ?? [], $s)),
            'fixture.caught' => fn (array $a, Session $s) => self::back(self::control($s, 'caught'), $s),
            'clone.setup' => fn (array $a, Session $s) => self::cloneSetup($a),
            'clone.tag' => fn (array $a, Session $s) => self::cloneTag($a),
            'clone.ref' => fn (array $a, Session $s) => self::git(['-C', $a['repository'], 'update-ref', $a['ref'], $a['target']]),
            'clone.command' => fn (array $a, Session $s) => self::call(fn () => (string) (new LocalGit($a['repository']))->generateCloneCommand('owner', 'repository', $a['version'], $a['versionType'], $a['directory'], $a['rootDirectory']), $s),
            'clone.run' => fn (array $a, Session $s) => self::run(self::in($a['command'], $s), $a['directory'], $a['workspace'] ?? null),
        ];
        foreach (self::METHODS as $method) {
            $ops['vcs.' . self::snake($method)] = fn (array $a, Session $s) => self::invoke($method, $a, $s);
        }

        return $ops;
    }

    private static function snake(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    /**
     * The process environment the cases' git commands run in: a global
     * config of this driver's own (the clone command writes to it), TLS
     * verification off for the fixture server's own certificate, and a
     * fixed date, so commits git makes are the same whichever runtime made
     * them.
     */
    private static function environment(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $config = '/tmp/compat-fs/vcs-gitconfig-php';
        file_put_contents($config, "[http]\n\tsslVerify = false\n[advice]\n\tdetachedHead = false\n");
        putenv('GIT_CONFIG_GLOBAL=' . $config);
        putenv('GIT_CONFIG_NOSYSTEM=1');
        putenv('GIT_TERMINAL_PROMPT=0');
        putenv('GIT_AUTHOR_DATE=1767225600 +0000');
        putenv('GIT_COMMITTER_DATE=1767225600 +0000');
        $done = true;
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function adapter(array $a, Session $s): Git
    {
        self::environment();
        $cache = match ($a['cache'] ?? 'memory') {
            'none' => new Cache(new None()),
            'redis' => new Cache(new Redis(self::redis($s))),
            default => new Cache(new Memory()),
        };
        foreach ($a['seed'] ?? [] as $key => $value) {
            $cache->save((string) $key, $value);
        }
        $endpoint = self::in($a['endpoint'] ?? '', $s);

        return match ($a['adapter']) {
            'github' => $endpoint === '' ? new GitHub($cache) : new FixtureGitHub($cache, $endpoint),
            'gitlab' => new GitLab($cache),
            'gitea' => new Gitea($cache),
            'forgejo' => new Forgejo($cache),
            'gogs' => new Gogs($cache),
            'bitbucket' => isset($a['web']) ? new FixtureBitbucket($cache, self::in($a['web'], $s)) : new Bitbucket($cache),
            'origin' => $endpoint === '' ? new Origin($cache) : new FixtureOrigin($cache, $endpoint, self::in($a['web'] ?? '', $s), self::in($a['git'] ?? '', $s)),
            default => throw new Fault('unknown adapter ' . $a['adapter']),
        };
    }

    private static function redis(Session $s): \Redis
    {
        $url = parse_url($s->service('cache-redis-shared'));
        $redis = new \Redis();
        $redis->connect($url['host'] ?? 'redis', $url['port'] ?? 6379);
        $redis->select((int) ltrim($url['path'] ?? '', '/'));

        return $redis;
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function invoke(string $method, array $a, Session $s): mixed
    {
        self::environment();
        // A provider's name instead of a handle: a fresh adapter of it, for
        // fuzzing what needs no provider (payloads, signatures, URLs)
        $adapter = \is_string($a['adapter'] ?? null) ? self::adapter(['adapter' => $a['adapter'], 'cache' => 'none'], $s) : $s->get($a['adapter'] ?? null);
        unset($a['adapter']);
        $args = self::in($a, $s);
        // A payload given as JSON rather than as its text: its json_encode()
        if (isset($args['payload']) && !\is_string($args['payload'])) {
            $args['payload'] = (string) json_encode($args['payload']);
        }

        return self::call(fn () => $adapter->{$method}(...$args), $s);
    }

    /**
     * A result in the form both runtimes report: a command as its shell
     * line, an exception as `$error` with its code.
     */
    private static function call(callable $call, Session $s): mixed
    {
        try {
            $result = $call();
            if ($result instanceof Command) {
                $result = (string) $result;
            }

            return self::back($result, $s);
        } catch (Fault $fault) {
            throw $fault;
        } catch (\Throwable $error) {
            return self::back(['$error' => ['class' => $error::class, 'message' => $error->getMessage(), 'code' => $error->getCode()]], $s);
        }
    }

    /**
     * Arguments with the fixture server's base for this runtime.
     */
    private static function in(mixed $value, Session $s): mixed
    {
        if (\is_string($value)) {
            $tls = $s->service('vcs-https');
            $http = $s->service('vcs-http');

            return str_replace(
                ['${vcs-tls-host}', '${vcs-host}', '${vcs-tls}', '${vcs}'],
                [self::host($tls), self::host($http), $tls, $http],
                $value,
            );
        }
        if (\is_array($value)) {
            return array_map(fn (mixed $v): mixed => self::in($v, $s), $value);
        }

        return $value;
    }

    /** A base without its scheme, as it follows the credentials in a clone URL. */
    private static function host(string $base): string
    {
        return substr($base, (int) strpos($base, '://') + 3);
    }

    /**
     * Results with the fixture server's base for this runtime as the
     * placeholder again (also JSON-escaped, inside a recorded body).
     */
    private static function back(mixed $value, Session $s): mixed
    {
        if (\is_string($value)) {
            $tls = $s->service('vcs-https');
            $http = $s->service('vcs-http');

            return str_replace(
                [$tls, str_replace('/', '\/', $tls), $http, str_replace('/', '\/', $http), self::host($tls), self::host($http)],
                ['${vcs-tls}', '${vcs-tls}', '${vcs}', '${vcs}', '${vcs-tls-host}', '${vcs-host}'],
                $value,
            );
        }
        if (\is_array($value)) {
            $out = [];
            foreach ($value as $key => $v) {
                $out[\is_string($key) ? self::back($key, $s) : $key] = self::back($v, $s);
            }

            return $out;
        }

        return $value;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private static function control(Session $s, string $action, ?array $body = null): mixed
    {
        $url = $s->service('vcs-http') . '/_fixture/' . $s->ns . '/' . $action;
        $context = stream_context_create(['http' => [
            'method' => $body === null ? 'GET' : 'POST',
            'header' => 'Content-Type: application/json',
            'content' => $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES),
            'ignore_errors' => true,
        ]]);
        $response = file_get_contents($url, false, $context);
        if ($response === false) {
            throw new Fault('the vcs-http fixture server is not running (tests/compat/vcs/services.sh up)');
        }

        return json_decode($response, true);
    }

    /**
     * @param array<string> $args
     */
    private static function git(array $args): int
    {
        self::environment();
        $stdout = $stderr = '';

        return Console::execute(array_merge(['git'], $args), '', $stdout, $stderr);
    }

    /**
     * CloneCommandTest's remote: a bare repository of $files committed on
     * main, cloned from a work tree that later tags push from.
     *
     * @param array<string, mixed> $a
     */
    private static function cloneSetup(array $a): int
    {
        $workspace = (string) $a['workspace'];
        $source = $workspace . '/source';
        foreach ($a['files'] as $path => $content) {
            $file = $source . '/' . $path;
            if (!is_dir(\dirname($file))) {
                mkdir(\dirname($file), 0777, true);
            }
            file_put_contents($file, $content);
        }
        self::git(['init', '-q', '-b', 'main', $source]);
        self::git(['-C', $source, 'add', '.']);
        self::git(['-C', $source, '-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '-q', '-m', 'Initial']);

        return self::git(['clone', '-q', '--bare', $source, $workspace . '/remote.git']);
    }

    /**
     * CloneCommandTest::tag(): a commit setting README.md, tagged and pushed.
     *
     * @param array<string, mixed> $a
     */
    private static function cloneTag(array $a): int
    {
        $workspace = (string) $a['workspace'];
        $work = $workspace . '/work';
        if (!is_dir($work . '/.git')) {
            self::git(['clone', '-q', $workspace . '/remote.git', $work]);
        }
        file_put_contents($work . '/README.md', (string) $a['content']);
        self::git(['-C', $work, 'add', 'README.md']);
        self::git(['-C', $work, '-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '-q', '-m', (string) $a['name']]);
        $tag = ['-C', $work, '-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'tag'];
        if (!empty($a['annotated'])) {
            $tag = array_merge($tag, ['-a', '-m', (string) $a['name']]);
        }

        return self::git(array_merge($tag, [(string) $a['name']])) + self::git(['-C', $work, 'push', '-q', 'origin', 'refs/tags/' . $a['name']]);
    }

    /**
     * Runs a command line in $workspace and reports its exit code, the
     * files it left in $directory (path => content, `.git` left out) and
     * the `fatal:` lines the clone scripts print.
     *
     * @return array<string, mixed>
     */
    private static function run(string $command, string $directory, ?string $workspace): array
    {
        self::environment();
        $cwd = getcwd();
        if ($workspace !== null) {
            if (!is_dir($workspace)) {
                mkdir($workspace, 0777, true);
            }
            chdir($workspace);
        }
        $stdout = $stderr = '';
        $exit = Console::execute($command, '', $stdout, $stderr);
        if ($cwd !== false) {
            chdir($cwd);
        }
        $files = [];
        if (is_dir($directory)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $relative = substr((string) $file, \strlen($directory) + 1);
                if (str_starts_with($relative, '.git/') || !$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $files[$relative] = (string) file_get_contents((string) $file);
            }
        }
        ksort($files);
        $fatal = array_values(array_filter(explode("\n", $stderr), static fn (string $line): bool => str_starts_with($line, 'fatal: no tag matching')));
        $pwned = $workspace !== null && (file_exists($workspace . '/pwned') || file_exists($directory . '/pwned'));

        return ['exit' => $exit, 'files' => $files === [] ? new \stdClass() : $files, 'fatal' => $fatal, 'pwned' => $pwned];
    }
}
