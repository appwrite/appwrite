<?php

namespace Utopia\System;

use Utopia\Tests\CgroupMemoryTest;

function php_uname(string $mode): string
{
    return 'Linux';
}

function is_readable(string $filename): bool
{
    return \array_key_exists($filename, CgroupMemoryTest::$files);
}

function file_get_contents(string $filename): string|false
{
    return CgroupMemoryTest::$files[$filename] ?? false;
}
