<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests;

use Psr\Http\Client\ClientExceptionInterface;

final class Failure extends \RuntimeException implements ClientExceptionInterface
{
}
