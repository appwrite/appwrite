<?php

namespace Utopia\Detector\Detection\Packager;

use Utopia\Detector\Detection\Packager;

class Yarn extends Packager
{
    public function getName(): string
    {
        return 'yarn';
    }

    /**
     * @return array<string>
     */
    public function getFiles(): array
    {
        return ['yarn.lock'];
    }
}
