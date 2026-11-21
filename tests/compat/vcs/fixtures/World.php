<?php

namespace Tests\Compat\Vcs\Fixtures;

/**
 * One emulated provider instance: its accounts, repositories (bare git
 * repositories on disk plus what the API keeps beside them) and the
 * requests it received. Every case namespace gets worlds of its own.
 */
final class World
{
    /** Every timestamp the emulator reports. */
    public const string TIME = '2026-01-01T00:00:00Z';

    private int $next = 1;

    /** @var array<string, Repository> by lowercase "owner/name" */
    public array $repositories = [];

    /** @var array<string, array<string, mixed>> organizations by lowercase name */
    public array $organizations = [];

    /** @var array<string, array<string, mixed>> users by lowercase login */
    public array $users = [];

    /** @var array<string, mixed> what a case configured: keys, installations */
    public array $config = [];

    public function __construct(
        public readonly string $ns,
        public readonly string $provider,
        public readonly string $root,
    ) {
    }

    /** The next id of anything. */
    public function id(): int
    {
        return $this->next++;
    }

    public function repository(string $owner, string $name): ?Repository
    {
        return $this->repositories[strtolower($owner . '/' . $name)] ?? null;
    }

    public function repositoryById(int|string $id): ?Repository
    {
        foreach ($this->repositories as $repository) {
            if ((string) $repository->id === (string) $id || $repository->uuid === (string) $id) {
                return $repository;
            }
        }

        return null;
    }

    public function create(string $owner, string $name, bool $private, string $defaultBranch): Repository
    {
        $repository = new Repository(
            $this->id(),
            $owner,
            $name,
            $private,
            $defaultBranch,
            $this->root . '/' . strtolower($owner) . '/' . strtolower($name) . '.git',
        );
        Git::init($repository->path, $defaultBranch);
        $this->repositories[strtolower($owner . '/' . $name)] = $repository;

        return $repository;
    }

    public function delete(Repository $repository): void
    {
        unset($this->repositories[strtolower($repository->owner . '/' . $repository->name)]);
        exec('rm -rf ' . escapeshellarg($repository->path));
    }

    /**
     * The token's account, created on first use.
     *
     * @return array<string, mixed>
     */
    public function user(string $login): array
    {
        $key = strtolower($login);
        if (!isset($this->users[$key])) {
            $this->users[$key] = ['id' => $this->id(), 'login' => $login, 'email' => $login . '@example.com'];
        }

        return $this->users[$key];
    }
}

/**
 * A repository: its bare git repository and what the provider keeps beside
 * it (hooks, pull requests, comments, statuses, check runs).
 */
final class Repository
{
    public string $uuid;

    public string $description = '';

    /** @var array<int, array<string, mixed>> */
    public array $hooks = [];

    /** @var array<int, array<string, mixed>> by number */
    public array $pulls = [];

    /** @var array<int, array<string, mixed>> by id */
    public array $comments = [];

    /** @var array<string, array<array<string, mixed>>> by commit */
    public array $statuses = [];

    /** @var array<string, array<string, mixed>> by id */
    public array $checkRuns = [];

    /** @var array<string, array<string, mixed>> by id */
    public array $checkSuites = [];

    public int $nextPull = 1;

    public function __construct(
        public int $id,
        public string $owner,
        public string $name,
        public bool $private,
        public string $defaultBranch,
        public string $path,
    ) {
        $this->uuid = \sprintf('{%08d-0000-4000-8000-%012d}', $id, $id);
    }

    public function fullName(): string
    {
        return $this->owner . '/' . $this->name;
    }

    /** Whether the repository has a commit yet. */
    public function empty(): bool
    {
        return Git::branches($this->path) === [];
    }

    /** The default branch's tip, or null. */
    public function head(): ?string
    {
        return Git::resolve($this->path, 'refs/heads/' . $this->defaultBranch);
    }

    /** A commit by branch, tag or hash, or null. */
    public function resolve(string $ref): ?string
    {
        foreach (['refs/heads/' . $ref, 'refs/tags/' . $ref] as $candidate) {
            $sha = Git::resolve($this->path, $candidate);
            if ($sha !== null) {
                return $sha;
            }
        }
        if (preg_match('/^[0-9a-f]{4,40}$/', $ref) === 1) {
            return Git::resolve($this->path, $ref);
        }

        return null;
    }

    /**
     * The languages of the default branch by bytes, largest first.
     *
     * @return array<string, int>
     */
    public function languages(): array
    {
        $head = $this->head();
        if ($head === null) {
            return [];
        }
        $map = ['php' => 'PHP', 'js' => 'JavaScript', 'ts' => 'TypeScript', 'py' => 'Python', 'go' => 'Go', 'rs' => 'Rust', 'rb' => 'Ruby', 'java' => 'Java', 'css' => 'CSS', 'html' => 'HTML'];
        $languages = [];
        foreach (Git::tree($this->path, $head, '', true) ?? [] as $entry) {
            if ($entry['type'] !== 'blob') {
                continue;
            }
            $extension = strtolower(pathinfo($entry['path'], PATHINFO_EXTENSION));
            if (isset($map[$extension])) {
                $languages[$map[$extension]] = ($languages[$map[$extension]] ?? 0) + $entry['size'];
            }
        }
        arsort($languages);

        return $languages;
    }
}
