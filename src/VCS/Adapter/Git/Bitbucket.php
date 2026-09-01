<?php

namespace Utopia\VCS\Adapter\Git;

use Exception;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Exception\FileNotFound;
use Utopia\VCS\Exception\RepositoryNotFound;

class Bitbucket extends Git
{
    public const CONTENTS_FILE = 'file';

    public const CONTENTS_DIRECTORY = 'dir';

    /**
     * Largest page size Bitbucket accepts.
     */
    private const int PAGE_SIZE = 100;

    /**
     * Depth used to walk a repository tree recursively. Bitbucket has no
     * "unlimited" value for max_depth, so this stands in for one.
     */
    private const int MAX_TREE_DEPTH = 100;

    /**
     * Maps the state vocabulary shared by the other adapters (GitHub's) onto
     * Bitbucket's build states.
     */
    private const array COMMIT_STATE_MAP = [
        'pending' => 'INPROGRESS',
        'in_progress' => 'INPROGRESS',
        'success' => 'SUCCESSFUL',
        'failure' => 'FAILED',
        'error' => 'FAILED',
        'cancelled' => 'STOPPED',
    ];

    /**
     * Bitbucket holds four states where a check run has seven verdicts, so a
     * run read back reports its state's verdict, not the one it was written with.
     */
    private const array CHECK_RUN_CONCLUSION_MAP = [
        'success' => 'SUCCESSFUL',
        'failure' => 'FAILED',
        'timed_out' => 'FAILED',
        'action_required' => 'FAILED',
        'cancelled' => 'STOPPED',
        'neutral' => 'STOPPED',
        'skipped' => 'STOPPED',
    ];

    /**
     * @var array<string, array{status: string, conclusion: string|null}>
     */
    private const array CHECK_RUN_STATE_MAP = [
        'INPROGRESS' => ['status' => 'in_progress', 'conclusion' => null],
        'SUCCESSFUL' => ['status' => 'completed', 'conclusion' => 'success'],
        'FAILED' => ['status' => 'completed', 'conclusion' => 'failure'],
        'STOPPED' => ['status' => 'completed', 'conclusion' => 'cancelled'],
    ];

    /**
     * Reverse of COMMIT_STATE_MAP, so getCommitStatuses() reports states in the
     * same vocabulary updateCommitStatus() accepts.
     */
    private const array COMMIT_STATE_MAP_REVERSE = [
        'INPROGRESS' => 'pending',
        'SUCCESSFUL' => 'success',
        'FAILED' => 'failure',
        'STOPPED' => 'cancelled',
    ];

    /**
     * Maps Bitbucket's event keys to the pull request verbs consumers key off
     * of; both a merge and a decline count as 'closed'.
     */
    private const array PULL_REQUEST_ACTION_MAP = [
        'pullrequest:created' => 'opened',
        'pullrequest:updated' => 'synchronize',
        'pullrequest:fulfilled' => 'closed',
        'pullrequest:rejected' => 'closed',
    ];

    protected string $endpoint = 'https://api.bitbucket.org/2.0';

    /**
     * Browser-facing host; the API lives on a separate host.
     */
    protected string $bitbucketUrl = 'https://bitbucket.org';

    protected string $accessToken;

    /** @var array<string, mixed>|null */
    private ?array $authenticatedUser = null;

    /**
     * Global Headers
     *
     * @var array<string, string>
     */
    protected $headers = ['content-type' => 'application/json'];

    public function __construct(protected Cache $cache) {}

    /**
     * Moves only the API host; the browser-facing host stays on bitbucket.org.
     */
    public function setEndpoint(string $endpoint): void
    {
        $this->endpoint = rtrim($endpoint, '/');
    }

    public function getName(): string
    {
        return 'bitbucket';
    }

    public function getEventHeaderName(): string
    {
        return 'x-event-key';
    }

    public function getSignatureHeaderName(): string
    {
        return 'x-hub-signature';
    }

    public function getSupportedWebhookScopes(): array
    {
        return [self::WEBHOOK_SCOPE_REPOSITORY];
    }

    public function getRepositoryUrl(string $owner, string $repositoryName): string
    {
        return "{$this->bitbucketUrl}/{$owner}/{$repositoryName}";
    }

    public function getBranchUrl(string $owner, string $repositoryName, string $branch): string
    {
        return $this->getRepositoryUrl($owner, $repositoryName) . "/branch/{$branch}";
    }

    public function getCommitUrl(string $owner, string $repositoryName, string $commitHash): string
    {
        return $this->getRepositoryUrl($owner, $repositoryName) . "/commits/{$commitHash}";
    }

    public function getFileUrl(string $owner, string $repositoryName, string $reference): string
    {
        return $this->getRepositoryUrl($owner, $repositoryName) . "/src/{$reference}";
    }

    /**
     * Bitbucket has no app installation flow; it authenticates with the
     * credential passed as $accessToken, which is either a bearer token (OAuth
     * 2.0, workspace or repository) or an Atlassian account API token given as
     * "email:token". $installationId, $privateKey and $appId are unused.
     */
    public function initializeVariables(string $installationId, string $privateKey, ?string $appId = null, ?string $accessToken = null, ?string $refreshToken = null): void
    {
        if (!\in_array($accessToken, [null, '', '0'], true)) {
            $this->accessToken = $accessToken;

            return;
        }

        throw new Exception('accessToken is required for this adapter.');
    }

    /**
     * Not applicable for this adapter - OAuth2 tokens are passed directly.
     */
    protected function generateAccessToken(string $privateKey, string $appId): void {}

    /**
     * Bitbucket reads the ref as one path segment, so a nested name like
     * feature/foo is taken as the ref `feature` and the path `foo` -- encoding
     * the slash doesn't separate them. Only the hash it points at survives.
     */
    private function resolveRef(string $owner, string $repositoryName, string $ref): string
    {
        if (!str_contains($ref, '/')) {
            return $ref;
        }

        $response = $this->call(
            self::METHOD_GET,
            "/repositories/{$owner}/{$repositoryName}/refs/branches/{$ref}",
            ['Authorization' => $this->authorizationHeader()],
        );

        $branch = $response['body'] ?? [];
        $hash = \is_array($branch) ? ($branch['target']['hash'] ?? '') : '';

        return empty($hash) ? $ref : (string) $hash;
    }

    /**
     * Name of the repository's main branch, or '' when it has none yet (an
     * empty repository names its main branch only once the root commit lands).
     */
    private function mainBranchName(string $owner, string $repositoryName): string
    {
        $mainbranch = $this->getRepository($owner, $repositoryName)['mainbranch'] ?? [];

        return (string) ($mainbranch['name'] ?? '');
    }

    /**
     * Slug of the workspace holding a repository, read off `workspace.slug` or
     * the first segment of `full_name` when the workspace object is absent.
     *
     * @param array<mixed> $repository
     */
    private function workspaceSlugOf(array $repository): string
    {
        $slug = (string) ($repository['workspace']['slug'] ?? '');
        if ($slug !== '' && $slug !== '0') {
            return $slug;
        }

        $fullName = (string) ($repository['full_name'] ?? '');

        return str_contains($fullName, '/') ? explode('/', $fullName)[0] : '';
    }

    /**
     * Repository responses carry Bitbucket's own field names; surface the keys
     * the other adapters report under so consumers can treat them alike.
     *
     * Bitbucket has no numeric repository ids; "workspace/slug" is the
     * identifier its API routes on, so that is what `id` carries and what
     * getRepositoryName() and event payloads report back.
     *
     * @param array<mixed> $repository
     * @return array<mixed>
     */
    private function normalizeRepository(array $repository): array
    {
        $repository['id'] = (string) ($repository['full_name'] ?? '');
        $repository['private'] = ($repository['is_private'] ?? false) === true;
        $repository['pushed_at'] = $repository['updated_on'] ?? '';

        if (empty($repository['workspace']['slug'])) {
            $repository['workspace'] = ['slug' => $this->workspaceSlugOf($repository)];
        }

        return $repository;
    }

    public function createRepository(string $owner, string $repositoryName, bool $private): array
    {
        $url = "/repositories/{$owner}/{$repositoryName}";

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => $this->authorizationHeader()], [
            'scm' => 'git',
            'name' => $repositoryName,
            'is_private' => $private,
        ]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            $error = $response['body']['error']['message'] ?? '';
            throw new Exception(
                "Creating repository {$repositoryName} failed with status code {$statusCode}" . ($error !== '' ? ": {$error}" : ''),
                $statusCode,
            );
        }

        $responseBody = $response['body'] ?? [];

        return $this->normalizeRepository(\is_array($responseBody) ? $responseBody : []);
    }

    public function deleteRepository(string $owner, string $repositoryName): bool
    {
        $url = "/repositories/{$owner}/{$repositoryName}";

        $response = $this->call(self::METHOD_DELETE, $url, ['Authorization' => $this->authorizationHeader()]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Deleting repository {$repositoryName} failed with status code {$statusCode}", $statusCode);
        }

        return true;
    }

    public function getRepository(string $owner, string $repositoryName): array
    {
        $url = "/repositories/{$owner}/{$repositoryName}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => $this->authorizationHeader()]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new RepositoryNotFound('Repository not found');
        }

        $responseBody = $response['body'] ?? [];

        return $this->normalizeRepository(\is_array($responseBody) ? $responseBody : []);
    }

    public function getRepositoryName(string $repositoryId): string
    {
        // $repositoryId is the "workspace/slug" id normalizeRepository() mints.
        // It travels as one path segment, so its slash arrives encoded.
        $repositoryId = rawurldecode($repositoryId);

        if (!str_contains($repositoryId, '/')) {
            throw new RepositoryNotFound("Repository {$repositoryId} not found");
        }

        [$workspace, $slug] = explode('/', $repositoryId, 2);

        // `slug` is the segment the API routes on; `name` is a display name
        // that can differ from it, as GitLab's `path` does from its `name`
        return (string) ($this->getRepository($workspace, $slug)['slug'] ?? $slug);
    }

    public function hasAccessToAllRepositories(): bool
    {
        return true;
    }

    public function getInstallationRepository(string $repositoryName): array
    {
        throw new Exception('getInstallationRepository is not applicable for this adapter');
    }

    public function searchRepositories(string $owner, int $page, int $per_page, string $search = ''): array
    {
        $url = "/repositories/{$owner}?page={$page}&pagelen={$per_page}";

        if ($search !== '' && $search !== '0') {
            // Bitbucket's filter grammar quotes string literals, so escape the
            // characters that would otherwise break out of the quoted value.
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $search);
            $url .= '&q=' . urlencode("name~\"{$escaped}\"");
        }

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => $this->authorizationHeader()]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            return ['items' => [], 'total' => 0];
        }

        $responseBody = $response['body'] ?? [];
        if (!\is_array($responseBody)) {
            return ['items' => [], 'total' => 0];
        }

        $repositories = [];
        foreach (($responseBody['values'] ?? []) as $repository) {
            $repository = $this->normalizeRepository(\is_array($repository) ? $repository : []);
            $repositories[] = [
                'id' => $repository['id'],
                'name' => $repository['name'] ?? '',
                'description' => $repository['description'] ?? '',
                'private' => $repository['private'],
                'pushed_at' => $repository['pushed_at'],
            ];
        }

        // `size` is optional on Bitbucket's paginated responses, so fall back to
        // what this page actually carried.
        return [
            'items' => $repositories,
            'total' => (int) ($responseBody['size'] ?? \count($repositories)),
        ];
    }

    /**
     * A workspace carries no personal-vs-team flag and nothing on the account
     * identifies which one is personal, so every workspace reports as a group.
     *
     * @return array{items: array<array<string, mixed>>, total: int}
     */
    public function listNamespaces(int $page, int $per_page, string $search = ''): array
    {
        $url = "/user/workspaces?page={$page}&pagelen={$per_page}";

        if ($search !== '' && $search !== '0') {
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $search);
            $url .= '&q=' . urlencode("slug~\"{$escaped}\"");
        }

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => $this->authorizationHeader()]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to list namespaces: HTTP {$statusCode}", $statusCode);
        }

        $responseBody = $response['body'] ?? [];
        if (!\is_array($responseBody)) {
            return ['items' => [], 'total' => 0];
        }

        $namespaces = [];
        foreach (($responseBody['values'] ?? []) as $entry) {
            $workspace = \is_array($entry) && \is_array($entry['workspace'] ?? null)
                ? $entry['workspace']
                : [];

            $slug = (string) ($workspace['slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            $uuid = (string) ($workspace['uuid'] ?? '');

            $namespaces[] = [
                'id' => $uuid !== '' ? $uuid : $slug,
                'name' => (string) ($workspace['name'] ?? $slug),
                'path' => $slug,
                'kind' => 'group',
                'avatarUrl' => (string) ($workspace['links']['avatar']['href'] ?? ''),
            ];
        }

        return [
            'items' => $namespaces,
            'total' => (int) ($responseBody['size'] ?? \count($namespaces)),
        ];
    }

    public function getRepositoryTree(string $owner, string $repositoryName, string $branch, bool $recursive = false): array
    {
        $suffix = $recursive ? '&max_depth=' . self::MAX_TREE_DEPTH : '';

        $items = $this->listSource($owner, $repositoryName, '', $branch, $suffix);

        return array_column($items, 'path');
    }

    public function listRepositoryContents(string $owner, string $repositoryName, string $path = '', string $ref = ''): array
    {
        $items = $this->listSource($owner, $repositoryName, $path, $ref);

        $contents = [];
        foreach ($items as $item) {
            $itemPath = (string) ($item['path'] ?? '');
            $contents[] = [
                'name' => basename($itemPath),
                'size' => $item['size'] ?? 0,
                'type' => ($item['type'] ?? '') === 'commit_directory' ? self::CONTENTS_DIRECTORY : self::CONTENTS_FILE,
            ];
        }

        return $contents;
    }

    /**
     * URL of a path in the repository's source tree. Bitbucket's source
     * endpoints always want an explicit ref, so an empty one falls back to the
     * main branch; throws when the repository has none yet.
     */
    private function sourceUrl(string $owner, string $repositoryName, string $path, string $ref): string
    {
        if ($ref === '' || $ref === '0') {
            $ref = $this->mainBranchName($owner, $repositoryName);

            if ($ref === '' || $ref === '0') {
                throw new Exception("Unable to resolve the main branch of {$owner}/{$repositoryName}.");
            }
        }

        $ref = $this->resolveRef($owner, $repositoryName, $ref);

        // Encode each path segment but keep the separators between them
        $path = implode('/', array_map(rawurlencode(...), explode('/', $this->normalizeRepositoryPath($path))));

        return "/repositories/{$owner}/{$repositoryName}/src/" . rawurlencode($ref) . '/' . $path;
    }

    /**
     * List entries of a directory in the repository, following pagination.
     * Returns an empty list when the ref or path doesn't exist.
     *
     * @param string $suffix Extra query string to append, e.g. '&max_depth=100'
     * @return array<mixed>
     */
    private function listSource(string $owner, string $repositoryName, string $path, string $ref, string $suffix = ''): array
    {
        try {
            $base = $this->sourceUrl($owner, $repositoryName, $path, $ref);
        } catch (Exception) {
            return [];
        }

        $items = [];
        $url = $base . '?pagelen=' . self::PAGE_SIZE . $suffix;

        while ($url !== '') {
            $response = $this->call(self::METHOD_GET, $url, ['Authorization' => $this->authorizationHeader()]);

            $responseHeaders = $response['headers'] ?? [];
            $statusCode = $responseHeaders['status-code'] ?? 0;

            // A ref or path that isn't there is an empty listing rather than a
            // failure; anything else is reported instead of being read as one.
            if ($statusCode === 404) {
                return $items;
            }

            if ($statusCode >= 400) {
                throw new Exception("Listing {$owner}/{$repositoryName} failed with status code {$statusCode}", $statusCode);
            }

            $responseBody = $response['body'] ?? [];
            if (!\is_array($responseBody)) {
                break;
            }

            $values = $responseBody['values'] ?? [];
            if (!\is_array($values)) {
                break;
            }

            $items = array_merge($items, $values);

            // Source listings page by handing back the url of the next page,
            // which this endpoint expects to be followed as given.
            $next = (string) ($responseBody['next'] ?? '');
            $url = str_starts_with($next, $this->endpoint) ? substr($next, \strlen($this->endpoint)) : '';
        }

        return $items;
    }

    public function getRepositoryContent(string $owner, string $repositoryName, string $path, string $ref = ''): array
    {
        try {
            $url = $this->sourceUrl($owner, $repositoryName, $path, $ref);
        } catch (Exception $e) {
            throw new FileNotFound($e->getMessage(), $e->getCode(), $e);
        }

        // A missing file is the expected, common case here (not every repo
        // has e.g. package.json) -- call() throws on a non-JSON/empty body,
        // which a 404 can legitimately have, so that has to be caught here
        // too rather than left to propagate as an uncaught fatal.
        try {
            $metaResponse = $this->call(self::METHOD_GET, $url . '?format=meta', ['Authorization' => $this->authorizationHeader()]);
        } catch (Exception $e) {
            throw new FileNotFound($e->getMessage(), $e->getCode(), $e);
        }

        $metaHeaders = $metaResponse['headers'] ?? [];
        if (($metaHeaders['status-code'] ?? 0) !== 200) {
            throw new FileNotFound();
        }

        $meta = $metaResponse['body'] ?? [];
        if (!\is_array($meta) || ($meta['type'] ?? '') !== 'commit_file') {
            throw new FileNotFound();
        }

        // Bitbucket serves file contents raw, typed after the file extension, so
        // don't let the response be decoded as JSON.
        try {
            $contentResponse = $this->call(self::METHOD_GET, $url, ['Authorization' => $this->authorizationHeader()], [], false);
        } catch (Exception) {
            throw new FileNotFound();
        }

        $contentHeaders = $contentResponse['headers'] ?? [];
        if (($contentHeaders['status-code'] ?? 0) !== 200) {
            throw new FileNotFound();
        }

        $content = $contentResponse['body'] ?? '';
        $content = \is_string($content) ? $content : '';

        return [
            // Bitbucket exposes no blob id, so compute the one git stores --
            // the repository is git backed, so this is the same value the
            // other adapters read straight off their responses.
            'sha' => hash('sha1', 'blob ' . \strlen($content) . "\0" . $content),
            'size' => \strlen($content),
            'content' => $content,
        ];
    }

    /**
     * Bitbucket doesn't compute language statistics; a repository carries a
     * single, manually set `language` field, which this reports when present.
     */
    public function listRepositoryLanguages(string $owner, string $repositoryName): array
    {
        try {
            $repository = $this->getRepository($owner, $repositoryName);
        } catch (RepositoryNotFound) {
            return [];
        }

        $language = (string) ($repository['language'] ?? '');

        return $language === '' || $language === '0' ? [] : [$language];
    }

    public function createFile(string $owner, string $repositoryName, string $filepath, string $content, string $message = 'Add file', string $branch = ''): array
    {
        if ($branch === '' || $branch === '0') {
            $branch = $this->mainBranchName($owner, $repositoryName);
        }

        $url = "/repositories/{$owner}/{$repositoryName}/src";

        // File paths are sent as form field names. A leading slash keeps a file
        // called e.g. 'message' from being read as commit metadata; Bitbucket
        // treats every path as absolute from the repository root either way.
        $payload = [
            'message' => $message,
            '/' . $this->normalizeRepositoryPath($filepath) => $content,
        ];

        // An empty repository has no branch to commit onto yet, and naming one
        // Bitbucket doesn't have is refused; omitting it lets Bitbucket create
        // its own default, which the caller reads back off the repository.
        if ($branch !== '' && $branch !== '0') {
            $payload['branch'] = $branch;
        }

        $response = $this->call(
            self::METHOD_POST,
            $url,
            [
                'Authorization' => $this->authorizationHeader(),
                'content-type' => 'application/x-www-form-urlencoded',
            ],
            $payload,
        );

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to create file {$filepath}: HTTP {$statusCode}", $statusCode);
        }

        // The response body is empty; the new commit is named by the Location header.
        $location = (string) ($responseHeaders['location'] ?? '');
        $commitHash = '';
        if (preg_match('#/commit/([0-9a-f]+)#', $location, $matches) === 1) {
            $commitHash = $matches[1];
        }

        return [
            'path' => $filepath,
            'branch' => $branch,
            'commitHash' => $commitHash,
        ];
    }

    public function createBranch(string $owner, string $repositoryName, string $newBranchName, string $oldBranchName): array
    {
        $url = "/repositories/{$owner}/{$repositoryName}/refs/branches";

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => $this->authorizationHeader()], [
            'name' => $newBranchName,
            'target' => ['hash' => $oldBranchName],
        ]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to create branch {$newBranchName}: HTTP {$statusCode}", $statusCode);
        }

        return $response['body'] ?? [];
    }

    public function listBranches(string $owner, string $repositoryName): array
    {
        return $this->listRefs($owner, $repositoryName, 'branches');
    }

    public function listTags(string $owner, string $repositoryName, string $search = ''): array
    {
        return $this->matchGlob($this->listRefs($owner, $repositoryName, 'tags'), $search);
    }

    /**
     * @param string $type 'branches' or 'tags'
     * @return array<string>
     */
    private function listRefs(string $owner, string $repositoryName, string $type): array
    {
        $names = [];
        $page = 1;
        do {
            $url = "/repositories/{$owner}/{$repositoryName}/refs/{$type}?pagelen=" . self::PAGE_SIZE . "&page={$page}";

            $response = $this->call(self::METHOD_GET, $url, ['Authorization' => $this->authorizationHeader()]);

            $responseHeaders = $response['headers'] ?? [];
            $statusCode = $responseHeaders['status-code'] ?? 0;
            if ($statusCode >= 400) {
                return $page === 1 ? [] : $names;
            }

            $responseBody = $response['body'] ?? [];
            if (!\is_array($responseBody)) {
                break;
            }

            $values = $responseBody['values'] ?? [];
            if (!\is_array($values)) {
                break;
            }

            foreach ($values as $ref) {
                $names[] = $ref['name'] ?? '';
            }

            $page++;
        } while (!empty($responseBody['next']));

        return $names;
    }

    public function createTag(string $owner, string $repositoryName, string $tagName, string $target, string $message = ''): array
    {
        $url = "/repositories/{$owner}/{$repositoryName}/refs/tags";

        $payload = [
            'name' => $tagName,
            'target' => ['hash' => $target],
        ];

        if ($message !== '' && $message !== '0') {
            $payload['message'] = $message;
        }

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => $this->authorizationHeader()], $payload);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to create tag {$tagName}: HTTP {$statusCode}", $statusCode);
        }

        return $response['body'] ?? [];
    }

    public function getCommit(string $owner, string $repositoryName, string $commitHash): array
    {
        $url = "/repositories/{$owner}/{$repositoryName}/commit/" . rawurlencode($commitHash);

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => $this->authorizationHeader()]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception('Commit not found or inaccessible');
        }

        $commit = $response['body'] ?? [];

        return $this->parseCommit(\is_array($commit) ? $commit : []);
    }

    public function getLatestCommit(string $owner, string $repositoryName, string $branch): array
    {
        $branch = $this->resolveRef($owner, $repositoryName, $branch);

        $url = "/repositories/{$owner}/{$repositoryName}/commits/" . rawurlencode($branch) . '?pagelen=1';

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => $this->authorizationHeader()]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to get latest commit: HTTP {$statusCode}", $statusCode);
        }

        $responseBody = $response['body'] ?? [];
        $values = \is_array($responseBody) ? ($responseBody['values'] ?? []) : [];

        if (empty($values[0])) {
            throw new Exception('Latest commit response is missing required information.');
        }

        return $this->parseCommit($values[0]);
    }

    /**
     * Bitbucket only names an unlinked author in a raw "Name <email>" string;
     * an author linked to an account is named by the account instead.
     *
     * @param array<mixed> $author
     */
    private function authorNameOf(array $author): string
    {
        $user = \is_array($author['user'] ?? null) ? $author['user'] : [];
        $name = $user['display_name'] ?? '';
        if (!empty($name)) {
            return (string) $name;
        }

        return trim(preg_replace('/<[^>]*>/', '', (string) ($author['raw'] ?? '')) ?? '');
    }

    /**
     * Normalize a Bitbucket commit into the shape every adapter reports.
     *
     * @param array<mixed> $commit
     * @return array<mixed>
     */
    private function parseCommit(array $commit): array
    {
        $author = $commit['author'] ?? [];
        $author = \is_array($author) ? $author : [];
        $user = $author['user'] ?? [];
        $user = \is_array($user) ? $user : [];
        $userLinks = \is_array($user['links'] ?? null) ? $user['links'] : [];

        $name = $this->authorNameOf($author);

        return [
            'commitAuthor' => $name === '' || $name === '0' ? 'Unknown' : $name,
            'commitMessage' => $commit['message'] ?? 'No message',
            'commitHash' => $commit['hash'] ?? '',
            'commitUrl' => $commit['links']['html']['href'] ?? '',
            'commitAuthorAvatar' => $userLinks['avatar']['href'] ?? '',
            'commitAuthorUrl' => $userLinks['html']['href'] ?? '',
        ];
    }

    public function updateCommitStatus(string $repositoryName, string $commitHash, string $owner, string $state, string $description = '', string $target_url = '', string $context = ''): void
    {
        $url = "/repositories/{$owner}/{$repositoryName}/commit/" . rawurlencode($commitHash) . '/statuses/build';

        // Bitbucket identifies a status by its key and overwrites a status
        // posted under a key it already has, so the context doubles as the key.
        $key = $context === '' || $context === '0' ? $this->getName() : $context;

        $payload = [
            'key' => $key,
            'name' => $key,
            'state' => self::COMMIT_STATE_MAP[$state] ?? $state,
            // A build status without a URL is rejected, so point at the commit
            // itself when the caller has nowhere better to link.
            'url' => $target_url === '' || $target_url === '0' ? $this->getCommitUrl($owner, $repositoryName, $commitHash) : $target_url,
        ];

        if ($description !== '' && $description !== '0') {
            $payload['description'] = $description;
        }

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => $this->authorizationHeader()], $payload);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to update commit status: HTTP {$statusCode}", $statusCode);
        }
    }

    public function getCommitStatuses(string $owner, string $repositoryName, string $commitHash): array
    {
        $url = "/repositories/{$owner}/{$repositoryName}/commit/" . rawurlencode($commitHash) . '/statuses?pagelen=' . self::PAGE_SIZE;

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => $this->authorizationHeader()]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            return [];
        }

        $responseBody = $response['body'] ?? [];
        if (!\is_array($responseBody)) {
            return [];
        }

        $statuses = [];
        foreach (($responseBody['values'] ?? []) as $status) {
            $state = (string) ($status['state'] ?? '');
            $statuses[] = [
                'state' => self::COMMIT_STATE_MAP_REVERSE[$state] ?? $state,
                'description' => $status['description'] ?? '',
                'target_url' => $status['url'] ?? '',
                'context' => $status['key'] ?? '',
            ];
        }

        return $statuses;
    }

    public function createCheckRun(
        string $owner,
        string $repositoryName,
        string $headSha,
        string $name,
        string $status = 'queued',
        string $conclusion = '',
        string $title = '',
        string $summary = '',
        string $text = '',
        array $annotations = [],
        array $images = [],
        array $actions = [],
        string $detailsUrl = '',
        string $externalId = '',
        string $startedAt = '',
        string $completedAt = '',
    ): array {
        [$status, $conclusion, $completedAt] = $this->settleCheckRun($status, $conclusion, $completedAt);

        // Each run takes a key of its own, so two runs of one name on a commit
        // stay two statuses rather than one overwriting the other.
        $key = 'check-run-' . bin2hex(random_bytes(8));

        $written = $this->writeBuildStatus($owner, $repositoryName, $headSha, [
            'key' => $key,
            'name' => $name,
            'state' => $this->checkRunState($conclusion),
            'url' => $detailsUrl === '' || $detailsUrl === '0' ? $this->getCommitUrl($owner, $repositoryName, $headSha) : $detailsUrl,
            'description' => $summary,
        ]);

        return $this->parseCheckRun($written, $owner, $repositoryName, $headSha, [
            'status' => $status,
            'conclusion' => $conclusion === '' ? null : $conclusion,
            'output' => ['title' => $title, 'summary' => $summary, 'text' => $text],
            'started_at' => $startedAt === '' || $startedAt === '0' ? ($written['created_on'] ?? null) : $startedAt,
            'completed_at' => empty($completedAt) ? null : $completedAt,
        ]);
    }

    public function getCheckRun(string $owner, string $repositoryName, string $checkRunId): array
    {
        [$commitHash, $key] = $this->splitCheckRunId($checkRunId);

        $url = "/repositories/{$owner}/{$repositoryName}/commit/" . rawurlencode($commitHash) . '/statuses/build/' . rawurlencode($key);

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => $this->authorizationHeader()]);

        $statusCode = $response['headers']['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to get check run {$checkRunId}: HTTP {$statusCode}", $statusCode);
        }

        $body = $response['body'] ?? [];

        return $this->parseCheckRun(\is_array($body) ? $body : [], $owner, $repositoryName, $commitHash);
    }

    public function updateCheckRun(
        string $owner,
        string $repositoryName,
        string $checkRunId,
        string $name = '',
        string $status = '',
        string $conclusion = '',
        string $title = '',
        string $summary = '',
        string $text = '',
        array $annotations = [],
        array $images = [],
        array $actions = [],
        string $detailsUrl = '',
        string $externalId = '',
        string $startedAt = '',
        string $completedAt = '',
    ): array {
        [$commitHash, $key] = $this->splitCheckRunId($checkRunId);
        [$status, $conclusion, $completedAt] = $this->settleCheckRun($status, $conclusion, $completedAt);

        // A repost rewrites every field, not the named ones, so what the caller
        // left out is read off the run and written back unchanged.
        $current = $this->getCheckRun($owner, $repositoryName, $checkRunId);

        $written = $this->writeBuildStatus($owner, $repositoryName, $commitHash, [
            'key' => $key,
            'name' => $name === '' || $name === '0' ? $current['name'] : $name,
            'state' => $this->checkRunState($conclusion),
            'url' => $detailsUrl === '' || $detailsUrl === '0' ? $current['html_url'] : $detailsUrl,
            'description' => $summary === '' || $summary === '0' ? ($current['output']['summary'] ?? '') : $summary,
        ]);

        return $this->parseCheckRun($written, $owner, $repositoryName, $commitHash, [
            'status' => empty($status) ? $current['status'] : $status,
            'conclusion' => $conclusion === '' ? $current['conclusion'] : $conclusion,
            'output' => ['title' => $title, 'summary' => $summary, 'text' => $text],
            'completed_at' => empty($completedAt) ? $current['completed_at'] : $completedAt,
        ]);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function settleCheckRun(string $status, string $conclusion, string $completedAt): array
    {
        if ($status === 'completed' && ($conclusion === '' || $conclusion === '0')) {
            throw new Exception("conclusion is required when status is 'completed'");
        }

        if ($conclusion !== '' && $conclusion !== '0') {
            $status = 'completed';

            if ($completedAt === '' || $completedAt === '0') {
                $completedAt = gmdate('Y-m-d\TH:i:s\Z');
            }
        }

        return [$status, $conclusion, $completedAt];
    }

    private function checkRunState(string $conclusion): string
    {
        if ($conclusion === '' || $conclusion === '0') {
            return 'INPROGRESS';
        }

        return self::CHECK_RUN_CONCLUSION_MAP[$conclusion] ?? 'FAILED';
    }

    /**
     * A run is addressed by its id alone, but a status lives under a commit,
     * so the id carries that commit alongside the key.
     *
     * @return array{0: string, 1: string}
     */
    private function splitCheckRunId(string $checkRunId): array
    {
        $parts = explode(':', $checkRunId, 2);

        if (\count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new Exception("Check run {$checkRunId} was not found", 404);
        }

        return $parts;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function writeBuildStatus(string $owner, string $repositoryName, string $commitHash, array $payload): array
    {
        $url = "/repositories/{$owner}/{$repositoryName}/commit/" . rawurlencode($commitHash) . '/statuses/build';

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => $this->authorizationHeader()], array_filter($payload, fn($value): bool => $value !== ''));

        $statusCode = $response['headers']['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to write check run: HTTP {$statusCode}", $statusCode);
        }

        $body = $response['body'] ?? [];

        return \is_array($body) ? $body : [];
    }

    /**
     * @param array<string, mixed> $status
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function parseCheckRun(array $status, string $owner, string $repositoryName, string $commitHash, array $overrides = []): array
    {
        $state = (string) ($status['state'] ?? '');
        $settled = self::CHECK_RUN_STATE_MAP[$state] ?? ['status' => 'completed', 'conclusion' => null];
        $commitUrl = $this->getCommitUrl($owner, $repositoryName, $commitHash);

        return array_merge([
            'id' => $commitHash . ':' . ($status['key'] ?? ''),
            'name' => (string) ($status['name'] ?? ''),
            'status' => $settled['status'],
            'conclusion' => $settled['conclusion'],
            'head_sha' => $commitHash,
            'url' => (string) ($status['links']['self']['href'] ?? $commitUrl),
            'html_url' => (string) ($status['url'] ?? $commitUrl),
            'started_at' => (string) ($status['created_on'] ?? ''),
            'completed_at' => $settled['conclusion'] === null ? null : (string) ($status['updated_on'] ?? ''),
            'output' => ['title' => '', 'summary' => (string) ($status['description'] ?? ''), 'text' => ''],
        ], $overrides);
    }

    public function createPullRequest(string $owner, string $repositoryName, string $title, string $head, string $base, string $body = ''): array
    {
        $url = "/repositories/{$owner}/{$repositoryName}/pullrequests";

        $payload = [
            'title' => $title,
            'source' => ['branch' => ['name' => $head]],
            'destination' => ['branch' => ['name' => $base]],
        ];

        if ($body !== '' && $body !== '0') {
            $payload['description'] = $body;
        }

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => $this->authorizationHeader()], $payload);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to create pull request: HTTP {$statusCode}", $statusCode);
        }

        $responseBody = $response['body'] ?? [];
        $responseBody = \is_array($responseBody) ? $responseBody : [];

        // Bitbucket calls it `id`; report it under `number` too, as the other
        // adapters do.
        $responseBody['number'] = $responseBody['id'] ?? 0;

        return $responseBody;
    }

    public function getPullRequest(string $owner, string $repositoryName, int $pullRequestNumber): array
    {
        $url = "/repositories/{$owner}/{$repositoryName}/pullrequests/{$pullRequestNumber}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => $this->authorizationHeader()]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to get pull request: HTTP {$statusCode}", $statusCode);
        }

        $pullRequest = $response['body'] ?? [];

        return $this->parsePullRequest(\is_array($pullRequest) ? $pullRequest : []);
    }

    public function getPullRequestFromBranch(string $owner, string $repositoryName, string $branch): array
    {
        $query = urlencode("source.branch.name=\"{$branch}\"");
        $url = "/repositories/{$owner}/{$repositoryName}/pullrequests?state=OPEN&q={$query}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => $this->authorizationHeader()]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to list pull requests: HTTP {$statusCode}", $statusCode);
        }

        $responseBody = $response['body'] ?? [];
        $values = \is_array($responseBody) ? ($responseBody['values'] ?? []) : [];

        if (empty($values[0])) {
            return [];
        }

        return $this->parsePullRequest($values[0]);
    }

    /**
     * Normalize a Bitbucket pull request into the shape every adapter reports.
     *
     * @param array<mixed> $pullRequest
     * @return array<mixed>
     */
    private function parsePullRequest(array $pullRequest): array
    {
        $source = \is_array($pullRequest['source'] ?? null) ? $pullRequest['source'] : [];
        $destination = \is_array($pullRequest['destination'] ?? null) ? $pullRequest['destination'] : [];

        return [
            'number' => $pullRequest['id'] ?? 0,
            'title' => $pullRequest['title'] ?? '',
            // Bitbucket reports OPEN/MERGED/DECLINED/SUPERSEDED
            'state' => strtolower((string) ($pullRequest['state'] ?? '')),
            'head' => [
                'ref' => $source['branch']['name'] ?? '',
                'sha' => $source['commit']['hash'] ?? '',
            ],
            'base' => [
                'ref' => $destination['branch']['name'] ?? '',
            ],
        ];
    }

    public function getPullRequestFiles(string $owner, string $repositoryName, int $pullRequestNumber): array
    {
        $files = [];
        $page = 1;
        do {
            $url = "/repositories/{$owner}/{$repositoryName}/pullrequests/{$pullRequestNumber}/diffstat?pagelen=" . self::PAGE_SIZE . "&page={$page}";

            $response = $this->call(self::METHOD_GET, $url, ['Authorization' => $this->authorizationHeader()]);

            $responseHeaders = $response['headers'] ?? [];
            $statusCode = $responseHeaders['status-code'] ?? 0;
            if ($statusCode >= 400) {
                throw new Exception("Failed to get pull request files: HTTP {$statusCode}", $statusCode);
            }

            $responseBody = $response['body'] ?? [];
            if (!\is_array($responseBody)) {
                break;
            }

            $values = $responseBody['values'] ?? [];
            if (!\is_array($values)) {
                break;
            }

            foreach ($values as $diff) {
                $new = \is_array($diff['new'] ?? null) ? $diff['new'] : [];
                $old = \is_array($diff['old'] ?? null) ? $diff['old'] : [];

                $files[] = [
                    'filename' => $new['path'] ?? $old['path'] ?? '',
                ];
            }

            $page++;
        } while (!empty($responseBody['next']));

        return $files;
    }

    public function createComment(string $owner, string $repositoryName, int $pullRequestNumber, string $comment): string
    {
        $url = "/repositories/{$owner}/{$repositoryName}/pullrequests/{$pullRequestNumber}/comments";

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => $this->authorizationHeader()], [
            'content' => ['raw' => $comment],
        ]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to create comment: HTTP {$statusCode}", $statusCode);
        }

        $responseBody = $response['body'] ?? [];
        if (!\is_array($responseBody) || !\array_key_exists('id', $responseBody)) {
            throw new Exception('Comment creation response is missing comment ID.');
        }

        // Comment ids are only addressable through their pull request, so carry both.
        return $pullRequestNumber . ':' . $responseBody['id'];
    }

    public function getComment(string $owner, string $repositoryName, string $commentId): string
    {
        $parts = explode(':', $commentId, 2);
        if (\count($parts) !== 2) {
            return '';
        }

        [$pullRequestNumber, $id] = $parts;
        $url = "/repositories/{$owner}/{$repositoryName}/pullrequests/{$pullRequestNumber}/comments/{$id}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => $this->authorizationHeader()]);

        return $response['body']['content']['raw'] ?? '';
    }

    public function updateComment(string $owner, string $repositoryName, string $commentId, string $comment): string
    {
        $parts = explode(':', $commentId, 2);
        if (\count($parts) !== 2) {
            throw new Exception("Invalid comment ID format: {$commentId}");
        }

        [$pullRequestNumber, $id] = $parts;
        $url = "/repositories/{$owner}/{$repositoryName}/pullrequests/{$pullRequestNumber}/comments/{$id}";

        $response = $this->call(self::METHOD_PUT, $url, ['Authorization' => $this->authorizationHeader()], [
            'content' => ['raw' => $comment],
        ]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to update comment: HTTP {$statusCode}", $statusCode);
        }

        return $commentId;
    }

    /**
     * @param array<string> $events Event names, either this library's ('push',
     *                              'pull_request') or Bitbucket's own keys
     *                              (e.g. 'repo:push')
     */
    public function createWebhook(string $owner, string $repositoryName, string $url, string $secret, array $events = ['push', 'pull_request']): string
    {
        $apiUrl = "/repositories/{$owner}/{$repositoryName}/hooks";

        $payload = [
            'description' => 'utopia',
            'url' => $url,
            'active' => true,
            'events' => $this->mapWebhookEvents($events),
        ];

        if ($secret !== '' && $secret !== '0') {
            $payload['secret'] = $secret;
        }

        $response = $this->call(self::METHOD_POST, $apiUrl, ['Authorization' => $this->authorizationHeader()], $payload);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to create webhook: HTTP {$statusCode} - " . json_encode($response['body'] ?? []), $statusCode);
        }

        $uuid = $response['body']['uuid'] ?? null;
        if ($uuid === null || $uuid === '') {
            throw new Exception('Webhook created but response did not include a uuid');
        }

        return (string) $uuid;
    }

    /**
     * Translate this library's event names into Bitbucket's event keys. A pull
     * request maps to several of them, since Bitbucket splits open, update,
     * merge and decline into separate events.
     *
     * @param array<string> $events
     * @return array<string>
     */
    private function mapWebhookEvents(array $events): array
    {
        $keys = [];
        foreach ($events as $event) {
            // Native Bitbucket keys pass through untouched
            if (str_contains($event, ':')) {
                $keys[] = $event;
                continue;
            }

            $keys = match ($event) {
                'push' => array_merge($keys, ['repo:push']),
                'pull_request' => array_merge($keys, array_keys(self::PULL_REQUEST_ACTION_MAP)),
                default => $keys,
            };
        }

        return array_values(array_unique($keys));
    }

    public function getUser(string $username): array
    {
        // Bitbucket looks accounts up by UUID or Atlassian account id; only some
        // accounts still resolve by name.
        $url = '/users/' . rawurlencode($username);

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => $this->authorizationHeader()]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to get user: HTTP {$statusCode}", $statusCode);
        }

        $responseBody = $response['body'] ?? [];
        if (!\is_array($responseBody) || empty($responseBody['uuid'])) {
            throw new Exception("User not found: {$username}");
        }

        // Bitbucket has no numeric user ids, and reports the handle as
        // `nickname`; surface both under the shared keys.
        $responseBody['id'] = $responseBody['uuid'];
        $responseBody['username'] ??= $responseBody['nickname'] ?? '';

        return $responseBody;
    }

    /**
     * Account the access token belongs to.
     *
     * @return array<mixed>
     */
    protected function getAuthenticatedUser(): array
    {
        if ($this->authenticatedUser !== null) {
            return $this->authenticatedUser;
        }

        $response = $this->call(self::METHOD_GET, '/user', ['Authorization' => $this->authorizationHeader()]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to get current user: HTTP {$statusCode}", $statusCode);
        }

        $responseBody = $response['body'] ?? [];

        return $this->authenticatedUser = \is_array($responseBody) ? $responseBody : [];
    }

    /**
     * $installationId and $repositoryId are unused (Bitbucket has neither
     * installations nor numeric repository ids): the owner is the token
     * account's workspace, resolved via /user/workspaces because `/user`'s
     * username/nickname are display handles, not workspace identifiers.
     */
    public function getOwnerName(string $installationId, ?int $repositoryId = null): string
    {
        $response = $this->call(self::METHOD_GET, '/user/workspaces', ['Authorization' => $this->authorizationHeader()]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode < 400) {
            $responseBody = $response['body'] ?? [];
            $values = \is_array($responseBody) ? ($responseBody['values'] ?? []) : [];

            // Each entry is a workspace_access object that carries the
            // workspace under its own key rather than at the top level
            $slug = $values[0]['workspace']['slug'] ?? '';
            if (!empty($slug)) {
                return (string) $slug;
            }
        }

        $user = $this->getAuthenticatedUser();

        return (string) ($user['username'] ?? ($user['nickname'] ?? ''));
    }

    /**
     * Authorization header for the credential this adapter was given.
     *
     * An Atlassian account API token authenticates as "email:token" over HTTP
     * Basic; an OAuth 2.0 or workspace token is a Bearer credential. The colon
     * an email:token pair always carries, and a token alone never does, tells
     * the two apart.
     */
    private function authorizationHeader(): string
    {
        if (str_contains($this->accessToken, ':')) {
            return 'Basic ' . base64_encode($this->accessToken);
        }

        return 'Bearer ' . $this->accessToken;
    }

    /**
     * $bitbucketUrl with the credential embedded as HTTP Basic userinfo, which
     * an email:token pair already is; a bare token needs the x-token-auth
     * username Bitbucket pairs it with.
     */
    private function authenticatedBitbucketUrl(): string
    {
        if (!isset($this->accessToken) || ($this->accessToken === '' || $this->accessToken === '0')) {
            return $this->bitbucketUrl;
        }

        $userinfo = str_contains($this->accessToken, ':')
            ? implode(':', array_map(urlencode(...), explode(':', $this->accessToken, 2)))
            : 'x-token-auth:' . urlencode($this->accessToken);

        return str_replace('://', '://' . $userinfo . '@', $this->bitbucketUrl);
    }

    /**
     * The archive host answers a token in the url with a redirect to a login
     * page, so the credential travels as a header instead.
     *
     * @return array<string, string>
     */
    #[\Override]
    public function getRepositoryPresignedUrlHeaders(): array
    {
        return ['Authorization' => $this->authorizationHeader()];
    }

    /**
     * @link https://support.atlassian.com/bitbucket-cloud/kb/how-to-download-repositories-using-the-api/
     *
     * Bitbucket answers this directly instead of redirecting to a signed URL,
     * so the access token is embedded as HTTP Basic userinfo -- the returned
     * URL carries the credential and must be treated as a secret.
     */
    public function getRepositoryPresignedUrl(string $owner, string $repositoryName, string $ref = '', string $format = 'tarball'): string
    {
        $extension = match ($format) {
            'tarball' => 'tar.gz',
            'zipball' => 'zip',
            default => throw new Exception("Invalid archive format: {$format}. Use 'tarball' or 'zipball'."),
        };

        // Bitbucket resolves HEAD to the repository's default branch
        $ref = $ref === '' || $ref === '0' ? 'HEAD' : $ref;

        $encodedRef = rawurlencode($this->resolveRef($owner, $repositoryName, $ref));

        return "{$this->bitbucketUrl}/{$owner}/{$repositoryName}/get/{$encodedRef}.{$extension}";
    }

    public function generateCloneCommand(string $owner, string $repositoryName, string $version, string $versionType, string $directory, string $rootDirectory): string
    {
        if (\in_array($rootDirectory, ['', '0', '/'], true)) {
            $rootDirectory = '*';
        }

        $cloneUrl = escapeshellarg("{$this->authenticatedBitbucketUrl()}/{$owner}/{$repositoryName}.git");
        $directory = escapeshellarg($directory);
        $rootDirectory = escapeshellarg($rootDirectory);

        $commands = [
            "mkdir -p {$directory}",
            "cd {$directory}",
            'git config --global init.defaultBranch main',
            'git init',
            "git remote add origin {$cloneUrl}",
            'git config core.sparseCheckout true',
            "echo {$rootDirectory} >> .git/info/sparse-checkout",
            "git config --add remote.origin.fetch '+refs/heads/*:refs/remotes/origin/*'",
            'git config remote.origin.tagopt --no-tags',
        ];

        switch ($versionType) {
            case self::CLONE_TYPE_BRANCH:
                $branchName = escapeshellarg($version);
                $commands[] = "if git ls-remote --exit-code --heads origin {$branchName}; then git pull --depth=1 origin {$branchName} && git checkout {$branchName}; else git checkout -b {$branchName}; fi";
                break;
            case self::CLONE_TYPE_COMMIT:
                $commitHash = escapeshellarg($version);
                $commands[] = "git fetch --depth=1 origin {$commitHash} && git checkout {$commitHash}";
                break;
            case self::CLONE_TYPE_TAG:
                $tagName = escapeshellarg($version);
                $commands[] = "git fetch --depth=1 origin refs/tags/{$tagName} && git checkout FETCH_HEAD";
                break;
            default:
                throw new Exception("Unsupported clone type: {$versionType}");
        }

        return implode(' && ', $commands);
    }

    /**
     * Bitbucket batches every ref a push touched into a single delivery, so one
     * payload can describe several branches. They are reported in the order
     * the payload lists them.
     *
     * @return array<array<mixed>>
     */
    public function getEvents(string $event, string $payload): array
    {
        $payloadArray = json_decode($payload, true);
        if (!\is_array($payloadArray)) {
            throw new Exception('Invalid payload.');
        }

        $repository = \is_array($payloadArray['repository'] ?? null) ? $payloadArray['repository'] : [];
        $actor = \is_array($payloadArray['actor'] ?? null) ? $payloadArray['actor'] : [];

        switch ($event) {
            case 'repo:push':
                $changes = $payloadArray['push']['changes'] ?? [];

                $events = [];
                foreach ($changes as $change) {
                    if (!\is_array($change)) {
                        continue;
                    }
                    if (!$this->isBranchChange($change)) {
                        continue;
                    }
                    $events[] = $this->parsePushChange($change, $repository, $actor);
                }

                return $events;

            case 'pullrequest:created':
            case 'pullrequest:updated':
            case 'pullrequest:fulfilled':
            case 'pullrequest:rejected':
                return [$this->parsePullRequestEvent($event, $payloadArray, $repository, $actor)];

            default:
                return [];
        }
    }

    /**
     * A push carries tag changes alongside branch ones, and the shared event
     * shape describes a branch push, so tags are left out rather than reported
     * as a branch. A change with no type at all is taken to be a branch.
     *
     * @param array<mixed> $change
     */
    private function isBranchChange(array $change): bool
    {
        $type = $change['new']['type'] ?? ($change['old']['type'] ?? 'branch');

        return \in_array($type, ['branch', 'named_branch'], true);
    }

    /**
     * @param array<mixed> $change
     * @param array<mixed> $repository
     * @param array<mixed> $actor
     * @return array<mixed>
     */
    private function parsePushChange(array $change, array $repository, array $actor): array
    {
        $actorLinks = \is_array($actor['links'] ?? null) ? $actor['links'] : [];
        $owner = $this->workspaceSlugOf($repository);
        $repositoryUrl = (string) ($repository['links']['html']['href'] ?? '');

        $new = \is_array($change['new'] ?? null) ? $change['new'] : [];
        $old = \is_array($change['old'] ?? null) ? $change['old'] : [];

        // A deleted branch is reported as a change with no new state
        $branch = $new['name'] ?? ($old['name'] ?? '');
        $target = \is_array($new['target'] ?? null) ? $new['target'] : [];
        $author = \is_array($target['author'] ?? null) ? $target['author'] : [];
        $raw = (string) ($author['raw'] ?? '');

        $authorName = $this->authorNameOf($author);

        $authorEmail = '';
        if (preg_match('/<([^>]*)>/', $raw, $matches) === 1) {
            $authorEmail = $matches[1];
        }

        return [
            'branchCreated' => ($change['created'] ?? false) === true,
            'branchDeleted' => ($change['closed'] ?? false) === true,
            'branch' => $branch,
            'branchUrl' => $repositoryUrl !== '' && $repositoryUrl !== '0' && !empty($branch) ? $repositoryUrl . '/branch/' . $branch : '',
            'repositoryId' => (string) ($repository['full_name'] ?? ''),
            'repositoryName' => $repository['name'] ?? '',
            'repositoryUrl' => $repositoryUrl,
            'installationId' => '', // Bitbucket has no installations
            'commitHash' => $target['hash'] ?? '',
            'owner' => $owner,
            'authorUrl' => $actorLinks['html']['href'] ?? '',
            'authorAvatarUrl' => $actorLinks['avatar']['href'] ?? '',
            'headCommitAuthorName' => $authorName,
            'headCommitAuthorEmail' => $authorEmail,
            'headCommitMessage' => $target['message'] ?? '',
            'headCommitUrl' => $target['links']['html']['href'] ?? '',
            'external' => false,
            'pullRequestNumber' => '',
            'action' => '',
            // Bitbucket's push payload carries no per-commit file lists
            'affectedFiles' => [],
        ];
    }

    /**
     * @param array<mixed> $payloadArray
     * @param array<mixed> $repository
     * @param array<mixed> $actor
     * @return array<mixed>
     */
    private function parsePullRequestEvent(string $event, array $payloadArray, array $repository, array $actor): array
    {
        $actorLinks = \is_array($actor['links'] ?? null) ? $actor['links'] : [];
        $owner = $this->workspaceSlugOf($repository);
        $repositoryUrl = (string) ($repository['links']['html']['href'] ?? '');

        $pullRequest = \is_array($payloadArray['pullrequest'] ?? null) ? $payloadArray['pullrequest'] : [];
        $source = \is_array($pullRequest['source'] ?? null) ? $pullRequest['source'] : [];
        $destination = \is_array($pullRequest['destination'] ?? null) ? $pullRequest['destination'] : [];

        $branch = $source['branch']['name'] ?? '';
        $commitHash = $source['commit']['hash'] ?? '';

        // A pull request whose source lives in another repository is a
        // fork-based external contribution; defaults to false (intentional) if
        // either uuid is missing.
        $sourceRepositoryId = $source['repository']['uuid'] ?? null;
        $destinationRepositoryId = $destination['repository']['uuid'] ?? null;
        $external = $sourceRepositoryId !== null
            && $destinationRepositoryId !== null
            && $sourceRepositoryId !== $destinationRepositoryId;

        return [
            'branch' => $branch,
            'branchUrl' => $repositoryUrl !== '' && $repositoryUrl !== '0' && !empty($branch) ? $repositoryUrl . '/branch/' . $branch : '',
            'repositoryId' => (string) ($repository['full_name'] ?? ''),
            'repositoryName' => $repository['name'] ?? '',
            'repositoryUrl' => $repositoryUrl,
            'installationId' => '',
            'commitHash' => $commitHash,
            'owner' => $owner,
            'authorUrl' => $actorLinks['html']['href'] ?? '',
            'authorAvatarUrl' => $actorLinks['avatar']['href'] ?? '',
            'headCommitUrl' => $repositoryUrl !== '' && $repositoryUrl !== '0' && !empty($commitHash) ? $repositoryUrl . '/commits/' . $commitHash : '',
            'external' => $external,
            'pullRequestNumber' => $pullRequest['id'] ?? '',
            'action' => self::PULL_REQUEST_ACTION_MAP[$event],
        ];
    }

    /**
     * Bitbucket prefixes the digest with the algorithm it used, as GitHub does.
     */
    #[\Override]
    public function validateWebhookEvent(string $payload, string $signature, string $signatureKey): bool
    {
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $signatureKey);

        return hash_equals($expected, $signature);
    }
}
