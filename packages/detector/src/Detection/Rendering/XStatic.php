<?php

namespace Utopia\Detector\Detection\Rendering;

use Utopia\Detector\Detection\Rendering;

class XStatic extends Rendering
{
    public function getName(): string
    {
        return 'static';
    }

    public function getFiles(string $framework): array
    {
        return [];
    }
}
