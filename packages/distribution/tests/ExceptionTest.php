<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Distribution\Exception\NotFound;
use Utopia\Distribution\Exception\Unauthorized;
use Utopia\Distribution\Issue;

final class ExceptionTest extends TestCase
{
    public function testIssuesAndPreviousAreKept(): void
    {
        $issue = new Issue('ENTITY_ERROR.ATTRIBUTE.INVALID', 'The version is invalid', '/data/attributes/versionString');
        $previous = new \RuntimeException('cause');

        $exception = new NotFound('App not found', [$issue], $previous);

        $this->assertSame('App not found', $exception->getMessage());
        $this->assertSame([$issue], $exception->getIssues());
        $this->assertSame($previous, $exception->getPrevious());
        $this->assertNull($exception->getBody());
    }

    public function testBodyIsKept(): void
    {
        $body = '{"errors":[{"id":"0c5a5f53","code":"NOT_FOUND"}]}';

        $exception = new NotFound('App not found', body: $body);

        $this->assertSame($body, $exception->getBody());
    }

    public function testIssueReferenceDefaultsToNull(): void
    {
        $this->assertNull(new Issue('NOT_FOUND', 'Missing')->reference);
        $this->assertSame('0c5a5f53', new Issue('NOT_FOUND', 'Missing', reference: '0c5a5f53')->reference);
    }

    public function testUnauthorizedDefaultsToOperationNotCredential(): void
    {
        $this->assertFalse(new Unauthorized('Role too low')->isCredential());
    }

    public function testUnauthorizedMarksCredential(): void
    {
        $issue = new Issue('NOT_AUTHORIZED', 'Key revoked', manual: true);

        $exception = new Unauthorized('Key revoked', [$issue], credential: true);

        $this->assertTrue($exception->isCredential());
        $this->assertSame([$issue], $exception->getIssues());
    }

    public function testUnauthorizedPassesBodyToParent(): void
    {
        $previous = new \RuntimeException('cause');

        $exception = new Unauthorized('Key revoked', [], $previous, true, '{"error":"invalid_grant"}');

        $this->assertTrue($exception->isCredential());
        $this->assertSame($previous, $exception->getPrevious());
        $this->assertSame('{"error":"invalid_grant"}', $exception->getBody());
    }
}
