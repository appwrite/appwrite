<?php

namespace Utopia\VCS;

use Exception;

abstract class Adapter
{
    public const CLONE_TYPE_BRANCH = 'branch';
    public const CLONE_TYPE_TAG = 'tag';
    public const CLONE_TYPE_COMMIT = 'commit';

    public const METHOD_GET = 'GET';
    public const METHOD_POST = 'POST';
    public const METHOD_PUT = 'PUT';
    public const METHOD_PATCH = 'PATCH';
    public const METHOD_DELETE = 'DELETE';
    public const METHOD_HEAD = 'HEAD';
    public const METHOD_OPTIONS = 'OPTIONS';
    public const METHOD_CONNECT = 'CONNECT';
    public const METHOD_TRACE = 'TRACE';

    public const TYPE_GIT = 'git';
    public const TYPE_SVN = 'svn';

    public const WEBHOOK_SCOPE_INSTALLATION = 'installation';
    public const WEBHOOK_SCOPE_REPOSITORY = 'repository';

    protected bool $selfSigned = true;

    protected string $endpoint;

    /**
     * Global Headers
     *
     * @var array<string, string>
     */
    protected $headers = [];

    /**
     * Get Adapter Name
     */
    abstract public function getName(): string;

    /**
     * Get Adapter Type
     */
    abstract public function getType(): string;

    /**
     * Initialize Variables
     */
    abstract public function initializeVariables(string $installationId, string $privateKey, ?string $appId = null, ?string $accessToken = null, ?string $refreshToken = null): void;

    /**
     * Generate Access Token
     */
    abstract protected function generateAccessToken(string $privateKey, string $appId): void;

    /**
     * Get user
     *
     * @return array<mixed>
     *
     */
    abstract public function getUser(string $username): array;

    /**
     * Get owner name of the installation
     *
     * For GitHub: Uses installationId to identify the GitHub App installation
     * For Gitea: Requires repositoryId since OAuth tokens can access multiple organizations
     *
     * @param string $installationId Installation ID (GitHub) or empty string (Gitea)
     * @param int|null $repositoryId Repository ID (required for Gitea, ignored by GitHub)
     * @return string Owner login/username
     */
    abstract public function getOwnerName(string $installationId, ?int $repositoryId = null): string;

    /**
     * Determines whether the installation has access to all repositories or specific repositories
     *
     * @return bool True if installation has access to all repositories, false if it has access to specific repositories
     *
     * @throws Exception
     */
    abstract public function hasAccessToAllRepositories(): bool;

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
    abstract public function searchRepositories(string $owner, int $page, int $per_page, string $search = ''): array;

    /**
     * Get repository for the installation
     *
     * @return array<mixed>
     */
    abstract public function getInstallationRepository(string $repositoryName): array;

    /**
     * Get repository
     *
     * @return array<mixed>
     */
    abstract public function getRepository(string $owner, string $repositoryName): array;

    /**
     * Create new repository
     *
     * @return array<mixed> Details of new repository
     */
    abstract public function createRepository(string $owner, string $repositoryName, bool $private): array;

    /**
     * Delete repository
     */
    abstract public function deleteRepository(string $owner, string $repositoryName): bool;

    /**
     * Get latest opened pull request with specific base branch
     * @return array<mixed>
     */
    abstract public function getPullRequestFromBranch(string $owner, string $repositoryName, string $branch): array;

    /**
     * Get Pull Request
     *
     * @return array<mixed> The retrieved pull request
     */
    abstract public function getPullRequest(string $owner, string $repositoryName, int $pullRequestNumber): array;

    /**
     * Get files changed in a pull request
     *
     * @param string $owner Owner name of the repository
     * @param string $repositoryName Name of the repository
     * @param int $pullRequestNumber The pull request number
     * @return array<mixed> List of files changed in the pull request
     */
    abstract public function getPullRequestFiles(string $owner, string $repositoryName, int $pullRequestNumber): array;

    /**
     * Add Comment to Pull Request
     */
    abstract public function createComment(string $owner, string $repositoryName, int $pullRequestNumber, string $comment): string;

    /**
     * Get Comment of Pull Request
     *
     * @param string $owner       The owner of the repository
     * @param string $repositoryName    The name of the repository
     * @param string $commentId   The ID of the comment to retrieve
     * @return string              The retrieved comment
     */
    abstract public function getComment(string $owner, string $repositoryName, string $commentId): string;

    /**
     * Update Pull Request Comment
     *
     * @param string $owner      The owner of the repository
     * @param string $repositoryName   The name of the repository
     * @param string $commentId  The ID of the comment to update
     * @param string $comment    The updated comment content
     * @return string            The ID of the updated comment
     */
    abstract public function updateComment(string $owner, string $repositoryName, string $commentId, string $comment): string;

    /**
     * Generates a clone command using app access token
     */
    abstract public function generateCloneCommand(string $owner, string $repositoryName, string $version, string $versionType, string $directory, string $rootDirectory): string;

    /**
     * Validates a webhook payload signature.
     *
     * Default covers unprefixed HMAC-SHA256, the scheme most providers use.
     * Override for providers with a different scheme (a prefixed digest, a
     * plain secret-token compare, etc).
     *
     * @param  string  $payload Raw body of HTTP request
     * @param  string  $signature Signature provided by Git provider in header
     * @param  string  $signatureKey Webhook secret configured on Git provider
     */
    public function validateWebhookEvent(string $payload, string $signature, string $signatureKey): bool
    {
        $expected = hash_hmac('sha256', $payload, $signatureKey);

        return hash_equals($expected, $signature);
    }

    /**
     * Parses a webhook delivery into the events it describes.
     *
     * A delivery usually describes one event, but some providers batch
     * several into one -- Bitbucket reports every ref a push touched.
     *
     * @param string $event Type of event: push, pull_request etc
     * @param string $payload The webhook payload received from Git provider
     * @return array<array<mixed>> Parsed payloads as json objects
     */
    abstract public function getEvents(string $event, string $payload): array;

    /**
     * HTTP header name carrying the webhook event type (e.g. 'x-github-event').
     */
    abstract public function getEventHeaderName(): string;

    /**
     * HTTP header name carrying webhook verification data. Usually an HMAC
     * signature of the payload (e.g. 'x-hub-signature-256'), but some
     * providers (e.g. GitLab's 'x-gitlab-token') send a plain shared secret
     * instead — check validateWebhookEvent()'s implementation per adapter
     * before assuming uniform HMAC comparison.
     */
    abstract public function getSignatureHeaderName(): string;

    /**
     * Webhook scopes this adapter can deliver events through.
     *
     * WEBHOOK_SCOPE_INSTALLATION: events arrive platform-wide once the
     * integration itself is installed (e.g. a GitHub App) -- no per-repository
     * registration needed.
     * WEBHOOK_SCOPE_REPOSITORY: createWebhook() must be called on each
     * individual repository to receive its events.
     *
     * An adapter may support more than one scope. Consumers that only need
     * one webhook per connected repository should prefer
     * WEBHOOK_SCOPE_INSTALLATION when present.
     *
     * @return array<string>
     */
    abstract public function getSupportedWebhookScopes(): array;

    /**
     * Browser-facing URL for a repository's home page.
     */
    abstract public function getRepositoryUrl(string $owner, string $repositoryName): string;

    /**
     * Browser-facing URL for an owner's home page: a user, organization or group.
     *
     * Concrete rather than abstract so an adapter defined outside this package
     * keeps loading; every adapter here overrides it.
     */
    public function getOrganizationUrl(string $owner): string
    {
        throw new Exception('getOrganizationUrl() is not supported by ' . $this->getName());
    }

    /**
     * Browser-facing URL for a branch within a repository.
     */
    abstract public function getBranchUrl(string $owner, string $repositoryName, string $branch): string;

    /**
     * Browser-facing URL for a commit within a repository.
     */
    abstract public function getCommitUrl(string $owner, string $repositoryName, string $commitHash): string;

    /**
     * Browser-facing URL for a file at a given ref within a repository.
     */
    abstract public function getFileUrl(string $owner, string $repositoryName, string $reference): string;

    /**
     * Fetches repository name using repository id
     *
     * @param string $repositoryId ID of the repository
     * @return string name of the repository
     */
    abstract public function getRepositoryName(string $repositoryId): string;

    /**
     * Lists branches for a given repository
     *
     * @param string $owner Owner name of the repository
     * @param string $repositoryName Name of the repository
     * @return array<string> List of branch names as array
     */
    abstract public function listBranches(string $owner, string $repositoryName): array;

    /**
     * Lists tags for a given repository, optionally filtered by a glob pattern.
     *
     * @param string $owner Owner name of the repository
     * @param string $repositoryName Name of the repository
     * @param string $search Glob pattern (e.g. 'v1.*'); empty returns all tags
     * @return array<string> List of tag names as array
     */
    abstract public function listTags(string $owner, string $repositoryName, string $search = ''): array;

    /**
     * Updates status check of each commit
     * state can be one of: error, failure, pending, success
     */
    abstract public function updateCommitStatus(string $repositoryName, string $SHA, string $owner, string $state, string $description = '', string $target_url = '', string $context = ''): void;

    /**
     * Creates a check run for a commit.
     * status can be one of: queued, in_progress
     * Use updateCheckRun() to set conclusion and mark the run as completed.
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
        throw new \Exception('createCheckRun() is not implemented for ' . $this->getName());
    }

    /**
     * Gets a check run by ID.
     *
     * @return array<mixed>
     */
    public function getCheckRun(string $owner, string $repositoryName, string $checkRunId): array
    {
        throw new \Exception('getCheckRun() is not implemented for ' . $this->getName());
    }

    /**
     * Updates an existing check run.
     * status can be one of: queued, in_progress, completed
     * conclusion (required when status=completed) can be one of: action_required, cancelled, failure, neutral, success, skipped, timed_out
     *
     * @param array<mixed> $annotations
     * @param array<mixed> $images
     * @param array<mixed> $actions
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
        throw new \Exception('updateCheckRun() is not implemented for ' . $this->getName());
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
    abstract public function getRepositoryTree(string $owner, string $repositoryName, string $branch, bool $recursive = false): array;

    /**
     * Get repository languages
     *
     * @param string $owner Owner name of the repository
     * @param string $repositoryName Name of the repository
     * @return array<mixed> List of repository languages
     */
    abstract public function listRepositoryLanguages(string $owner, string $repositoryName): array;

    /**
     * List contents of the specified root directory.
     *
     * @param  string  $owner Owner name of the repository
     * @param  string  $repositoryName Name of the repository
     * @param  string  $path Path to list contents from
     * @param  string  $ref The name of the commit/branch/tag
     * @return array<mixed> List of contents at the specified path
     */
    abstract public function listRepositoryContents(string $owner, string $repositoryName, string $path = '', string $ref = ''): array;

    /**
     * Get contents of the specified file.
     *
     * @param  string  $owner Owner name
     * @param  string  $repositoryName Name of the repository
     * @param  string  $path Path to the file
     * @param  string  $ref The name of the commit/branch/tag
     * @return array<string, mixed> File details
     */
    abstract public function getRepositoryContent(string $owner, string $repositoryName, string $path, string $ref = ''): array;

    /**
     * Get details of a commit using commit hash
     *
     * @param  string  $owner Owner name of the repository
     * @param  string  $repositoryName Name of the GitHub repository
     * @param  string  $commitHash SHA of the commit
     * @return array<mixed> Details of the commit
     */
    abstract public function getCommit(string $owner, string $repositoryName, string $commitHash): array;

    /**
     * Get latest commit of a branch
     *
     * @param  string  $owner Owner name of the repository
     * @param  string  $repositoryName Name of the GitHub repository
     * @param  string  $branch Name of the branch
     * @return array<mixed> Details of the commit
     */
    abstract public function getLatestCommit(string $owner, string $repositoryName, string $branch): array;

    /**
     * Get a short-lived presigned URL to download the repository archive.
     *
     * @param  string  $owner Owner name of the repository
     * @param  string  $repositoryName Name of the repository
     * @param  string  $ref Branch, tag or commit to download (defaults to the default branch)
     * @param  string  $format Archive format, e.g. 'tarball' or 'zipball'
     * @return string Presigned download URL
     *
     * @throws Exception when the adapter does not implement it (opt-in, mirrors createCheckRun())
     */
    public function getRepositoryPresignedUrl(string $owner, string $repositoryName, string $ref = '', string $format = 'tarball'): string
    {
        throw new Exception('getRepositoryPresignedUrl() is not implemented for ' . $this->getName());
    }

    /**
     * Call
     *
     * Make an API call
     *
     * @param  array<mixed>  $params
     * @param  array<string, string>  $headers
     * @param  bool  $followRedirects When false, a redirect response is returned as-is instead of being followed
     * @return array<mixed>
     *
     * @throws Exception
     */
    protected function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, bool $followRedirects = true)
    {
        $headers = array_merge($this->headers, $headers);
        $ch = curl_init($this->endpoint . $path . (($method === self::METHOD_GET && $params !== []) ? '?' . http_build_query($params) : ''));

        if (!$ch) {
            throw new Exception('Curl failed to initialize');
        }

        $responseHeaders = [];
        $responseStatus = -1;
        $responseType = '';
        $responseBody = '';

        $query = match ($headers['content-type']) {
            // An empty body must encode as an object - some APIs (e.g.
            // Origin's proto3-JSON endpoints) reject a bare array
            'application/json' => $params === [] ? '{}' : json_encode($params),
            'multipart/form-data' => $this->flatten($params),
            'application/graphql' => $params[0],
            default => http_build_query($params),
        };

        $headerLines = [];
        foreach ($headers as $name => $header) {
            $headerLines[] = $name . ':' . $header;
        }

        curl_setopt($ch, CURLOPT_PATH_AS_IS, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $followRedirects);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.77 Safari/537.36');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, string $header) use (&$responseHeaders): int {
            $length = \strlen($header);
            $parts = explode(':', $header, 2);

            if (\count($parts) < 2) { // ignore invalid headers
                return $length;
            }

            $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);

            return $length;
        });

        if ($method !== self::METHOD_GET) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        }

        // Allow self signed certificates
        if ($this->selfSigned) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        $responseBody = curl_exec($ch) ?: '';

        if ($responseBody === true) {
            $responseBody = '';
        }

        $responseType = $responseHeaders['content-type'] ?? '';
        $responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($decode) {
            $length = strpos($responseType, ';') ?: \strlen($responseType);
            if (substr($responseType, 0, $length) === 'application/json') {
                $json = json_decode($responseBody, true);
                if ($json === null) {
                    throw new Exception('Failed to parse response: ' . $responseBody);
                }
                $responseBody = $json;
                $json = null;
            }
        }

        if ((curl_errno($ch) !== 0/* || 200 != $responseStatus*/)) {
            throw new Exception(curl_error($ch) . ' with status code ' . $responseStatus, $responseStatus);
        }

        $responseHeaders['status-code'] = $responseStatus;

        if ($responseStatus === 500) {
            echo 'Server error(' . $method . ': ' . $path . '. Params: ' . json_encode($params) . '): ' . json_encode($responseBody) . "\n";
        }

        return [
            'headers' => $responseHeaders,
            'body' => $responseBody,
        ];
    }

    /**
     * Flatten params array to PHP multiple format
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    protected function flatten(array $data, string $prefix = ''): array
    {
        $output = [];

        foreach ($data as $key => $value) {
            $finalKey = $prefix !== '' && $prefix !== '0' ? "{$prefix}[{$key}]" : $key;

            if (\is_array($value)) {
                $output += $this->flatten($value, $finalKey); // @todo: handle name collision here if needed
            } else {
                $output[$finalKey] = $value;
            }
        }

        return $output;
    }
}
