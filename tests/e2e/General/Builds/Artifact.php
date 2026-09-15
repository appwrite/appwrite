<?php

declare(strict_types=1);

namespace Tests\E2E\General\Builds;

use Utopia\Storage\Device\Local;

final class Artifact extends Local
{
    public function __construct(private readonly \Closure $beforeSize)
    {
        parent::__construct(sys_get_temp_dir());
    }

    public function getFileSize(string $path): int
    {
        ($this->beforeSize)();

        return parent::getFileSize($path);
    }
}
