<?php

namespace Utopia\Detector\Detection\Mobile;

use Utopia\Detector\Detection\Mobile;

class Flutter extends Mobile
{
    public function getName(): string
    {
        return 'flutter';
    }

    /**
     * @return array<string>
     */
    public function getPlatforms(): array
    {
        return [self::PLATFORM_ANDROID, self::PLATFORM_IOS];
    }

    /**
     * @return array<string>
     */
    public function getPackages(): array
    {
        return ['flutter'];
    }
}
