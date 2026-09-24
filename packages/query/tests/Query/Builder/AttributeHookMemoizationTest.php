<?php

namespace Tests\Query\Builder;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Builder\MySQL;
use Utopia\Query\Hook\Attribute;
use Utopia\Query\Query;

/**
 * Verifies that resolveAttribute() memoizes results within a single build()
 * call so each attribute is resolved at most once per build, no matter how
 * many clauses (SELECT, WHERE, GROUP BY, ORDER BY, JOIN ON, UPDATE SET)
 * reference it.
 */
class AttributeHookMemoizationTest extends TestCase
{
    public function testAttributeResolvedAtMostOncePerBuild(): void
    {
        $hook = new class () implements Attribute {
            /** @var array<string, int> */
            public array $calls = [];

            public function resolve(string $attribute): string
            {
                $this->calls[$attribute] = ($this->calls[$attribute] ?? 0) + 1;

                return $attribute;
            }
        };

        $builder = new MySQL();
        $builder
            ->from('users')
            ->addHook($hook)
            ->queries([
                Query::select(['id', 'name', 'age']),
                Query::equal('name', ['alice']),
                Query::greaterThan('age', 18),
                Query::orderAsc('name'),
                Query::orderDesc('age'),
            ])
            ->build();

        // Every attribute referenced should have been resolved exactly once,
        // even though `name` and `age` appear in SELECT, WHERE, and ORDER BY.
        foreach ($hook->calls as $attribute => $count) {
            $this->assertSame(
                1,
                $count,
                \sprintf('Attribute %s was resolved %d times (expected 1)', $attribute, $count),
            );
        }

        $this->assertNotEmpty($hook->calls, 'Hook should have been invoked at least once');
    }

    public function testMemoClearedBetweenBuilds(): void
    {
        $hook = new class () implements Attribute {
            public int $calls = 0;

            public function resolve(string $attribute): string
            {
                $this->calls++;

                return $attribute;
            }
        };

        $builder = new MySQL();
        $builder
            ->from('users')
            ->addHook($hook)
            ->queries([
                Query::select(['name']),
                Query::equal('name', ['alice']),
            ])
            ->build();

        $firstCalls = $hook->calls;
        $this->assertGreaterThan(0, $firstCalls);

        // Second build with the same builder must re-resolve (memo cleared).
        $builder->build();
        $this->assertSame(
            $firstCalls * 2,
            $hook->calls,
            'Memo should be cleared between builds — second build must re-resolve.',
        );
    }

    public function testMemoClearedOnReset(): void
    {
        $hook = new class () implements Attribute {
            public int $calls = 0;

            public function resolve(string $attribute): string
            {
                $this->calls++;

                return $attribute;
            }
        };

        $builder = new MySQL();
        $builder
            ->from('users')
            ->addHook($hook)
            ->queries([Query::select(['name'])])
            ->build();

        $firstBuildCalls = $hook->calls;
        $this->assertGreaterThan(0, $firstBuildCalls);

        // reset() preserves addHook registrations (user-installed infra) but
        // must clear the memo so a fresh build re-resolves attributes rather
        // than returning stale entries.
        $builder->reset();
        $builder
            ->from('users')
            ->queries([Query::select(['name'])])
            ->build();

        $this->assertGreaterThan(
            $firstBuildCalls,
            $hook->calls,
            'reset() must clear the memo so the next build re-resolves attributes.',
        );
    }
}
