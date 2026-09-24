<?php

namespace Utopia\Detector\Detection\Packager;

use Utopia\Detector\Detection\Packager;

class PNPM extends Packager
{
    public function getName(): string
    {
        return 'pnpm';
    }

    /**
     * @return array<string>
     */
    public function getFiles(): array
    {
        return [
            'pnpm-lock.yaml',
        ];
    }
}
