<?php

namespace Tests\Query\Builder\Feature\ClickHouse;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\ClickHouse as Builder;
use Utopia\Query\Query;

class ArrayJoinsTest extends TestCase
{
    use AssertsBindingCount;

    public function testArrayJoinEmitsArrayJoinClauseAndQuotesColumn(): void
    {
        $result = (new Builder())
            ->from('events')
            ->arrayJoin('tags')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `events` ARRAY JOIN `tags`', $result->query);
    }

    public function testArrayJoinWithAliasQuotesBothColumnAndAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->arrayJoin('tags', 'tag')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `events` ARRAY JOIN `tags` AS `tag`', $result->query);
    }

    public function testLeftArrayJoinPrefixesLeft(): void
    {
        $result = (new Builder())
            ->from('events')
            ->leftArrayJoin('tags')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `events` LEFT ARRAY JOIN `tags`', $result->query);
    }

    public function testLeftArrayJoinWithAliasFormatsAsClause(): void
    {
        $result = (new Builder())
            ->from('events')
            ->leftArrayJoin('tags', 'tag')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `events` LEFT ARRAY JOIN `tags` AS `tag`', $result->query);
    }

    public function testArrayJoinWithEmptyAliasOmitsAsClause(): void
    {
        $result = (new Builder())
            ->from('events')
            ->arrayJoin('tags', '')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `events` ARRAY JOIN `tags`', $result->query);
        $this->assertStringNotContainsString('AS ``', $result->query);
    }

    public function testArrayJoinPrecedesWhereClause(): void
    {
        $result = (new Builder())
            ->from('events')
            ->arrayJoin('tags', 'tag')
            ->filter([Query::equal('tag', ['important'])])
            ->build();

        $this->assertBindingCount($result);
        $this->assertLessThan(\strpos($result->query, 'WHERE'), \strpos($result->query, 'ARRAY JOIN'));
        $this->assertSame(['important'], $result->bindings);
    }
}
