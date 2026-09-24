<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\OAuth2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Auth\OAuth2\AuthorizationDetails;

final class AuthorizationDetailsTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, string, string, string, ?string, bool}>
     */
    public static function grants(): iterable
    {
        $project = [['type' => 'project', 'identifiers' => ['p1'], 'actions' => ['read']]];

        yield 'value listed in field' => [$project, 'project', 'p1', 'identifiers', null, true];
        yield 'value absent from field' => [$project, 'project', 'p2', 'identifiers', null, false];
        yield 'type must match' => [$project, 'organization', 'p1', 'identifiers', null, false];
        yield 'field must match' => [$project, 'project', 'p1', 'actions', null, false];
        yield 'other field matches' => [$project, 'project', 'read', 'actions', null, true];

        $wildcard = [['type' => 'project', 'identifiers' => ['*']]];
        yield 'wildcard matches any value when given' => [$wildcard, 'project', 'anything', 'identifiers', '*', true];
        yield 'wildcard ignored when not given' => [$wildcard, 'project', 'anything', 'identifiers', null, false];

        yield 'empty type' => [$project, '', 'p1', 'identifiers', null, false];
        yield 'empty value' => [$project, 'project', '', 'identifiers', null, false];
        yield 'empty field' => [$project, 'project', 'p1', '', null, false];

        yield 'null input' => [null, 'project', 'p1', 'identifiers', null, false];
        yield 'scalar input' => ['nonsense', 'project', 'p1', 'identifiers', null, false];

        // A JSON object decodes to an associative PHP array; it is not a list of
        // entries, and a field that is a map is not a list of values. Neither
        // may grant, at either level.
        yield 'associative entry collection ignored' => [['named' => ['type' => 'project', 'identifiers' => ['p1']]], 'project', 'p1', 'identifiers', null, false];
        yield 'associative field ignored' => [[['type' => 'project', 'identifiers' => ['alias' => 'p1']]], 'project', 'p1', 'identifiers', null, false];

        yield 'malformed entries ignored, valid entry honored' => [
            ['scalar', ['type' => 'project', 'identifiers' => 'not-a-list'], ['type' => 'project'], ['type' => 'project', 'identifiers' => ['p1']]],
            'project', 'p1', 'identifiers', null, true,
        ];

        // A numeric value in the field must not match the string lookup.
        yield 'match is type strict' => [[['type' => 'project', 'identifiers' => [1]]], 'project', '1', 'identifiers', null, false];
    }

    #[DataProvider('grants')]
    public function testGrants(mixed $raw, string $type, string $value, string $field, ?string $wildcard, bool $expected): void
    {
        $this->assertSame($expected, (new AuthorizationDetails($raw))->grants($type, $value, $field, $wildcard));
    }

    /**
     * @return iterable<string, array{mixed, list<array<string, mixed>>}>
     */
    public static function restricts(): iterable
    {
        yield 'values narrowed, other fields kept' => [
            [['type' => 'project', 'identifiers' => ['p1', 'p2'], 'actions' => ['read']]],
            [['type' => 'project', 'identifiers' => ['p1'], 'actions' => ['read']]],
        ];
        yield 'entry dropped when nothing is allowed' => [
            [['type' => 'project', 'identifiers' => ['p2']]],
            [],
        ];
        yield 'ungoverned type passes through, order preserved' => [
            [
                ['type' => 'organization', 'identifiers' => ['o1', 'o2']],
                ['type' => 'payment', 'actions' => ['initiate']],
                ['type' => 'project', 'identifiers' => ['p1', 'p2']],
            ],
            [
                ['type' => 'organization', 'identifiers' => ['o1']],
                ['type' => 'payment', 'actions' => ['initiate']],
                ['type' => 'project', 'identifiers' => ['p1']],
            ],
        ];
        yield 'missing or malformed field resolves as no values' => [
            [['type' => 'project'], ['type' => 'project', 'identifiers' => ['alias' => 'p1']]],
            [],
        ];
        yield 'non-string values are neither offered nor returned' => [
            [['type' => 'project', 'identifiers' => [1, 'p1']]],
            [['type' => 'project', 'identifiers' => ['p1']]],
        ];
    }

    /**
     * @param list<array<string, mixed>> $expected
     */
    #[DataProvider('restricts')]
    public function testRestrict(mixed $raw, array $expected): void
    {
        $resolver = fn(string $type, array $values): ?array => match ($type) {
            'project' => array_intersect($values, ['p1', 1]),
            'organization' => array_intersect($values, ['o1']),
            default => null,
        };

        $this->assertSame($expected, (new AuthorizationDetails($raw))->restrict('identifiers', $resolver)->toArray());
    }

    public function testRestrictDoesNotModifyTheReceiver(): void
    {
        $details = new AuthorizationDetails([['type' => 'project', 'identifiers' => ['p1', 'p2']]]);

        $restricted = $details->restrict('identifiers', fn(): array => ['p1']);

        $this->assertTrue($details->grants('project', 'p2', 'identifiers'));
        $this->assertFalse($restricted->grants('project', 'p2', 'identifiers'));
    }

    public function testRestrictCannotWidenTheGrant(): void
    {
        $details = new AuthorizationDetails([['type' => 'project', 'identifiers' => ['p2', 'p1']]]);

        $restricted = $details->restrict('identifiers', fn(): array => ['p1', 'p3', 'p2']);

        $this->assertSame([['type' => 'project', 'identifiers' => ['p2', 'p1']]], $restricted->toArray());
        $this->assertFalse($restricted->grants('project', 'p3', 'identifiers'));
    }

    public function testRestrictExpandsAWildcardFromTheResolver(): void
    {
        $details = new AuthorizationDetails([
            ['type' => 'project', 'identifiers' => ['*']],
            ['type' => 'organization', 'identifiers' => ['o1']],
        ]);

        $restricted = $details->restrict('identifiers', fn(): array => ['x1', 'x2'], '*');

        // The wildcard entry takes the resolver's expansion; the explicit entry still cannot widen.
        $this->assertSame([['type' => 'project', 'identifiers' => ['x1', 'x2']]], $restricted->toArray());
    }

    public function testRestrictDropsEntryWhenResolverReturnsOnlyUngrantedValues(): void
    {
        $details = new AuthorizationDetails([['type' => 'project', 'identifiers' => ['p1']]]);

        $this->assertSame([], $details->restrict('identifiers', fn(): array => ['p2'])->toArray());
    }
}
