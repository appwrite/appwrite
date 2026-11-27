<?php

namespace Appwrite\Distribution\Build;

use Utopia\Database\Document;
use Utopia\Validator\Contains;
use Utopia\Validator\Globstar;

readonly class Push
{
    public const string TAGS = 'refs/tags/';

    private const int MESSAGE_LENGTH = 255;

    private const array OWN_EMAILS = [
        APP_VCS_GITHUB_EMAIL,
        APP_VCS_GITEA_EMAIL,
        APP_VCS_GITLAB_EMAIL,
        APP_VCS_BITBUCKET_EMAIL,
    ];

    /**
     * @param list<string> $affectedFiles
     */
    public function __construct(
        public string $reference,
        public string $commitHash,
        public string $commitMessage,
        public string $authorName,
        public string $authorEmail,
        public string $authorUrl,
        public string $commitUrl,
        public string $branchUrl,
        public bool $deleted,
        public array $affectedFiles,
    ) {
    }

    /**
     * @param array<string, mixed> $parsedPayload
     */
    public static function fromPayload(array $parsedPayload): self
    {
        $files = $parsedPayload['affectedFiles'] ?? [];

        return new self(
            reference: (string) ($parsedPayload['branch'] ?? ''),
            commitHash: (string) ($parsedPayload['commitHash'] ?? ''),
            commitMessage: (string) ($parsedPayload['headCommitMessage'] ?? ''),
            authorName: (string) ($parsedPayload['headCommitAuthorName'] ?? ''),
            authorEmail: (string) ($parsedPayload['headCommitAuthorEmail'] ?? ''),
            authorUrl: (string) ($parsedPayload['authorUrl'] ?? ''),
            commitUrl: (string) ($parsedPayload['headCommitUrl'] ?? ''),
            branchUrl: (string) ($parsedPayload['branchUrl'] ?? ''),
            deleted: (bool) ($parsedPayload['branchDeleted'] ?? false),
            affectedFiles: \is_array($files) ? \array_values(\array_map(static fn (mixed $file): string => (string) $file, $files)) : [],
        );
    }

    /**
     * The same push with the commit the provider reports for its reference, which a forged delivery cannot vary.
     */
    public function withCommit(string $commitHash, string $commitMessage): self
    {
        return new self(
            reference: $this->reference,
            commitHash: $commitHash,
            commitMessage: $commitMessage,
            authorName: $this->authorName,
            authorEmail: $this->authorEmail,
            authorUrl: $this->authorUrl,
            commitUrl: $this->commitUrl,
            branchUrl: $this->branchUrl,
            deleted: $this->deleted,
            affectedFiles: $this->affectedFiles,
        );
    }

    public function isTag(): bool
    {
        return \str_starts_with($this->reference, self::TAGS);
    }

    public function name(): string
    {
        return $this->isTag() ? \substr($this->reference, \strlen(self::TAGS)) : $this->reference;
    }

    /**
     * Deterministic, so a redelivered webhook maps to the same build.
     */
    public function buildId(Document $target): string
    {
        return 'b' . \substr(\hash('sha256', "{$target->getId()}:vcs:{$this->reference}:{$this->commitHash}"), 0, 35);
    }

    public function skip(Document $app, Document $repository): ?string
    {
        return match (true) {
            $app->isEmpty() => 'app missing',
            $app->getAttribute('repositoryId', '') !== $repository->getId() => 'repository not connected',
            $this->deleted => 'deleted',
            \in_array($this->authorEmail, self::OWN_EMAILS, true) => 'own commit',
            $app->getAttribute('enabled', true) === false => 'disabled',
            $app->getAttribute('providerDeployments', true) === false => 'deployments',
            (new Contains(VCS_DEPLOYMENT_SKIP_PATTERNS))->isValid($this->commitMessage) => 'skip pattern',
            $this->isTag() && !$this->matchesTag($app) => 'tag',
            !$this->isTag() && !$this->matchesBranch($app) => 'branch',
            !$this->matchesPaths($app) => 'paths',
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $tag = $this->isTag();

        return [
            'providerBranch' => $tag ? '' : $this->name(),
            'providerTag' => $tag ? $this->name() : '',
            'providerBranchUrl' => $tag ? '' : $this->branchUrl,
            'providerCommitHash' => $this->commitHash,
            'providerCommitMessage' => \mb_strimwidth($this->commitMessage, 0, self::MESSAGE_LENGTH, '...'),
            'providerCommitAuthor' => $this->authorName,
            'providerCommitAuthorUrl' => $this->authorUrl,
            'providerCommitUrl' => $this->commitUrl,
        ];
    }

    private function matchesTag(Document $app): bool
    {
        $tags = $app->getAttribute('providerTags', []);

        return !empty($tags) && (new Globstar($tags))->isValid($this->name());
    }

    /**
     * Unlike Functions and Sites, an empty list builds only the production branch: every build may be signed and shipped.
     */
    private function matchesBranch(Document $app): bool
    {
        $branches = $app->getAttribute('providerBranches', []);

        if (empty($branches)) {
            return $this->name() === $app->getAttribute('providerBranch', '');
        }

        return (new Globstar($branches))->isValid($this->name());
    }

    private function matchesPaths(Document $app): bool
    {
        $paths = $app->getAttribute('providerPaths', []);

        if (empty($paths) || empty($this->affectedFiles)) {
            return true;
        }

        $trigger = new Globstar($paths);

        foreach ($this->affectedFiles as $file) {
            if ($trigger->isValid($file)) {
                return true;
            }
        }

        return false;
    }
}
