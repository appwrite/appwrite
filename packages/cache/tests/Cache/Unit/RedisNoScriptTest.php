<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Redis\NoScript;

final class RedisNoScriptTest extends TestCase
{
    public function testMatchesWhenNoScriptIsTheLeadingCodeToken(): void
    {
        $this->assertTrue(NoScript::matches('NOSCRIPT No matching script. Please use EVAL.'));
        $this->assertTrue(NoScript::matches('NOSCRIPT'));
    }

    public function testDoesNotMatchWhenNoScriptIsNotTheCode(): void
    {
        // A substring check would wrongly trip on echoed key/value text or a
        // different error code — the code is only ever the leading token.
        $this->assertFalse(NoScript::matches('ERR user set key to "NOSCRIPT"'));
        $this->assertFalse(NoScript::matches('WRONGTYPE Operation against a key holding the wrong kind of value'));
        $this->assertFalse(NoScript::matches(''));
    }

    public function testFromExceptionPreservesMessageAndCause(): void
    {
        $cause = new \RedisException('NOSCRIPT No matching script. Please use EVAL.');
        $signal = NoScript::from($cause);

        $this->assertSame($cause->getMessage(), $signal->getMessage());
        $this->assertSame($cause, $signal->getPrevious());
    }

    public function testFromStringCarriesMessage(): void
    {
        $signal = NoScript::from('NOSCRIPT No matching script. Please use EVAL.');

        $this->assertSame('NOSCRIPT No matching script. Please use EVAL.', $signal->getMessage());
        $this->assertNotInstanceOf(\Throwable::class, $signal->getPrevious());
    }
}
