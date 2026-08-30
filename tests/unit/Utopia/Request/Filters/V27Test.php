<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filters\V27;
use PHPUnit\Framework\TestCase;

final class V27Test extends TestCase
{
    public function testSplitsLegacyMigrationResource(): void
    {
        $filter = new V27();

        $result = $filter->parse([
            'resourceId' => 'database:collection',
        ], 'migrations.createCSVImport');

        $this->assertSame([
            'databaseId' => 'database',
            'collectionId' => 'collection',
        ], $result);
    }

    public function testExplicitResourceIdsTakePrecedence(): void
    {
        $filter = new V27();

        $result = $filter->parse([
            'resourceId' => 'legacyDatabase:legacyCollection',
            'databaseId' => 'database',
            'collectionId' => 'collection',
        ], 'migrations.createJSONExport');

        $this->assertSame([
            'databaseId' => 'database',
            'collectionId' => 'collection',
        ], $result);
    }

    public function testRewritesLegacyRecentKeyword(): void
    {
        $filter = new V27();

        $result = $filter->parse([
            'sessionId' => 'recent',
            'duration' => 900,
        ], 'users.createJWT');

        $this->assertSame([
            'sessionId' => 'recent()',
            'duration' => 900,
        ], $result);
    }

    public function testLeavesParenthesisedRecentKeywordAlone(): void
    {
        $filter = new V27();

        $result = $filter->parse(['sessionId' => 'recent()'], 'users.createJWT');

        $this->assertSame(['sessionId' => 'recent()'], $result);
    }

    /**
     * Only the exact legacy word is a keyword; anything else is a session ID
     * and must reach the endpoint untouched — including a session literally
     * named with the keyword as a prefix.
     */
    public function testLeavesSessionIdsAlone(): void
    {
        $filter = new V27();

        foreach (['recently', 'recent-1', 'RECENT', 'abc123'] as $sessionId) {
            $this->assertSame(
                ['sessionId' => $sessionId],
                $filter->parse(['sessionId' => $sessionId], 'users.createJWT')
            );
        }
    }

    public function testLeavesAbsentSessionIdAlone(): void
    {
        $filter = new V27();

        $this->assertSame(['duration' => 900], $filter->parse(['duration' => 900], 'users.createJWT'));
    }

    public function testDoesNotRewriteKeywordForOtherMethods(): void
    {
        $filter = new V27();

        $this->assertSame(
            ['sessionId' => 'recent'],
            $filter->parse(['sessionId' => 'recent'], 'account.getSession')
        );
    }

    public function testLeavesMalformedResourceForValidation(): void
    {
        $filter = new V27();
        $content = ['resourceId' => 'database'];

        $this->assertSame($content, $filter->parse($content, 'migrations.createCSVExport'));
    }
}
