<?php

namespace Utopia\Detector\Detection\Packager;

use Utopia\Detector\Detection\Packager;

class NPM extends Packager
{
    public function getName(): string
    {
        return 'npm';
    }

    /**
     * @return array<string>
     */
    public function getFiles(): array
    {
        return ['package.json', 'package-lock.json'];
    }
}
