<?php

namespace Utopia\VCS\Adapter\Git;

use Ahc\Jwt\JWT;
use Exception;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Exception\FileNotFound;
use Utopia\VCS\Exception\RepositoryNotFound;

class GitHub extends Git
{
    public const EVENT_PUSH = 'push';

    public const EVENT_PULL_REQUEST = 'pull_request';

    public const EVENT_INSTALLATION = 'installation';

    public const CONTENTS_DIRECTORY = 'dir';

    public const CONTENTS_FILE = 'file';

    /**
     * GitHub App JWT expiry in seconds. GitHub allows a maximum of 10 minutes;
     * we use 9 minutes to leave a 1-minute safety margin for clock drift.
     *
     * @see https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/generating-a-json-web-token-jwt-for-a-github-app
     * "The time must be no more than 10 minutes into the future."
     */
    public const GITHUB_APP_JWT_EXPIRY = 60 * 9;

    protected string $endpoint = 'https://api.github.com';

    protected string $accessToken = '';

    protected string $jwtToken;

    protected string $installationId;

    /**
     * Global Headers
     *
     * @var array<string, string>
     */
    protected $headers = ['content-type' => 'application/json'];

    public function __construct(protected Cache $cache) {}

    /**
     * Get Adapter Name
     */
    public function getName(): string
    {
        return 'github';
    }

    /**
     * GitHub Initialisation with access token generation.
     */
    public function initializeVariables(string $installationId, string $privateKey, ?string $appId = null, ?string $accessToken = null, ?string $refreshToken = null): void
    {
        $this->installationId = $installationId;

        // Cache for 1 minute less than the JWT expiry so we refresh before the token actually expires.
        $response = $this->cache->load($installationId, self::GITHUB_APP_JWT_EXPIRY - 60);
        if ($response == false) {
            $this->generateAccessToken($privateKey, $appId);

            $tokens = json_encode([
                'jwtToken' => $this->jwtToken,
                'accessToken' => $this->accessToken,
            ]) ?: '{}';

            $this->cache->save($installationId, $tokens);
        } else {
            $parsed = json_decode((string) $response, true);
            $this->jwtToken = $parsed['jwtToken'] ?? '';
            $this->accessToken = $parsed['accessToken'] ?? '';
        }
    }

    /**
     * Create new repository
     *
     * @return array<mixed> Details of new repository
     */
    public function createRepository(string $owner, string $repositoryName, bool $private): array
    {
        $url = "/orgs/{$owner}/repos";

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => "Bearer $this->accessToken"], [
            'name' => $repositoryName,
            'private' => $private,
        ]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Creating repository {$repositoryName} failed with status code {$statusCode}", $statusCode);
        }

        return $response['body'] ?? [];
    }
    /**
     * Create a pull request
     *
     * @param  string  $owner  Owner of the repository
     * @param  string  $repositoryName  Name of the repository
     * @param  string  $title  PR title
     * @param  string  $head  Source branch
     * @param  string  $base  Target branch
     * @param  string  $body  PR description (optional)
     * @return array<mixed> Created PR details
     */
    public function createPullRequest(string $owner, string $repositoryName, string $title, string $head, string $base, string $body = ''): array
    {
        throw new Exception('Not implemented');
    }

    /**
     * Create a webhook on a repository
     */
    public function createWebhook(string $owner, string $repositoryName, string $url, string $secret, array $events = ['push', 'pull_request']): int
    {
        $response = $this->call(
            self::METHOD_POST,
            "/repos/{$owner}/{$repositoryName}/hooks",
            ['Authorization' => "Bearer $this->accessToken"],
            [
                'name' => 'web',
                'active' => true,
                'events' => $events,
                'config' => [
                    'url' => $url,
                    'content_type' => 'json',
                    'secret' => $secret,
                ],
            ],
        );

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Failed to create webhook: HTTP {$responseHeadersStatusCode}", $responseHeadersStatusCode);
        }

        $id = $response['body']['id'] ?? null;
        if ($id === null) {
            throw new Exception('Webhook created but response did not include an id');
        }

        return (int) $id;
    }

    /**
     * Create a file in a repository
     *
     * @param  string  $owner  Owner of the repository
     * @param  string  $repositoryName  Name of the repository
     * @param  string  $filepath  Path where file should be created
     * @param  string  $content  Content of the file
     * @param  string  $message  Commit message
     * @param  string  $branch  Branch to create file on (optional)
     * @return array<mixed> Response from API
     */
    public function createFile(string $owner, string $repositoryName, string $filepath, string $content, string $message = 'Add file', string $branch = ''): array
    {
        $url = "/repos/{$owner}/{$repositoryName}/contents/{$filepath}";

        $payload = [
            'message' => $message,
            'content' => base64_encode($content),
        ];

        // GitHub supports branch parameter
        if ($branch !== '' && $branch !== '0') {
            $payload['branch'] = $branch;
        }

        $response = $this->call(
            self::METHOD_PUT,
            $url,
            ['Authorization' => "Bearer $this->accessToken"],
            $payload,
        );

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Failed to create file {$filepath}: HTTP {$responseHeadersStatusCode}", $responseHeadersStatusCode);
        }

        return $response['body'] ?? [];
    }

    /**
     * Create a branch in a repository
     *
     * @param string $owner Owner of the repository
     * @param string $repositoryName Name of the repository
     * @param string $newBranchName Name of the new branch
     * @param string $oldBranchName Name of the branch to branch from
     * @return array<mixed> Response from API
     */
    public function createBranch(string $owner, string $repositoryName, string $newBranchName, string $oldBranchName): array
    {
        $latestCommit = $this->getLatestCommit($owner, $repositoryName, $oldBranchName);
        $sha = $latestCommit['commitHash'];

        $response = $this->call(self::METHOD_POST, "/repos/$owner/$repositoryName/git/refs", ['Authorization' => "Bearer $this->accessToken"], [
            'ref' => "refs/heads/$newBranchName",
            'sha' => $sha,
        ]);

        return $response['body'] ?? [];
    }

    /**
     * Determines whether the installation has access to all repositories or specific repositories
     *
     * @return bool True if installation has access to all repositories, false if it has access to specific repositories
     *
     * @throws Exception
     */
    public function hasAccessToAllRepositories(): bool
    {
        $url = '/app/installations/' . $this->installationId;
        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->jwtToken"]);
        $responseBody = $response['body'] ?? [];
        return ($responseBody['repository_selection'] ?? '') === 'all';
    }

    /**
     * Search repositories for GitHub App
     * @param string $owner Name of user or org
     * @param int $page page number
     * @param int $per_page number of results per page
     * @param string $search Query to be searched to filter repo names
     * @return array<mixed>
     *
     * @throws Exception
     */
    public function searchRepositories(string $owner, int $page, int $per_page, string $search = ''): array
    {
        // Installation has access to all repositories, use the search API which supports filtering.
        if ($this->hasAccessToAllRepositories()) {
            $url = '/search/repositories';

            $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"], [
                'q' => "{$search} user:{$owner} fork:true",
                'page' => $page,
                'per_page' => $per_page,
                'sort' => 'updated',
            ]);
            $responseHeaders = $response['headers'] ?? [];
            $statusCode = $responseHeaders['status-code'] ?? 0;

            // The search API rejects a query naming an owner that does not exist,
            // which is a missing owner rather than a failure worth reporting.
            if ($statusCode === 422) {
                return ['items' => [], 'total' => 0];
            }

            if ($statusCode >= 400) {
                throw new Exception("Failed to search repositories: HTTP {$statusCode}", $statusCode);
            }

            $responseBody = $response['body'] ?? [];

            return [
                'items' => $responseBody['items'] ?? [],
                'total' => $responseBody['total_count'] ?? 0,
            ];
        }

        // Installation has access to specific repositories, we need to perform client-side filtering.
        $url = '/installation/repositories';
        $repositories = [];

        // When no search query is provided, delegate pagination to the GitHub API.
        if ($search === '' || $search === '0') {
            $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"], [
                'page' => $page,
                'per_page' => $per_page,
            ]);

            $responseHeaders = $response['headers'] ?? [];
            $statusCode = $responseHeaders['status-code'] ?? 0;
            if ($statusCode >= 400) {
                throw new Exception("Failed to list installation repositories: HTTP {$statusCode}", $statusCode);
            }

            $responseBody = $response['body'] ?? [];

            return [
                'items' => $responseBody['repositories'] ?? [],
                'total' => $responseBody['total_count'] ?? 0,
            ];
        }

        // When search query is provided, fetch all repositories accessible by the installation and filter them locally.
        $currentPage = 1;
        while (true) {
            $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"], [
                'page' => $currentPage,
                'per_page' => 100, // Maximum allowed by GitHub API
            ]);

            $responseHeaders = $response['headers'] ?? [];
            $statusCode = $responseHeaders['status-code'] ?? 0;
            if ($statusCode >= 400) {
                throw new Exception("Failed to list installation repositories: HTTP {$statusCode}", $statusCode);
            }

            $responseBody = $response['body'] ?? [];

            // Filter repositories to only include those that match the search query.
            $filteredRepositories = array_filter($responseBody['repositories'] ?? [], fn(array $repo): bool => stripos($repo['name'] ?? '', $search) !== false);

            // Merge with result so far.
            $repositories = array_merge($repositories, $filteredRepositories);

            // If less than 100 repositories are returned, we have fetched all repositories.
            if (\count($responseBody['repositories'] ?? []) < 100) {
                break;
            }

            // Increment page number to fetch next page.
            $currentPage++;
        }

        $repositoriesInRequestedPage = \array_slice($repositories, ($page - 1) * $per_page, $per_page);

        return [
            'items' => $repositoriesInRequestedPage,
            'total' => \count($repositories),
        ];
    }

    public function getInstallationRepository(string $repositoryName): array
    {
        $currentPage = 1;
        $perPage = 100;
        $totalRepositories = 0;
        $maxRepositories = 1000;
        $url = '/installation/repositories';

        while ($totalRepositories < $maxRepositories) {
            $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"], [
                'page' => $currentPage,
                'per_page' => $perPage,
            ]);

            $responseBody = $response['body'] ?? [];

            if (!\array_key_exists('repositories', $responseBody)) {
                throw new Exception('Repositories list missing in the response.');
            }

            foreach (($responseBody['repositories'] ?? []) as $repo) {
                if (strtolower($repo['name'] ?? '') === strtolower($repositoryName)) {
                    return $repo;
                }
            }

            if (\count($responseBody['repositories'] ?? []) < $perPage) {
                break;
            }

            $currentPage++;
            $totalRepositories += $perPage;
        }

        throw new RepositoryNotFound('Repository not found.');
    }

    /**
     * Get GitHub repository
     *
     * @return array<mixed>
     *
     * @throws Exception
     */
    public function getRepository(string $owner, string $repositoryName): array
    {
        $url = "/repos/{$owner}/{$repositoryName}";
        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode === 404 || $responseHeadersStatusCode === 422) {
            throw new RepositoryNotFound('Repository not found.');
        }

        return $response['body'] ?? [];
    }

    /**
     * Fetches repository name using repository id
     *
     * @param  string  $repositoryId ID of GitHub Repository
     * @return string name of GitHub repository
     */
    public function getRepositoryName(string $repositoryId): string
    {
        $url = "/repositories/$repositoryId";
        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        $responseBody = $response['body'] ?? [];

        if (!\array_key_exists('name', $responseBody)) {
            throw new RepositoryNotFound('Repository not found');
        }

        return $responseBody['name'] ?? '';
    }

    /**
     * Get repository tree
     *
     * @param string $owner Owner name of the repository
     * @param string $repositoryName Name of the GitHub repository
     * @param string $branch Name of the branch
     * @param bool $recursive Whether to fetch the tree recursively
     * @return array<string> List of files in the repository
     */
    public function getRepositoryTree(string $owner, string $repositoryName, string $branch, bool $recursive = false): array
    {
        // if recursive is true, add optional query param to url
        $url = "/repos/$owner/$repositoryName/git/trees/$branch" . ($recursive ? '?recursive=1' : '');
        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode == 404) {
            return [];
        }

        $responseBody = $response['body'] ?? [];

        return array_column($responseBody['tree'] ?? [], 'path');
    }

    /**
     * Get repository languages
     *
     * @param  string  $owner Owner name of the repository
     * @param  string  $repositoryName Name of the GitHub repository
     * @return array<mixed> List of repository languages
     */
    public function listRepositoryLanguages(string $owner, string $repositoryName): array
    {
        $url = "/repos/$owner/$repositoryName/languages";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        $responseBody = $response['body'] ?? [];

        if (!empty($responseBody)) {
            return array_keys($responseBody);
        }

        return [];
    }

    /**
     * Get contents of the specified file.
     *
     * @param  string  $owner Owner name
     * @param  string  $repositoryName Name of the repository
     * @param  string  $path Path to the file
     * @param  string  $ref The name of the commit/branch/tag
     * @return array<string, mixed> File details
     */
    public function getRepositoryContent(string $owner, string $repositoryName, string $path, string $ref = ''): array
    {
        $url = "/repos/$owner/$repositoryName/contents/" . $this->normalizeRepositoryPath($path);
        if ($ref !== '' && $ref !== '0') {
            $url .= "?ref=$ref";
        }

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode !== 200) {
            throw new FileNotFound();
        }

        $responseBody = $response['body'] ?? [];

        $encoding = $responseBody['encoding'] ?? '';

        $content = '';
        if ($encoding === 'base64') {
            $content = base64_decode($responseBody['content'] ?? '');
        } else {
            throw new FileNotFound();
        }

        return [
            'sha' => $responseBody['sha'] ?? '',
            'size' => $responseBody['size'] ?? 0,
            'content' => $content,
        ];
    }

    /**
     * List contents of the specified root directory.
     *
     * @param  string  $owner Owner name of the repository
     * @param  string  $repositoryName Name of the GitHub repository
     * @param  string  $path Path to list contents from
     * @param  string  $ref The name of the commit/branch/tag
     * @return array<mixed> List of contents at the specified path
     */
    public function listRepositoryContents(string $owner, string $repositoryName, string $path = '', string $ref = ''): array
    {
        $path = $this->normalizeRepositoryPath($path);
        $url = "/repos/$owner/$repositoryName/contents";
        if ($path !== '' && $path !== '0') {
            $url .= "/$path";
        }
        if ($ref !== '' && $ref !== '0') {
            $url .= "?ref=$ref";
        }

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode == 404) {
            return [];
        }

        $responseBody = $response['body'] ?? [];

        $items = [];

        if (!empty($responseBody[0] ?? [])) {
            $items = $responseBody;
        } elseif (!empty($responseBody)) {
            $items = [$responseBody];
        }

        $contents = [];
        foreach ($items as $item) {
            $type = $item['type'] ?? 'file';
            $contents[] = [
                'name' => $item['name'] ?? '',
                'size' => $item['size'] ?? 0,
                'type' => $type === 'file' ? self::CONTENTS_FILE : self::CONTENTS_DIRECTORY,
            ];
        }

        return $contents;
    }

    public function deleteRepository(string $owner, string $repositoryName): bool
    {
        $url = "/repos/{$owner}/{$repositoryName}";

        $response = $this->call(self::METHOD_DELETE, $url, ['Authorization' => "Bearer $this->accessToken"]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Deleting repository $repositoryName failed with status code $responseHeadersStatusCode", $responseHeadersStatusCode);
        }
        return true;
    }

    /**
     * Add Comment to Pull Request
     *
     *
     * @throws Exception
     */
    public function createComment(string $owner, string $repositoryName, int $pullRequestNumber, string $comment): string
    {
        $url = '/repos/' . $owner . '/' . $repositoryName . '/issues/' . $pullRequestNumber . '/comments';

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => "Bearer $this->accessToken"], ['body' => $comment]);

        $responseBody = $response['body'] ?? [];

        if (!\array_key_exists('id', $responseBody)) {
            throw new Exception('Comment creation response is missing comment ID.');
        }

        return $responseBody['id'] ?? '';
    }

    /**
     * Get Comment of Pull Request
     *
     * @param string $owner       The owner of the repository
     * @param string $repositoryName    The name of the repository
     * @param string $commentId   The ID of the comment to retrieve
     * @return string              The retrieved comment
     *
     * @throws Exception
     */
    public function getComment(string $owner, string $repositoryName, string $commentId): string
    {
        $url = '/repos/' . $owner . '/' . $repositoryName . '/issues/comments/' . $commentId;

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        $responseBody = $response['body'] ?? [];

        return $responseBody['body'] ?? '';
    }

    /**
     * Update Pull Request Comment
     *
     * @param string $owner      The owner of the repository
     * @param string $repositoryName   The name of the repository
     * @param string $commentId  The ID of the comment to update
     * @param string $comment    The updated comment content
     * @return string            The ID of the updated comment
     *
     * @throws Exception
     */
    public function updateComment(string $owner, string $repositoryName, string $commentId, string $comment): string
    {
        $url = '/repos/' . $owner . '/' . $repositoryName . '/issues/comments/' . $commentId;

        $response = $this->call(self::METHOD_PATCH, $url, ['Authorization' => "Bearer $this->accessToken"], ['body' => $comment]);

        $responseBody = $response['body'] ?? [];

        if (!\array_key_exists('id', $responseBody)) {
            throw new Exception('Comment update response is missing comment ID.');
        }

        return $responseBody['id'] ?? '';
    }

    /**
     * Generate Access Token
     */
    protected function generateAccessToken(string $privateKey, ?string $appId): void
    {
        // adhocore/jwt treats a string key as a file path, so it must receive the parsed key object
        $privateKeyObj = openssl_pkey_get_private($privateKey);
        if ($privateKeyObj === false) {
            throw new Exception('Failed to read the GitHub App private key');
        }

        $appIdentifier = $appId;

        $iat = time();
        $exp = $iat + self::GITHUB_APP_JWT_EXPIRY;
        $payload = [
            'iat' => $iat,
            'exp' => $exp,
            'iss' => $appIdentifier,
        ];

        // generate access token
        $jwt = new JWT($privateKeyObj, 'RS256');
        $token = $jwt->encode($payload);
        $this->jwtToken = $token;
        $response = $this->call(self::METHOD_POST, '/app/installations/' . $this->installationId . '/access_tokens', ['Authorization' => 'Bearer ' . $token]);
        $responseBody = $response['body'] ?? [];
        $statusCode = $response['headers']['status-code'] ?? 0;
        if (!\array_key_exists('token', $responseBody)) {
            $safeBody = json_encode(array_intersect_key($responseBody, array_flip(['message', 'documentation_url'])));
            throw new Exception('Failed to retrieve access token from GitHub API. Status: ' . $statusCode . '. Response: ' . $safeBody, $statusCode);
        }
        $this->accessToken = $responseBody['token'] ?? '';
    }

    /**
     * Get user
     *
     * @return array<mixed>
     *
     * @throws Exception
     */
    public function getUser(string $username): array
    {
        return $this->call(self::METHOD_GET, '/users/' . $username);
    }

    /**
     * Get owner name of the GitHub installation
     *
     * @param string $installationId GitHub App installation ID
     * @param int|null $repositoryId Not used by GitHub (parameter exists for this adapter compatibility)
     * @return string Owner login/username
     */
    public function getOwnerName(string $installationId, ?int $repositoryId = null): string
    {
        // GitHub doesn't use $repositoryId - only installationId
        $url = '/app/installations/' . $installationId;
        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->jwtToken"]);

        $responseBody = $response['body'] ?? [];
        $responseBodyAccount = $responseBody['account'] ?? [];

        if (!\array_key_exists('login', $responseBodyAccount)) {
            throw new Exception('Owner name retrieval response is missing account login.');
        }

        return $responseBodyAccount['login'] ?? '';
    }

    /**
     * Get Pull Request
     *
     * @return array<mixed> The retrieved pull request
     */
    public function getPullRequest(string $owner, string $repositoryName, int $pullRequestNumber): array
    {
        $url = "/repos/{$owner}/{$repositoryName}/pulls/{$pullRequestNumber}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to get pull request: HTTP {$statusCode}", $statusCode);
        }

        return $response['body'] ?? [];
    }

    /**
     * Get files changed in a pull request
     *
     * @return array<mixed> List of files changed in the pull request
     */
    public function getPullRequestFiles(string $owner, string $repositoryName, int $pullRequestNumber): array
    {
        $allFiles = [];
        $perPage = 30;
        $currentPage = 1;

        while (true) {
            $url = "/repos/{$owner}/{$repositoryName}/pulls/{$pullRequestNumber}/files";

            $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"], [
                'per_page' => $perPage,
                'page' => $currentPage,
            ]);

            $files = $response['body'] ?? [];
            $allFiles = array_merge($allFiles, $files);

            if (\count($files) < $perPage) {
                break;
            }

            $currentPage++;
        }

        return $allFiles;
    }

    /**
     * Get latest opened pull request with specific base branch
     * @return array<mixed>
     */
    public function getPullRequestFromBranch(string $owner, string $repositoryName, string $branch): array
    {
        $head = "{$owner}:{$branch}";
        $url = "/repos/{$owner}/{$repositoryName}/pulls?head={$head}&state=open&sort=updated&per_page=1";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        $responseBody = $response['body'] ?? [];

        return $responseBody[0] ?? [];
    }

    /**
     * Lists branches for a given repository, optionally filtered by a search prefix.
     *
     * When $search is provided, uses GET /repos/{owner}/{repo}/git/matching-refs/heads/{prefix}
     * to perform server-side prefix filtering. This endpoint ignores per_page/page params and
     * always returns all matching refs in one call; results are then paginated client-side.
     * When $search is empty, uses GET /repos/{owner}/{repo}/branches with GitHub's native pagination.
     *
     * @param  int  $perPage Clamped to [1, 100]
     * @param  int  $page Page number (1-based)
     * @param  string  $search Prefix filter; empty returns all branches
     * @return array<string> List of branch names
     */
    public function listBranches(string $owner, string $repositoryName, int $perPage = 100, int $page = 1, string $search = ''): array
    {
        $perPage = min(max($perPage, 1), 100);

        if ($search !== '') {
            $url = "/repos/$owner/$repositoryName/git/matching-refs/heads/" . str_replace('%2F', '/', rawurlencode($search));
            $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

            $statusCode = $response['headers']['status-code'] ?? 0;
            $responseBody = $response['body'] ?? [];

            if ($statusCode < 200 || $statusCode >= 300 || !\is_array($responseBody)) {
                return [];
            }

            $branches = array_map(fn(array $ref): string|array => str_replace('refs/heads/', '', $ref['ref'] ?? ''), $responseBody);
            $offset = ($page - 1) * $perPage;

            return array_values(\array_slice($branches, $offset, $perPage));
        }

        $url = "/repos/$owner/$repositoryName/branches";
        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"], [
            'page' => $page,
            'per_page' => $perPage,
        ]);

        $statusCode = $response['headers']['status-code'] ?? 0;
        $responseBody = $response['body'] ?? [];

        if ($statusCode < 200 || $statusCode >= 300 || !\is_array($responseBody)) {
            return [];
        }

        return array_values(array_map(fn(array $branch) => $branch['name'] ?? '', $responseBody));
    }

    /**
     * Lists tags for a given repository, optionally filtered by a glob pattern.
     *
     * Uses GET /repos/{owner}/{repo}/git/matching-refs/tags to fetch every tag ref
     * in a single call, then filters client-side with the glob pattern.
     *
     * @param  string  $search Glob pattern (e.g. 'v1.*'); empty returns all tags
     * @return array<string> List of tag names
     */
    public function listTags(string $owner, string $repositoryName, string $search = ''): array
    {
        $url = "/repos/$owner/$repositoryName/git/matching-refs/tags/";
        $headers = $this->accessToken === '' || $this->accessToken === '0' ? [] : ['Authorization' => "Bearer $this->accessToken"];
        $response = $this->call(self::METHOD_GET, $url, $headers);

        $statusCode = $response['headers']['status-code'] ?? 0;
        $responseBody = $response['body'] ?? [];

        if ($statusCode < 200 || $statusCode >= 300 || !\is_array($responseBody)) {
            return [];
        }

        $tags = array_map(fn(array $ref): string|array => str_replace('refs/tags/', '', $ref['ref'] ?? ''), $responseBody);

        return $this->matchGlob($tags, $search);
    }

    /**
     * Get details of a commit using commit hash
     *
     * @param  string  $owner Owner name of the repository
     * @param  string  $repositoryName Name of the GitHub repository
     * @param  string  $commitHash SHA of the commit
     * @return array<mixed> Details of the commit
     */
    public function getCommit(string $owner, string $repositoryName, string $commitHash): array
    {
        $url = "/repos/$owner/$repositoryName/commits/$commitHash";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode === 404) {
            throw new RepositoryNotFound('Commit not found.');
        }
        if ($responseHeadersStatusCode === 422) {
            throw new Exception('Commit not found or inaccessible.');
        }

        $responseBody = $response['body'] ?? [];
        $responseBodyAuthor = $responseBody['author'] ?? [];
        $responseBodyCommit = $responseBody['commit'] ?? [];
        $responseBodyCommitAuthor = $responseBodyCommit['author'] ?? [];

        return [
            'commitAuthor' => $responseBodyCommitAuthor['name'] ?? 'Unknown',
            'commitMessage' => $responseBodyCommit['message'] ?? 'No message',
            'commitAuthorAvatar' => $responseBodyAuthor['avatar_url'] ?? '',
            'commitAuthorUrl' => $responseBodyAuthor['html_url'] ?? '',
            'commitHash' => $responseBody['sha'] ?? '',
            'commitUrl' => $responseBody['html_url'] ?? '',
        ];
    }

    /**
     * Get latest commit of a branch
     *
     * @param  string  $owner Owner name of the repository
     * @param  string  $repositoryName Name of the GitHub repository
     * @param  string  $branch Name of the branch
     * @return array<mixed> Details of the commit
     */
    public function getLatestCommit(string $owner, string $repositoryName, string $branch): array
    {
        $url = "/repos/$owner/$repositoryName/commits/$branch?per_page=1";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode === 404) {
            throw new RepositoryNotFound("Branch not found: {$branch}");
        }
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Failed to get latest commit: HTTP {$responseHeadersStatusCode}", $responseHeadersStatusCode);
        }

        $responseBody = $response['body'] ?? [];
        $responseBodyCommit = $responseBody['commit'] ?? [];
        $responseBodyCommitAuthor = $responseBodyCommit['author'] ?? [];
        $responseBodyAuthor = \is_array($responseBody['author'] ?? null) ? $responseBody['author'] : [];

        return [
            'commitAuthor' => $responseBodyCommitAuthor['name'] ?? '',
            'commitMessage' => $responseBodyCommit['message'] ?? '',
            'commitHash' => $responseBody['sha'] ?? '',
            'commitUrl' => $responseBody['html_url'] ?? '',
            'commitAuthorAvatar' => $responseBodyAuthor['avatar_url'] ?? '',
            'commitAuthorUrl' => $responseBodyAuthor['html_url'] ?? '',
        ];
    }

    /**
     * Get a short-lived presigned URL to download the repository archive.
     *
     * GitHub answers its tarball/zipball endpoints with a temporary redirect to a
     * signed codeload URL. We capture that URL instead of following the redirect,
     * so callers can download the archive directly without proxying the token.
     *
     * @param  string  $owner Owner name of the repository
     * @param  string  $repositoryName Name of the repository
     * @param  string  $ref Branch, tag or commit to download (defaults to the default branch)
     * @param  string  $format Archive format: 'tarball' or 'zipball'
     * @return string Presigned download URL
     */
    public function getRepositoryPresignedUrl(string $owner, string $repositoryName, string $ref = '', string $format = 'tarball'): string
    {
        if (!\in_array($format, ['tarball', 'zipball'], true)) {
            throw new Exception("Invalid archive format: {$format}. Use 'tarball' or 'zipball'.");
        }

        $url = "/repos/$owner/$repositoryName/$format";
        if ($ref !== '' && $ref !== '0') {
            // Encode the ref but keep slashes so nested branch names (e.g. feature/foo) still resolve
            $url .= '/' . str_replace('%2F', '/', rawurlencode($ref));
        }

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"], [], false, false);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode === 404) {
            throw new RepositoryNotFound('Repository or ref not found.');
        }
        if ($responseHeadersStatusCode === 401 || $responseHeadersStatusCode === 403) {
            throw new Exception('Access denied to repository archive; check the access token and its permissions.', $responseHeadersStatusCode);
        }

        $presignedUrl = $responseHeaders['location'] ?? '';
        if (empty($presignedUrl)) {
            throw new Exception("Failed to get presigned URL: HTTP {$responseHeadersStatusCode}", $responseHeadersStatusCode);
        }

        return $presignedUrl;
    }

    /**
     * Updates status check of each commit
     * state can be one of: error, failure, pending, success
     */
    public function updateCommitStatus(string $repositoryName, string $commitHash, string $owner, string $state, string $description = '', string $target_url = '', string $context = ''): void
    {
        $url = "/repos/$owner/$repositoryName/statuses/$commitHash";

        $body = [
            'state' => $state,
            'target_url' => $target_url,
            'description' => $description,
            'context' => $context,
        ];

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => "Bearer $this->accessToken"], $body);
        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to update commit status: HTTP {$statusCode}", $statusCode);
        }
    }

    /**
     * Creates a check run for a commit.
     * status can be one of: queued, in_progress, completed
     * conclusion (required when status=completed) can be one of: action_required, cancelled, failure, neutral, success, skipped, timed_out
     *
     * @param array<mixed> $annotations
     * @param array<mixed> $images
     * @param array<mixed> $actions
     * @return array<mixed>
     */
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
        $url = "/repos/$owner/$repositoryName/check-runs";

        if ($status === 'completed' && ($conclusion === '' || $conclusion === '0')) {
            throw new Exception("conclusion is required when status is 'completed'");
        }

        // Conclusion requires status=completed; auto-set completed_at if not provided.
        if ($conclusion !== '' && $conclusion !== '0') {
            $status = 'completed';
            if ($completedAt === '' || $completedAt === '0') {
                $completedAt = gmdate('Y-m-d\TH:i:s\Z');
            }
        }

        $body = array_merge(
            [
                'name' => $name,
                'head_sha' => $headSha,
                'status' => $status,
            ],
            array_filter([
                'conclusion' => $conclusion,
                'completed_at' => $completedAt,
                'details_url' => $detailsUrl,
                'external_id' => $externalId,
                'started_at' => $startedAt,
            ], fn(string $value): bool => $value !== '' && $value !== '0'),
        );

        // Output requires both title and summary.
        if ($title !== '' && $title !== '0' && ($summary !== '' && $summary !== '0')) {
            $output = array_filter(['title' => $title, 'summary' => $summary, 'text' => $text], fn(string $value): bool => $value !== '' && $value !== '0');
            if ($annotations !== []) {
                $output['annotations'] = $annotations;
            }
            if ($images !== []) {
                $output['images'] = $images;
            }
            $body['output'] = $output;
        }

        if ($actions !== []) {
            $body['actions'] = $actions;
        }

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => "Bearer $this->accessToken"], $body);

        $responseHeadersStatusCode = $response['headers']['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Failed to create check run: HTTP $responseHeadersStatusCode", $responseHeadersStatusCode);
        }

        $checkRun = $response['body'] ?? [];
        $checkRun['id'] = (string) ($checkRun['id'] ?? '');

        return $checkRun;
    }

    /**
     * Gets a check run by ID.
     *
     * @return array<mixed>
     */
    public function getCheckRun(string $owner, string $repositoryName, string $checkRunId): array
    {
        $url = "/repos/$owner/$repositoryName/check-runs/$checkRunId";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        $responseHeadersStatusCode = $response['headers']['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Failed to get check run $checkRunId: HTTP $responseHeadersStatusCode", $responseHeadersStatusCode);
        }

        $checkRun = $response['body'] ?? [];
        $checkRun['id'] = (string) ($checkRun['id'] ?? '');

        return $checkRun;
    }

    /**
     * Updates an existing check run.
     * status can be one of: queued, in_progress, completed
     * conclusion (required when status=completed) can be one of: action_required, cancelled, failure, neutral, success, skipped, timed_out
     *
     * @param array<mixed> $annotations
     * @param array<mixed> $images
     * @return array<mixed>
     */
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
        $url = "/repos/$owner/$repositoryName/check-runs/$checkRunId";

        if ($status === 'completed' && ($conclusion === '' || $conclusion === '0')) {
            throw new Exception("conclusion is required when status is 'completed'");
        }

        // Conclusion requires status=completed; auto-set completed_at if not provided.
        if ($conclusion !== '' && $conclusion !== '0') {
            $status = 'completed';
            if ($completedAt === '' || $completedAt === '0') {
                $completedAt = gmdate('Y-m-d\TH:i:s\Z');
            }
        }

        $body = array_filter([
            'name' => $name,
            'status' => $status,
            'details_url' => $detailsUrl,
            'external_id' => $externalId,
            'started_at' => $startedAt,
            'conclusion' => $conclusion,
            'completed_at' => $completedAt,
        ], fn(string $value): bool => $value !== '' && $value !== '0');

        // Output requires both title and summary.
        if ($title !== '' && $title !== '0' && ($summary !== '' && $summary !== '0')) {
            $output = array_filter(['title' => $title, 'summary' => $summary, 'text' => $text], fn(string $value): bool => $value !== '' && $value !== '0');
            if ($annotations !== []) {
                $output['annotations'] = $annotations;
            }
            if ($images !== []) {
                $output['images'] = $images;
            }
            $body['output'] = $output;
        }

        if ($actions !== []) {
            $body['actions'] = $actions;
        }

        $response = $this->call(self::METHOD_PATCH, $url, ['Authorization' => "Bearer $this->accessToken"], $body);

        $responseHeadersStatusCode = $response['headers']['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Failed to update check run $checkRunId: HTTP $responseHeadersStatusCode", $responseHeadersStatusCode);
        }

        $checkRun = $response['body'] ?? [];
        $checkRun['id'] = (string) ($checkRun['id'] ?? '');

        return $checkRun;
    }

    /**
     * Generates a clone command using app access token
     */
    public function generateCloneCommand(string $owner, string $repositoryName, string $version, string $versionType, string $directory, string $rootDirectory): string
    {
        if ($rootDirectory === '' || $rootDirectory === '0') {
            $rootDirectory = '*';
        }

        // URL encode the components for the clone URL
        $owner = urlencode($owner);
        $repositoryName = urlencode($repositoryName);
        $accessToken = $this->accessToken === '' || $this->accessToken === '0' ? '' : ':' . urlencode($this->accessToken);

        $cloneUrl = "https://{$owner}{$accessToken}@github.com/{$owner}/{$repositoryName}";

        $directory = escapeshellarg($directory);
        $rootDirectory = escapeshellarg($rootDirectory);

        $commands = [
            "mkdir -p {$directory}",
            "cd {$directory}",
            'git config --global init.defaultBranch main',
            'git init',
            "git remote add origin {$cloneUrl}",
            // Enable sparse checkout
            'git config core.sparseCheckout true',
            "echo {$rootDirectory} >> .git/info/sparse-checkout",
            // Disable fetching of refs we don't need
            "git config --add remote.origin.fetch '+refs/heads/*:refs/remotes/origin/*'",
            // Disable fetching of tags
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
                $commands[] = "git fetch --depth=1 origin refs/tags/$(git ls-remote --tags origin {$tagName} | tail -n 1 | awk -F '/' '{print $3}') && git checkout FETCH_HEAD";
                break;
        }

        return implode(' && ', $commands);
    }

    public function getEventHeaderName(): string
    {
        return 'x-github-event';
    }

    public function getSignatureHeaderName(): string
    {
        return 'x-hub-signature-256';
    }

    public function getSupportedWebhookScopes(): array
    {
        return [self::WEBHOOK_SCOPE_INSTALLATION, self::WEBHOOK_SCOPE_REPOSITORY];
    }

    public function getRepositoryUrl(string $owner, string $repositoryName): string
    {
        return "https://github.com/{$owner}/{$repositoryName}";
    }

    public function getBranchUrl(string $owner, string $repositoryName, string $branch): string
    {
        return $this->getRepositoryUrl($owner, $repositoryName) . "/tree/{$branch}";
    }

    public function getCommitUrl(string $owner, string $repositoryName, string $commitHash): string
    {
        return $this->getRepositoryUrl($owner, $repositoryName) . "/commit/{$commitHash}";
    }

    public function getFileUrl(string $owner, string $repositoryName, string $reference): string
    {
        return $this->getRepositoryUrl($owner, $repositoryName) . "/blob/{$reference}";
    }

    /**
     * Parses webhook event payload
     *
     * @param  string  $event Type of event: push, pull_request etc
     * @param  string  $payload The webhook payload received from GitHub
     * @return array<array<mixed>> Parsed payloads as json objects
     */
    public function getEvents(string $event, string $payload): array
    {
        $payload = json_decode($payload, true);

        if ($payload === null || !\is_array($payload)) {
            throw new Exception('Invalid payload.');
        }

        $payloadInstallation = $payload['installation'] ?? [];

        $installationId = \strval($payloadInstallation['id'] ?? '');

        switch ($event) {
            case 'push':
                $payloadRepository = $payload['repository'] ?? [];
                $payloadRepositoryOwner = $payloadRepository['owner'] ?? [];
                $payloadSender = $payload['sender'] ?? [];
                $payloadHeadCommit = $payload['head_commit'] ?? [];
                $payloadHeadCommitAuthor = $payloadHeadCommit['author'] ?? [];

                $branchCreated = $payload['created'] ?? false;
                $branchDeleted = $payload['deleted'] ?? false;
                $repositoryId = \strval($payloadRepository['id'] ?? '');
                $repositoryName = $payloadRepository['name'] ?? '';
                $branch = str_replace('refs/heads/', '', $payload['ref'] ?? '');
                $repositoryUrl = $payloadRepository['html_url'] ?? '';
                $branchUrl = !empty($repositoryUrl) && !empty($branch) ? $repositoryUrl . '/tree/' . $branch : '';
                $commitHash = $payload['after'] ?? '';
                $owner = $payloadRepositoryOwner['name'] ?? '';
                $authorUrl = $payloadSender['html_url'] ?? '';
                $authorAvatarUrl = $payloadSender['avatar_url'] ?? '';
                $headCommitAuthorName = $payloadHeadCommitAuthor['name'] ?? '';
                $headCommitAuthorEmail = $payloadHeadCommitAuthor['email'] ?? '';
                $headCommitMessage = $payloadHeadCommit['message'] ?? '';
                $headCommitUrl = $payloadHeadCommit['url'] ?? '';

                $affectedFiles = [];
                foreach (($payload['commits'] ?? []) as $commit) {
                    foreach (($commit['added'] ?? []) as $added) {
                        $affectedFiles[$added] = true;
                    }

                    foreach (($commit['removed'] ?? []) as $removed) {
                        $affectedFiles[$removed] = true;
                    }

                    foreach (($commit['modified'] ?? []) as $modified) {
                        $affectedFiles[$modified] = true;
                    }
                }

                return [[
                    'branchCreated' => $branchCreated,
                    'branchDeleted' => $branchDeleted,
                    'branch' => $branch,
                    'branchUrl' => $branchUrl,
                    'repositoryId' => $repositoryId,
                    'repositoryName' => $repositoryName,
                    'repositoryUrl' => $repositoryUrl,
                    'installationId' => $installationId,
                    'commitHash' => $commitHash,
                    'owner' => $owner,
                    'authorUrl' => $authorUrl,
                    'authorAvatarUrl' => $authorAvatarUrl,
                    'headCommitAuthorName' => $headCommitAuthorName,
                    'headCommitAuthorEmail' => $headCommitAuthorEmail,
                    'headCommitMessage' => $headCommitMessage,
                    'headCommitUrl' => $headCommitUrl,
                    'external' => false,
                    'pullRequestNumber' => '',
                    'action' => '',
                    'affectedFiles' => array_keys($affectedFiles),
                ]];
            case 'pull_request':
                $payloadRepository = $payload['repository'] ?? [];
                $payloadRepositoryOwner = $payloadRepository['owner'] ?? [];
                $payloadSender = $payload['sender'] ?? [];
                $payloadPullRequest = $payload['pull_request'] ?? [];
                $payloadPullRequestHead = $payloadPullRequest['head'] ?? [];
                $payloadPullRequestHeadUser = $payloadPullRequestHead['user'] ?? [];
                $payloadPullRequestUser = $payloadPullRequest['user'] ?? [];
                $payloadPullRequestBase = $payloadPullRequest['base'] ?? [];
                $payloadPullRequestBaseUser = $payloadPullRequestBase['user'] ?? [];

                $repositoryId = \strval($payloadRepository['id'] ?? '');
                $branch = $payloadPullRequestHead['ref'] ?? '';
                $repositoryName = $payloadRepository['name'] ?? '';
                $repositoryUrl = $payloadRepository['html_url'] ?? '';
                $branchUrl = !empty($repositoryUrl) && !empty($branch) ? $repositoryUrl . '/tree/' . $branch : '';
                $pullRequestNumber = $payload['number'] ?? '';
                $action = $payload['action'] ?? '';
                $owner = $payloadRepositoryOwner['login'] ?? '';
                $authorUrl = $payloadSender['html_url'] ?? '';
                $authorAvatarUrl = $payloadPullRequestUser['avatar_url'] ?? '';
                $commitHash = $payloadPullRequestHead['sha'] ?? '';
                $headCommitUrl = $repositoryUrl ? $repositoryUrl . '/commits/' . $commitHash : '';
                $headLogin = $payloadPullRequestHeadUser['login'] ?? '';
                $baseLogin = $payloadPullRequestBaseUser['login'] ?? '';
                $external = $headLogin !== $baseLogin;

                return [[
                    'branch' => $branch,
                    'branchUrl' => $branchUrl,
                    'repositoryId' => $repositoryId,
                    'repositoryName' => $repositoryName,
                    'repositoryUrl' => $repositoryUrl,
                    'installationId' => $installationId,
                    'commitHash' => $commitHash,
                    'owner' => $owner,
                    'authorUrl' => $authorUrl,
                    'authorAvatarUrl' => $authorAvatarUrl,
                    'headCommitUrl' => $headCommitUrl,
                    'external' => $external,
                    'pullRequestNumber' => $pullRequestNumber,
                    'action' => $action,
                ]];
            case 'installation':
            case 'installation_repositories':
                $payloadInstallation = $payload['installation'] ?? [];
                $payloadInstallationAccount = $payloadInstallation['account'] ?? [];

                $action = $payload['action'] ?? '';
                $userName = $payloadInstallationAccount['login'] ?? '';

                return [[
                    'action' => $action,
                    'installationId' => $installationId,
                    'userName' => $userName,
                ]];
        }

        return [];
    }


    /**
     * Validate webhook event
     *
     * @param  string  $payload Raw body of HTTP request
     * @param  string  $signature Signature provided by GitHub in header
     * @param  string  $signatureKey Webhook secret configured on GitHub
     */
    #[\Override]
    public function validateWebhookEvent(string $payload, string $signature, string $signatureKey): bool
    {
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $signatureKey);

        return hash_equals($expected, $signature);
    }

    public function createTag(string $owner, string $repositoryName, string $tagName, string $target, string $message = ''): array
    {
        throw new Exception('createTag() is not implemented for GitHub');
    }

    public function getCommitStatuses(string $owner, string $repositoryName, string $commitHash): array
    {
        throw new Exception('getCommitStatuses() is not implemented for GitHub');
    }
}
