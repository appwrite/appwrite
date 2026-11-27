<?php

namespace Utopia\Detector\Detection\Mobile;

use Utopia\Detector\Detection\Mobile;

class Android extends Mobile
{
    public function getName(): string
    {
        return 'android';
    }

    /**
     * @return array<string>
     */
    public function getPlatforms(): array
    {
        return [self::PLATFORM_ANDROID];
    }

    /**
     * @return array<string>
     */
    public function getPlugins(): array
    {
        return ['com.android.application'];
    }
}
