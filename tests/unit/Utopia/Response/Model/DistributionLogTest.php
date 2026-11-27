<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Model;

use Appwrite\Utopia\Response\Model\DistributionLog;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class DistributionLogTest extends TestCase
{
    public function testSequenceIsAString(): void
    {
        $log = (new DistributionLog())->filter(new Document([
            'sequence' => 1739871234567890123,
        ]));

        $this->assertSame('1739871234567890123', $log->getAttribute('sequence'));
    }

    public function testMissingSequenceStaysUnset(): void
    {
        $log = (new DistributionLog())->filter(new Document([]));

        $this->assertFalse($log->isSet('sequence'));
    }
}
