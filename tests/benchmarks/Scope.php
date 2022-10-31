<?php

namespace Tests\Benchmarks;

use PhpBench\Attributes\AfterMethods;
use PhpBench\Attributes\BeforeMethods;
use Tests\E2E\Scopes\Scope as E2EScope;

#[BeforeMethods(['setUp'])]
#[AfterMethods(['tearDown'])]
abstract class Scope extends E2EScope
{
}
