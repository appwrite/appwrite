<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\S3\Http;

use Appwrite\Platform\Modules\S3\Http\S3\Base;
use PHPUnit\Framework\TestCase;
use Utopia\System\System;

final class BucketFileSizeTest extends TestCase
{
    public function testABucketGetsThePlanPerFileLimit(): void
    {
        $this->assertSame(50 * 1000 * 1000, Base::maximumFileSize(['fileSize' => 50]));
        $this->assertSame(5000 * 1000 * 1000, Base::maximumFileSize(['fileSize' => 5000]));
    }

    public function testWithoutAPlanLimitABucketGetsThePlatformLimit(): void
    {
        $platform = (int) System::getEnv('_APP_STORAGE_LIMIT', '0');

        $this->assertSame($platform, Base::maximumFileSize([]));
        $this->assertSame($platform, Base::maximumFileSize(['fileSize' => 0]));
    }
}
