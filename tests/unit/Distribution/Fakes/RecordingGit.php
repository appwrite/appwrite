<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Fakes;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git\Gitea;

/**
 * A Git provider that hands out a fixed archive URL and records the commit checks it is sent.
 */
final class RecordingGit extends Gitea
{
    public const string ARCHIVE = 'https://git.example.test/archive/source.tar.gz';

    public const array HEADERS = ['authorization' => 'token repository-read-token'];

    /** @var list<array<string, string>> */
    public array $checks = [];

    /** @var list<array{string, string, string}> Owner, repository and reference */
    public array $archives = [];

    public function __construct(private bool $archived = true, private ?\Throwable $failure = null)
    {
        parent::__construct(new Cache(new None()));
    }

    #[\Override]
    public function supportsRepositoryArchives(): bool
    {
        return $this->archived;
    }

    #[\Override]
    public function getRepositoryPresignedUrl(string $owner, string $repositoryName, string $ref = '', string $format = 'tarball'): string
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->archives[] = [$owner, $repositoryName, $ref];

        return self::ARCHIVE;
    }

    #[\Override]
    public function getRepositoryPresignedUrlHeaders(): array
    {
        return self::HEADERS;
    }

    #[\Override]
    public function updateCheck(string $owner, string $repositoryName, string $SHA, string $state, string $name, string $title, string $summary = '', string $detailsUrl = '', string $suite = '', string $key = '', string $suiteKey = ''): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->checks[] = [
            'owner' => $owner,
            'repository' => $repositoryName,
            'commit' => $SHA,
            'state' => $state,
            'name' => $name,
            'title' => $title,
            'summary' => $summary,
            'detailsUrl' => $detailsUrl,
            'suite' => $suite,
            'key' => $key,
            'suiteKey' => $suiteKey,
        ];
    }
}
