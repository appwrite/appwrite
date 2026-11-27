<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Build;

use Appwrite\Distribution\Build\Push;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

#[RequiresPhpExtension('utopia')]
final class PushTest extends TestCase
{
    private const string REPOSITORY = 'repository1';

    private const string HASH = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

    /**
     * @return \Iterator<string, array{array<string, mixed>, array<string, mixed>|null, string|null}>
     */
    public static function skips(): \Iterator
    {
        yield 'app missing' => [[], null, 'app missing'];
        yield 'stale link' => [[], ['repositoryId' => 'other'], 'repository not connected'];
        yield 'deleted before own commit' => [['branchDeleted' => true, 'headCommitAuthorEmail' => APP_VCS_GITHUB_EMAIL], [], 'deleted'];
        yield 'github own commit' => [['headCommitAuthorEmail' => APP_VCS_GITHUB_EMAIL], [], 'own commit'];
        yield 'gitea own commit' => [['headCommitAuthorEmail' => APP_VCS_GITEA_EMAIL], [], 'own commit'];
        yield 'gitlab own commit' => [['headCommitAuthorEmail' => APP_VCS_GITLAB_EMAIL], [], 'own commit'];
        yield 'bitbucket own commit' => [['headCommitAuthorEmail' => APP_VCS_BITBUCKET_EMAIL], [], 'own commit'];
        yield 'own commit before disabled' => [['headCommitAuthorEmail' => APP_VCS_GITHUB_EMAIL], ['enabled' => false], 'own commit'];
        yield 'disabled' => [[], ['enabled' => false], 'disabled'];
        yield 'deployments off' => [[], ['providerDeployments' => false], 'deployments'];
        yield 'skip appwrite' => [['headCommitMessage' => 'chore: bump [skip appwrite]'], [], 'skip pattern'];
        yield 'skip ci' => [['headCommitMessage' => 'docs: readme [skip ci]'], [], 'skip pattern'];
        yield 'skip pattern before branch' => [['branch' => 'feature', 'headCommitMessage' => '[skip ci]'], [], 'skip pattern'];
        yield 'empty tag list builds no tags' => [['branch' => 'refs/tags/v1.0.0'], [], 'tag'];
        yield 'unmatched tag' => [['branch' => 'refs/tags/nightly'], ['providerTags' => ['v*']], 'tag'];
        yield 'matched tag' => [['branch' => 'refs/tags/v1.0.0'], ['providerTags' => ['v*']], null];
        yield 'tag ignores the branch list' => [['branch' => 'refs/tags/v1.0.0'], ['providerTags' => ['v*'], 'providerBranches' => ['release/*']], null];
        yield 'empty branch list builds the production branch' => [['branch' => 'main'], [], null];
        yield 'empty branch list skips other branches' => [['branch' => 'feature'], [], 'branch'];
        yield 'release glob matches' => [['branch' => 'release/1.0'], ['providerBranches' => ['release/*']], null];
        yield 'release glob skips the production branch' => [['branch' => 'main'], ['providerBranches' => ['release/*']], 'branch'];
        yield 'branch before paths' => [['branch' => 'feature', 'affectedFiles' => ['docs/a.md']], ['providerPaths' => ['lib/**']], 'branch'];
        yield 'unmatched paths' => [['affectedFiles' => ['docs/a.md']], ['providerPaths' => ['lib/**']], 'paths'];
        yield 'matched paths' => [['affectedFiles' => ['docs/a.md', 'lib/main.dart']], ['providerPaths' => ['lib/**']], null];
        yield 'empty paths build every change' => [['affectedFiles' => ['docs/a.md']], [], null];
        yield 'no affected files ignores paths' => [['affectedFiles' => []], ['providerPaths' => ['lib/**']], null];
    }

    /**
     * @param array<string, mixed> $payload
     * @param ?array<string, mixed> $app null for an app that no longer exists
     */
    #[DataProvider('skips')]
    public function testSkip(array $payload, ?array $app, ?string $reason): void
    {
        $push = Push::fromPayload([...$this->payload(), ...$payload]);
        $app = $app === null ? new Document() : new Document([...$this->app(), ...$app]);

        $this->assertSame($reason, $push->skip($app, new Document(['$id' => self::REPOSITORY])));
    }

    public function testFromPayloadDefaults(): void
    {
        $push = Push::fromPayload([]);

        $this->assertSame('', $push->reference);
        $this->assertSame('', $push->commitHash);
        $this->assertSame('', $push->authorEmail);
        $this->assertFalse($push->deleted);
        $this->assertSame([], $push->affectedFiles);
    }

    public function testFromPayloadReadsTheParsedEvent(): void
    {
        $push = Push::fromPayload($this->payload());

        $this->assertSame('main', $push->reference);
        $this->assertSame(self::HASH, $push->commitHash);
        $this->assertSame('feat: add login', $push->commitMessage);
        $this->assertSame('Jane', $push->authorName);
        $this->assertSame('jane@example.com', $push->authorEmail);
        $this->assertSame('https://github.com/jane', $push->authorUrl);
        $this->assertSame('https://github.com/acme/app/commit/' . self::HASH, $push->commitUrl);
        $this->assertSame('https://github.com/acme/app/tree/main', $push->branchUrl);
        $this->assertSame(['lib/main.dart'], $push->affectedFiles);
    }

    public function testClassifiesReferences(): void
    {
        $tag = Push::fromPayload(['branch' => 'refs/tags/v1.2.3']);
        $branch = Push::fromPayload(['branch' => 'release/1.2']);

        $this->assertTrue($tag->isTag());
        $this->assertSame('v1.2.3', $tag->name());
        $this->assertFalse($branch->isTag());
        $this->assertSame('release/1.2', $branch->name());
    }

    public function testBuildIdIsStable(): void
    {
        $target = new Document(['$id' => 'target1']);
        $push = Push::fromPayload($this->payload());

        $id = $push->buildId($target);

        $this->assertSame($id, Push::fromPayload($this->payload())->buildId($target));
        $this->assertSame('b' . \substr(\hash('sha256', 'target1:vcs:main:' . self::HASH), 0, 35), $id);
        $this->assertSame(36, \strlen($id));
    }

    public function testBuildIdDiffersPerReferenceCommitAndTarget(): void
    {
        $target = new Document(['$id' => 'target1']);
        $id = Push::fromPayload($this->payload())->buildId($target);

        $this->assertNotSame($id, Push::fromPayload([...$this->payload(), 'branch' => 'refs/tags/main'])->buildId($target));
        $this->assertNotSame($id, Push::fromPayload([...$this->payload(), 'commitHash' => 'f' . \substr(self::HASH, 1)])->buildId($target));
        $this->assertNotSame($id, Push::fromPayload($this->payload())->buildId(new Document(['$id' => 'target2'])));
    }

    public function testBranchAttributes(): void
    {
        $attributes = Push::fromPayload($this->payload())->attributes();

        $this->assertSame([
            'providerBranch' => 'main',
            'providerTag' => '',
            'providerBranchUrl' => 'https://github.com/acme/app/tree/main',
            'providerCommitHash' => self::HASH,
            'providerCommitMessage' => 'feat: add login',
            'providerCommitAuthor' => 'Jane',
            'providerCommitAuthorUrl' => 'https://github.com/jane',
            'providerCommitUrl' => 'https://github.com/acme/app/commit/' . self::HASH,
        ], $attributes);
    }

    public function testTagAttributesUseTheResolvedCommit(): void
    {
        $resolved = \str_repeat('e', 40);
        $attributes = Push::fromPayload([...$this->payload(), 'branch' => 'refs/tags/v2.0.0'])->withCommit($resolved, 'release: v2.0.0')->attributes();

        $this->assertSame('', $attributes['providerBranch']);
        $this->assertSame('v2.0.0', $attributes['providerTag']);
        $this->assertSame('', $attributes['providerBranchUrl']);
        $this->assertSame($resolved, $attributes['providerCommitHash']);
        $this->assertSame('release: v2.0.0', $attributes['providerCommitMessage']);
    }

    public function testWithCommitReplacesOnlyTheCommit(): void
    {
        $resolved = \str_repeat('e', 40);
        $push = Push::fromPayload($this->payload());

        $verified = $push->withCommit($resolved, 'fix: verified');

        $this->assertSame($resolved, $verified->commitHash);
        $this->assertSame('fix: verified', $verified->commitMessage);
        $this->assertSame(self::HASH, $push->commitHash);
        $this->assertSame('feat: add login', $push->commitMessage);
        $this->assertSame($push->reference, $verified->reference);
        $this->assertSame($push->authorName, $verified->authorName);
        $this->assertSame($push->authorEmail, $verified->authorEmail);
        $this->assertSame($push->authorUrl, $verified->authorUrl);
        $this->assertSame($push->commitUrl, $verified->commitUrl);
        $this->assertSame($push->branchUrl, $verified->branchUrl);
        $this->assertSame($push->deleted, $verified->deleted);
        $this->assertSame($push->affectedFiles, $verified->affectedFiles);
        $this->assertNotSame($push->buildId(new Document(['$id' => 'target1'])), $verified->buildId(new Document(['$id' => 'target1'])));
    }

    public function testSkipPatternIsReadFromTheVerifiedMessage(): void
    {
        $app = new Document($this->app());
        $repository = new Document(['$id' => self::REPOSITORY]);
        $push = Push::fromPayload($this->payload());

        $this->assertNull($push->skip($app, $repository));
        $this->assertSame('skip pattern', $push->withCommit(self::HASH, 'docs: readme [skip ci]')->skip($app, $repository));
        $this->assertNull(Push::fromPayload([...$this->payload(), 'headCommitMessage' => '[skip ci]'])->withCommit(self::HASH, 'feat: add login')->skip($app, $repository));
    }

    public function testCommitMessageIsTruncated(): void
    {
        $attributes = Push::fromPayload([...$this->payload(), 'headCommitMessage' => \str_repeat('a', 300)])->attributes();

        $this->assertSame(255, \mb_strlen($attributes['providerCommitMessage']));
        $this->assertSame(\str_repeat('a', 252) . '...', $attributes['providerCommitMessage']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'branch' => 'main',
            'commitHash' => self::HASH,
            'headCommitMessage' => 'feat: add login',
            'headCommitAuthorName' => 'Jane',
            'headCommitAuthorEmail' => 'jane@example.com',
            'authorUrl' => 'https://github.com/jane',
            'headCommitUrl' => 'https://github.com/acme/app/commit/' . self::HASH,
            'branchUrl' => 'https://github.com/acme/app/tree/main',
            'branchDeleted' => false,
            'affectedFiles' => ['lib/main.dart'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function app(): array
    {
        return [
            '$id' => 'app1',
            'repositoryId' => self::REPOSITORY,
            'providerBranch' => 'main',
            'enabled' => true,
            'providerDeployments' => true,
            'providerBranches' => [],
            'providerTags' => [],
            'providerPaths' => [],
        ];
    }
}
