<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Model;

use Appwrite\Utopia\Response\Model\DistributionRelease;
use PHPUnit\Framework\TestCase;

final class DistributionReleaseTest extends TestCase
{
    public function testListingSnapshotIsNotExposed(): void
    {
        $this->assertArrayNotHasKey('listing', (new DistributionRelease())->getRules());
    }
}
