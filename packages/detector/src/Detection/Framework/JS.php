<?php

namespace Utopia\Detector\Detection\Framework;

use Utopia\Detector\Detection\Framework;

abstract class JS extends Framework
{
    /**
     * @return array<string>
     */
    public function getPackages(): array
    {
        return [];
    }

    /**
     * @return array<string>
     */
    public function getFiles(): array
    {
        return ['package.json'];
    }
}
