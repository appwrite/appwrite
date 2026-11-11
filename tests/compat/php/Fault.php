<?php

namespace Tests\Compat;

/**
 * A harness problem (unknown operation, malformed arguments, missing
 * service), reported apart from library errors because it says nothing about
 * the library's behaviour.
 */
final class Fault extends \RuntimeException
{
}
