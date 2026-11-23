<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Build;

use Appwrite\Distribution\Build\Status;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatusTest extends TestCase
{
    /**
     * @return \Iterator<string, array{Status, Status}>
     */
    public static function allowed(): \Iterator
    {
        yield 'waiting to processing' => [Status::Waiting, Status::Processing];
        yield 'waiting to building' => [Status::Waiting, Status::Building];
        yield 'waiting to signing' => [Status::Waiting, Status::Signing];
        yield 'waiting to ready' => [Status::Waiting, Status::Ready];
        yield 'waiting to failed' => [Status::Waiting, Status::Failed];
        yield 'waiting to canceled' => [Status::Waiting, Status::Canceled];
        yield 'processing to waiting' => [Status::Processing, Status::Waiting];
        yield 'processing to building' => [Status::Processing, Status::Building];
        yield 'processing to signing' => [Status::Processing, Status::Signing];
        yield 'processing to ready' => [Status::Processing, Status::Ready];
        yield 'processing to failed' => [Status::Processing, Status::Failed];
        yield 'processing to canceled' => [Status::Processing, Status::Canceled];
        yield 'building to waiting' => [Status::Building, Status::Waiting];
        yield 'building to signing' => [Status::Building, Status::Signing];
        yield 'building to ready' => [Status::Building, Status::Ready];
        yield 'building to failed' => [Status::Building, Status::Failed];
        yield 'building to canceled' => [Status::Building, Status::Canceled];
        yield 'signing to waiting' => [Status::Signing, Status::Waiting];
        yield 'signing to ready' => [Status::Signing, Status::Ready];
        yield 'signing to failed' => [Status::Signing, Status::Failed];
        yield 'signing to canceled' => [Status::Signing, Status::Canceled];
    }

    /**
     * @return \Iterator<string, array{Status, Status}>
     */
    public static function denied(): \Iterator
    {
        yield 'ready to waiting' => [Status::Ready, Status::Waiting];
        yield 'ready to processing' => [Status::Ready, Status::Processing];
        yield 'ready to failed' => [Status::Ready, Status::Failed];
        yield 'ready to canceled' => [Status::Ready, Status::Canceled];
        yield 'failed to waiting' => [Status::Failed, Status::Waiting];
        yield 'failed to processing' => [Status::Failed, Status::Processing];
        yield 'failed to ready' => [Status::Failed, Status::Ready];
        yield 'failed to canceled' => [Status::Failed, Status::Canceled];
        yield 'canceled to waiting' => [Status::Canceled, Status::Waiting];
        yield 'canceled to building' => [Status::Canceled, Status::Building];
        yield 'canceled to ready' => [Status::Canceled, Status::Ready];
        yield 'canceled to failed' => [Status::Canceled, Status::Failed];
        yield 'waiting to waiting' => [Status::Waiting, Status::Waiting];
        yield 'processing to processing' => [Status::Processing, Status::Processing];
        yield 'building to building' => [Status::Building, Status::Building];
        yield 'signing to signing' => [Status::Signing, Status::Signing];
        yield 'ready to ready' => [Status::Ready, Status::Ready];
        yield 'building to processing' => [Status::Building, Status::Processing];
        yield 'signing to processing' => [Status::Signing, Status::Processing];
        yield 'signing to building' => [Status::Signing, Status::Building];
    }

    #[DataProvider('allowed')]
    public function testAllowsTransition(Status $from, Status $to): void
    {
        $this->assertTrue($from->canTransition($to));
    }

    #[DataProvider('denied')]
    public function testDeniesTransition(Status $from, Status $to): void
    {
        $this->assertFalse($from->canTransition($to));
    }

    public function testAllowsNoUnlistedTransition(): void
    {
        $count = 0;
        foreach (Status::cases() as $from) {
            foreach (Status::cases() as $to) {
                $count += (int) $from->canTransition($to);
            }
        }

        $this->assertSame(\iterator_count(self::allowed()), $count);
    }

    public function testValues(): void
    {
        $this->assertSame(['waiting', 'processing', 'building', 'signing', 'ready', 'failed', 'canceled'], Status::values());
    }
}
