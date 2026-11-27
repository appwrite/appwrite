<?php

namespace Utopia\Detector\Detection\Mobile;

use Utopia\Detector\Detection\Mobile;

class Ios extends Mobile
{
    public function getName(): string
    {
        return 'ios';
    }

    /**
     * @return array<string>
     */
    public function getPlatforms(): array
    {
        return [self::PLATFORM_IOS];
    }

    /**
     * @return array<string>
     */
    public function getFiles(): array
    {
        return ['Podfile'];
    }

    /**
     * @return array<string>
     */
    public function getFileExtensions(): array
    {
        return ['xcodeproj', 'xcworkspace'];
    }
}
