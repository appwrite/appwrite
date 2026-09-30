<?php

declare(strict_types=1);

namespace Utopia\VCS\Adapter;

use Exception;
use Utopia\Cache\Cache;
use Utopia\Command;
use Utopia\VCS\Adapter;

abstract class Git extends Adapter
{
    protected string $endpoint;

    protected string $accessToken;

    /**
     * Global Headers
     *
     * @var array<string, string>
     */
    protected $headers = ['content-type' => 'application/json'];

    public function __construct(protected Cache $cache)
    {
    }

    /**
     * Get Adapter Type
     */
    public function getType(): string
    {
        return self::TYPE_GIT;
    }

    /**
     * Create a file in a repository
     *
     * @param string $owner Owner of the repository
     * @param string $repositoryName Name of the repository
     * @param string $filepath Path where file should be created
     * @param string $content Content of the file
     * @param string $message Commit message
     * @return array<mixed> Response from API
     */
    abstract public function createFile(string $owner, string $repositoryName, string $filepath, string $content, string $message = 'Add file', string $branch = ''): array;

    /**
     * Create a branch in a repository
     *
     * @param string $owner Owner of the repository
     * @param string $repositoryName Name of the repository
     * @param string $newBranchName Name of the new branch
     * @param string $oldBranchName Name of the branch to branch from
     * @return array<mixed> Response from API
     */
    abstract public function createBranch(string $owner, string $repositoryName, string $newBranchName, string $oldBranchName): array;

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
    abstract public function createPullRequest(string $owner, string $repositoryName, string $title, string $head, string $base, string $body = ''): array;

    /**
     * Create a webhook on a repository
     *
     * @param string $owner Owner of the repository
     * @param string $repositoryName Name of the repository
     * @param string $url Webhook URL to send events to
     * @param string $secret Webhook secret for signature validation
     * @param array<string> $events Events to trigger the webhook
     * @return int|string Webhook ID, as the provider identifies it: an int on
     *                    the providers that number their hooks, a string where
     *                    they don't (Bitbucket identifies them by UUID)
     */
    abstract public function createWebhook(string $owner, string $repositoryName, string $url, string $secret, array $events = ['push', 'pull_request']): int|string;


    /**
     * Create a tag in a repository
     *
     * @param string $owner Owner of the repository
     * @param string $repositoryName Name of the repository
     * @param string $tagName Name of the tag (e.g., 'v1.0.0')
     * @param string $target Target commit SHA or branch name
     * @param string $message Tag message (optional)
     * @return array<mixed> Created tag details
     */
    abstract public function createTag(string $owner, string $repositoryName, string $tagName, string $target, string $message = ''): array;

    /**
     * Whether the provider lets this integration create repositories. Some
     * partner APIs reserve creation for user principals.
     */
    public function supportsRepositoryCreation(): bool
    {
        return true;
    }

    /**
     * Whether the provider exposes an endpoint to delete a repository.
     */
    public function supportsRepositoryDeletion(): bool
    {
        return true;
    }

    /**
     * Whether the provider can hand out an archive download URL at all, so a
     * consumer can arrange its own source packaging before calling
     * getRepositoryPresignedUrl() just to catch it throwing.
     */
    public function supportsRepositoryArchives(): bool
    {
        return true;
    }

    /**
     * Git HTTPS URL of a repository, for a consumer that clones instead of
     * downloading an archive. Carries no credentials - they ride the headers
     * from getRepositoryCloneHeaders(), never a string that errors and logs
     * echo verbatim.
     */
    public function getRepositoryCloneUrl(string $owner, string $repositoryName): string
    {
        throw new Exception('getRepositoryCloneUrl() is not supported by ' . $this->getName());
    }

    /**
     * Headers authenticating a fetch of getRepositoryCloneUrl(), typically
     * Authorization. Empty when the repository needs none.
     *
     * @return array<string, string>
     */
    public function getRepositoryCloneHeaders(): array
    {
        return [];
    }

    /**
     * Whether images embedded in pull request comments can render on the
     * provider. Providers without an image proxy (the way GitHub rewrites
     * comment images through its camo CDN) cannot display images hosted on
     * the consumer's own host - often private or plain-HTTP - so consumers
     * should fall back to text there.
     */
    public function supportsCommentImages(): bool
    {
        return true;
    }

    /**
     * Whether the provider can host repositories that anonymous clients are
     * able to read. Providers that scope every repository to an
     * authenticated audience have no public repositories, whatever a
     * visibility flag may claim.
     */
    public function supportsPublicRepositories(): bool
    {
        return true;
    }

    /**
     * Headers a caller must send with getRepositoryPresignedUrl() to reach a
     * private repository.
     *
     * @return array<string, string>
     */
    public function getRepositoryPresignedUrlHeaders(): array
    {
        return [];
    }

    /**
     * Get a short-lived URL to download the repository archive.
     *
     * Not every provider offers one, so the default reports it as unsupported
     * rather than forcing an implementation.
     *
     * @param string $owner Owner of the repository
     * @param string $repositoryName Name of the repository
     * @param string $ref Branch, tag or commit to archive
     * @param string $format Either 'tarball' or 'zipball'
     */
    public function getRepositoryPresignedUrl(string $owner, string $repositoryName, string $ref = '', string $format = 'tarball'): string
    {
        throw new Exception('getRepositoryPresignedUrl() is not supported by ' . $this->getName());
    }

    /**
     * Create a check run for a commit.
     *
     * Only some providers model checks separately from commit statuses, so the
     * default reports it as unsupported.
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
        throw new Exception('createCheckRun() is not supported by ' . $this->getName());
    }

    /**
     * Get a check run by id.
     *
     * @return array<mixed>
     */
    public function getCheckRun(string $owner, string $repositoryName, string $checkRunId): array
    {
        throw new Exception('getCheckRun() is not supported by ' . $this->getName());
    }

    /**
     * Update a check run.
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
        throw new Exception('updateCheckRun() is not supported by ' . $this->getName());
    }

    /**
     * List namespaces the credentials can create repositories in.
     *
     * Only some providers model namespaces separately, so the default reports
     * it as unsupported.
     *
     * @return array{items: array<array<string, mixed>>, total: int}
     */
    public function listNamespaces(int $page, int $per_page, string $search = ''): array
    {
        throw new Exception('listNamespaces() is not supported by ' . $this->getName());
    }

    /**
     * Get commit statuses
     *
     * Every adapter reports each status as
     * ['state' => string, 'description' => string, 'target_url' => string, 'context' => string].
     *
     * @param string $owner Owner of the repository
     * @param string $repositoryName Name of the repository
     * @param string $commitHash SHA of the commit
     * @return array<mixed> List of commit statuses
     */
    abstract public function getCommitStatuses(string $owner, string $repositoryName, string $commitHash): array;

    /**
     * Sparse, shallow clone of one ref into $directory, checking out only
     * $rootDirectory. Every value reaches git as its own argument; the one
     * shell script, the branch fallback, is constant and reads its values
     * from positional parameters.
     */
    protected function cloneCommand(string $cloneUrl, string $version, string $versionType, string $directory, string $rootDirectory): Command
    {
        $rootDirectory = $this->normalizeRepositoryPath($rootDirectory);
        if ($rootDirectory === '') {
            $rootDirectory = '*';
        }

        // A leading dash would reach git as an option instead of a ref
        $ref = fn (string $value): bool => !str_starts_with($value, '-');

        $git = fn (): Command => (new Command('git'))->option('-C', $directory);

        $checkout = match ($versionType) {
            self::CLONE_TYPE_BRANCH => (new Command('sh'))
                ->flag('-c')
                ->argument('if git -C "$1" ls-remote --exit-code --heads origin "$2"; then git -C "$1" pull --depth=1 origin "$2" && git -C "$1" checkout "$2"; else git -C "$1" checkout -b "$2"; fi')
                ->argument('sh')
                ->argument($directory)
                ->argument($version, $ref),
            self::CLONE_TYPE_COMMIT => Command::and(
                $git()->argument('fetch')->option('--depth', '1')->argument('origin')->argument($version, $ref),
                $git()->argument('checkout')->argument($version, $ref),
            ),
            self::CLONE_TYPE_TAG => Command::and(
                $git()->argument('fetch')->option('--depth', '1')->argument('origin')->argument('refs/tags/' . $version),
                $git()->argument('checkout')->argument('FETCH_HEAD'),
            ),
            default => throw new Exception("Unsupported clone type: {$versionType}"),
        };

        return Command::and(
            (new Command('mkdir'))->flag('-p')->argument($directory),
            (new Command('git'))->argument('config')->flag('--global')->argument('init.defaultBranch')->argument('main'),
            $git()->argument('init'),
            $git()->argument('remote')->argument('add')->argument('origin')->argument($cloneUrl),
            $git()->argument('config')->argument('core.sparseCheckout')->argument('true'),
            Command::appendStdout(
                (new Command('printf'))->argument('%s\n')->argument($rootDirectory),
                $directory . '/.git/info/sparse-checkout',
            ),
            // Disable fetching of refs we don't need
            $git()->argument('config')->flag('--add')->argument('remote.origin.fetch')->argument('+refs/heads/*:refs/remotes/origin/*'),
            // Disable fetching of tags
            $git()->argument('config')->argument('remote.origin.tagopt')->argument('--no-tags'),
            $checkout,
        );
    }

    /**
     * Resolve the path sentinels a caller may pass - '', '.', './', 'src//' -
     * to the plain path every provider's API expects. Providers differ on
     * whether they do this themselves, so adapters normalize before calling.
     */
    protected function normalizeRepositoryPath(string $path): string
    {
        $segments = array_filter(
            explode('/', $path),
            fn (string $segment): bool => $segment !== '' && $segment !== '.',
        );

        return implode('/', $segments);
    }

    /**
     * Filter ref names by a shell glob pattern (e.g. 'v1.*', 'v?.0.0').
     * An empty pattern returns every name unchanged.
     *
     * @param array<string> $names
     * @return array<string>
     */
    protected function matchGlob(array $names, string $pattern): array
    {
        if ($pattern === '') {
            return array_values($names);
        }

        return array_values(array_filter($names, fn (string $name): bool => fnmatch($pattern, $name)));
    }
}
