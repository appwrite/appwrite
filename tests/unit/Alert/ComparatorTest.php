<?php

declare(strict_types=1);

namespace Tests\Unit\Alert;

use Appwrite\Alert\Comparator;
use Appwrite\Alert\Email;
use Appwrite\Alert\Quantity;
use Appwrite\Alert\Rules;
use Appwrite\Alert\Status;
use Appwrite\Alert\Wording;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Query;

final class ComparatorTest extends TestCase
{
    /**
     * @return \Iterator<string, array{Comparator, float, bool}>
     */
    public static function comparisons(): \Iterator
    {
        yield 'greater than, above' => [Comparator::GreaterThan, 11, true];
        yield 'greater than, equal' => [Comparator::GreaterThan, 10, false];
        yield 'greater than or equal, equal' => [Comparator::GreaterThanEqual, 10, true];
        yield 'greater than or equal, below' => [Comparator::GreaterThanEqual, 9, false];
        yield 'less than, below' => [Comparator::LessThan, 9, true];
        yield 'less than, equal' => [Comparator::LessThan, 10, false];
        yield 'less than or equal, equal' => [Comparator::LessThanEqual, 10, true];
        yield 'less than or equal, above' => [Comparator::LessThanEqual, 11, false];
    }

    #[DataProvider('comparisons')]
    public function testMatchesAgainstThreshold(Comparator $comparator, float $value, bool $expected): void
    {
        $this->assertSame($expected, $comparator->matches($value, 10));
    }

    /**
     * @return \Iterator<string, array{float, string, string}>
     */
    public static function quantities(): \Iterator
    {
        yield 'bytes below a kilobyte' => [999, 'bytes', '999 B'];
        yield 'bytes in decimal units' => [548730, 'bytes', '548.73 KB'];
        yield 'whole gigabytes' => [5_000_000_000, 'bytes', '5 GB'];
        yield 'percent' => [87.5, 'percent', '87.5%'];
        yield 'currency' => [1234.5, 'usd', '$1,234.50'];
        yield 'count with its unit' => [1_200_000, 'operations', '1,200,000 operations'];
        yield 'one of a count' => [1, 'requests', '1 request'];
        yield 'requests' => [25_000, 'requests', '25,000 requests'];
        yield 'executions' => [3, 'executions', '3 executions'];
        yield 'mb-seconds as gb-hours' => [9_000_000, 'mbSeconds', '2.5 GB-hours'];
        yield 'small gb-hours' => [1_000, 'mbSeconds', '0.0003 GB-hours'];
        yield 'users' => [1_500, 'users', '1,500 users'];
        yield 'otp attempts' => [12, 'attempts', '12 attempts'];
        yield 'messages' => [1, 'messages', '1 message'];
        yield 'webhook events' => [40, 'events', '40 events'];
        yield 'connections' => [342, 'connections', '342 connections'];
        yield 'screenshots' => [7, 'screenshots', '7 screenshots'];
    }

    #[DataProvider('quantities')]
    public function testQuantityReadsInItsUnit(float $amount, string $unit, string $expected): void
    {
        $this->assertSame($expected, (new Quantity($amount, $unit))->format());
    }

    public function testEmailSaysWhatCrossedWhichThreshold(): void
    {
        $email = fn (Status $status, ?int $window) => new Email(
            status: $status,
            rule: 'Egress spike',
            metric: 'Outbound bandwidth',
            place: 'Storefront',
            value: new Quantity(548730, 'bytes'),
            comparator: Comparator::GreaterThan,
            threshold: new Quantity(5000, 'bytes'),
            window: $window,
            changedAt: new \DateTimeImmutable('2026-10-29T11:54:00+00:00'),
            details: [],
            url: 'https://console.example/projects/storefront/usage/alerts?alert=egress',
            reason: '',
        );

        $firing = $email(Status::Firing, 3600);
        $this->assertSame('[Firing] Egress spike · Storefront', $firing->subject());
        $this->assertSame('Outbound bandwidth is above 5 KB', $firing->heading());
        $this->assertSame('Outbound bandwidth reached 548.73 KB in the last hour, 110× the 5 KB threshold.', $firing->summary());

        $resolved = $email(Status::Ok, 900);
        $this->assertSame('[Resolved] Egress spike · Storefront', $resolved->subject());
        $this->assertSame('Outbound bandwidth is no longer above 5 KB', $resolved->heading());
        $this->assertSame('Outbound bandwidth is back to 548.73 KB in the last 15 minutes, no longer above the 5 KB threshold.', $resolved->summary());

        // A gauge compares its latest reading, so no window is named.
        $this->assertSame('Outbound bandwidth reached 548.73 KB, 110× the 5 KB threshold.', $email(Status::Firing, null)->summary());
    }

    public function testEmailLinksItsUnsubscribeLinksOnlyWhenGiven(): void
    {
        $template = __DIR__ . '/../../../app/config/locale/templates/email-alert.tpl';
        $email = fn (string $unsubscribeUrl, string $unsubscribeAllUrl) => new Email(
            status: Status::Firing,
            rule: 'Egress spike',
            metric: 'Outbound bandwidth',
            place: 'Storefront',
            value: new Quantity(6_000, 'bytes'),
            comparator: Comparator::GreaterThan,
            threshold: new Quantity(5_000, 'bytes'),
            window: 3600,
            changedAt: new \DateTimeImmutable('2026-10-29T12:00:00+00:00'),
            details: [],
            url: 'https://console.example/alerts',
            reason: 'You\'re receiving this as an owner of Acme.',
            unsubscribeUrl: $unsubscribeUrl,
            unsubscribeAllUrl: $unsubscribeAllUrl,
        );

        $html = $email('https://console.example/alerts/unsubscribe?token=one&x=1', 'https://console.example/alerts/unsubscribe?token=all')->render($template);
        $this->assertStringContainsString('href="https://console.example/alerts/unsubscribe?token=one&amp;x=1"', $html);
        $this->assertStringContainsString('>Stop emails for this monitor</a>', $html);
        $this->assertStringContainsString('href="https://console.example/alerts/unsubscribe?token=all"', $html);
        $this->assertStringContainsString('>Stop all usage alert emails</a>', $html);

        $html = $email('', '')->render($template);
        $this->assertStringNotContainsString('Stop emails', $html);
        $this->assertStringNotContainsString('{{unsubscribe}}', $html);
    }

    public function testEmailWordingReplacesTheGeneratedCopy(): void
    {
        $email = new Email(
            status: Status::Firing,
            rule: 'Budget cap reached',
            metric: 'Budget used',
            place: 'Acme',
            value: new Quantity(100, 'percent'),
            comparator: Comparator::GreaterThanEqual,
            threshold: new Quantity(100, 'percent'),
            window: null,
            changedAt: new \DateTimeImmutable('2026-10-29T12:00:00+00:00'),
            details: [],
            url: 'https://console.example/organizations/acme/billing',
            reason: '',
            wording: new Wording(subject: 'You have reached the budget cap', action: 'Update budget cap'),
        );

        $this->assertSame('You have reached the budget cap', $email->subject());
        // Parts left out keep what the rule says.
        $this->assertSame('Budget used is at or above 100%', $email->heading());
        $this->assertStringContainsString('>Update budget cap</a>', $email->render(__DIR__ . '/../../../app/config/locale/templates/email-alert.tpl'));
        $this->assertStringNotContainsString('View alert', $email->render(__DIR__ . '/../../../app/config/locale/templates/email-alert.tpl'));

        $worded = new Email(
            status: Status::Firing,
            rule: 'Budget cap reached',
            metric: 'Budget used',
            place: 'Acme',
            value: new Quantity(100, 'percent'),
            comparator: Comparator::GreaterThanEqual,
            threshold: new Quantity(100, 'percent'),
            window: null,
            changedAt: new \DateTimeImmutable('2026-10-29T12:00:00+00:00'),
            details: [],
            url: 'https://console.example/organizations/acme/billing',
            reason: '',
            wording: new Wording(heading: 'You have reached your budget cap', summary: 'Projects in Acme are now limited.'),
        );
        $this->assertSame('You have reached your budget cap', $worded->heading());
        $this->assertSame('Projects in Acme are now limited.', $worded->summary());
        // The summary is the inbox preview, not repeated in the body.
        $this->assertStringNotContainsString('Projects in Acme are now limited.', $worded->render(__DIR__ . '/../../../app/config/locale/templates/email-alert.tpl'));
        $this->assertSame('[Firing] Budget cap reached · Acme', $worded->subject());
    }

    public function testEmailSaysHowLongAResolvedRuleFired(): void
    {
        $template = __DIR__ . '/../../../app/config/locale/templates/email-alert.tpl';
        $email = fn (Status $status, float $value) => new Email(
            status: $status,
            rule: 'Egress spike',
            metric: 'Outbound bandwidth',
            place: 'Storefront',
            value: new Quantity($value, 'bytes'),
            comparator: Comparator::GreaterThan,
            threshold: new Quantity(5000, 'bytes'),
            window: 3600,
            changedAt: new \DateTimeImmutable('2026-10-29T11:59:00+00:00'),
            details: [],
            url: 'https://console.example/projects/storefront/usage/alerts?alert=egress',
            reason: '',
            location: 'Storefront · Acme · Frankfurt',
            since: new \DateTimeImmutable('2026-10-29T09:45:00+00:00'),
            cooldown: 3600,
            pauseUrl: 'https://console.example/projects/storefront/usage/monitors?monitor=egress',
        );

        $resolved = $email(Status::Ok, 1200)->render($template);
        $this->assertStringContainsString('Firing for', $resolved);
        $this->assertStringContainsString('2 h 14 m', $resolved);
        $this->assertStringContainsString('09:45 to 11:59 UTC', $resolved);
        $this->assertStringNotContainsString('Pause this monitor', $resolved);

        // Just over the threshold reads as "above"; the ratio is for values well past it.
        $firing = $email(Status::Firing, 6000)->render($template);
        $this->assertStringContainsString('Above the threshold', $firing);
        $this->assertStringContainsString('Storefront · Acme · Frankfurt', $firing);
        $this->assertStringContainsString('On recovery, or in 1 hour if still firing', $firing);
        $this->assertStringContainsString('Pause this monitor', $firing);
        $this->assertStringContainsString('>Monitor</td>', $firing);
    }

    public function testRulesReadTheComparisonAndScope(): void
    {
        $rules = Rules::parse([
            Query::equal('resourceType', ['bucket'])->toString(),
            Query::greaterThanEqual('storage', 5000)->toString(),
            Query::equal('resourceId', ['images'])->toString(),
        ], self::REGISTRY);

        $this->assertSame('storage', $rules->metric);
        $this->assertSame(Comparator::GreaterThanEqual, $rules->comparator);
        $this->assertEqualsWithDelta(5000.0, $rules->threshold, PHP_FLOAT_EPSILON);
        $this->assertSame('bucket', $rules->resourceType);
        $this->assertSame('images', $rules->resourceId);
        // Normalized with the comparison first, as the console reads them back.
        $this->assertSame([
            '{"method":"greaterThanEqual","attribute":"storage","values":[5000]}',
            '{"method":"equal","attribute":"resourceType","values":["bucket"]}',
            '{"method":"equal","attribute":"resourceId","values":["images"]}',
        ], $rules->rules);
    }

    public function testRulesWithoutScopeWatchTheProject(): void
    {
        $rules = Rules::parse([Query::lessThan('network.outbound', 10)->toString()], self::REGISTRY);

        $this->assertSame('project', $rules->resourceType);
        $this->assertSame('', $rules->resourceId);
    }

    /**
     * @return \Iterator<string, array{list<string>, string}>
     */
    public static function invalidRules(): \Iterator
    {
        yield 'no comparison' => [[Query::equal('resourceType', ['bucket'])->toString()], 'needs one rule comparing a metric'];
        yield 'two comparisons' => [[Query::greaterThan('storage', 1)->toString(), Query::lessThan('storage', 9)->toString()], 'one metric comparison'];
        yield 'unknown metric' => [[Query::greaterThan('functions.executions', 1)->toString()], "'functions.executions', which is not a metric"];
        yield 'unsupported method' => [[Query::between('storage', 1, 2)->toString()], "uses 'between'"];
        yield 'not a number' => [[Query::greaterThan('storage', '5')->toString()], 'with one number'];
        yield 'not a query' => [['greaterThan(storage)'], 'Rule 0 is not a valid query'];
        yield 'scope not equal' => [[Query::greaterThan('storage', 1)->toString(), Query::notEqual('resourceType', 'bucket')->toString()], "must be equal('resourceType'"];
        yield 'id without type' => [[Query::greaterThan('storage', 1)->toString(), Query::equal('resourceId', ['images'])->toString()], "needs a rule on 'resourceType'"];
        yield 'unknown resource' => [[Query::greaterThan('storage', 1)->toString(), Query::equal('resourceType', ['site'])->toString()], "'site' cannot be monitored"];
        yield 'metric not on resource' => [[Query::greaterThan('network.outbound', 1)->toString(), Query::equal('resourceType', ['bucket'])->toString()], "not recorded for resource type 'bucket'"];
    }

    /**
     * @param list<string> $queries
     */
    #[DataProvider('invalidRules')]
    public function testRulesRefuseWhatAMonitorCannotRead(array $queries, string $reason): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);

        Rules::parse($queries, self::REGISTRY);
    }

    private const array REGISTRY = [
        'resources' => ['project' => [], 'bucket' => []],
        'metrics' => [
            'network.outbound' => ['resources' => ['project' => []]],
            'storage' => ['resources' => ['project' => [], 'bucket' => ['resourceType' => 'bucket', 'id' => 'resourceId']]],
        ],
    ];
}
