<?php

declare(strict_types=1);

namespace Tests\Unit\Extend;

use Appwrite\Extend\Exception;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Utopia\Cache\Exception\Unavailable;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Exception\Timeout;
use Utopia\Http\Exception as HttpException;
use Utopia\VCS\Exception\OwnerNotFound;

final class ExceptionTest extends TestCase
{
    /**
     * @return Iterator<string, array{OwnerNotFound}>
     */
    public static function missingInstallations(): Iterator
    {
        yield 'with not found code' => [new OwnerNotFound('Installation not found', 404)];
        yield 'without code' => [new OwnerNotFound('Installation not found')];
    }

    #[DataProvider('missingInstallations')]
    public function testMissingVcsInstallationIsInstallationNotFound(OwnerNotFound $error): void
    {
        $exception = Exception::tryFrom($error);

        $this->assertInstanceOf(Exception::class, $exception);
        $this->assertSame(Exception::INSTALLATION_NOT_FOUND, $exception->getType());
        $this->assertSame(404, $exception->getCode());
        $this->assertFalse($exception->isPublishable());
        $this->assertSame($error, $exception->getPrevious());
    }

    public function testAuthorizationIsUserUnauthorized(): void
    {
        $exception = Exception::tryFrom(new Authorization('No permission'));

        $this->assertInstanceOf(Exception::class, $exception);
        $this->assertSame(Exception::USER_UNAUTHORIZED, $exception->getType());
        $this->assertSame(401, $exception->getCode());
        $this->assertNull($exception->getPrevious());
    }

    public function testTimeoutIsDatabaseTimeout(): void
    {
        $error = new Timeout('Query took too long');
        $exception = Exception::tryFrom($error);

        $this->assertInstanceOf(Exception::class, $exception);
        $this->assertSame(Exception::DATABASE_TIMEOUT, $exception->getType());
        $this->assertSame($error, $exception->getPrevious());
    }

    public function testCacheUnavailableIsGeneralCacheUnavailable(): void
    {
        $error = new Unavailable('Cache is down');
        $exception = Exception::tryFrom($error);

        $this->assertInstanceOf(Exception::class, $exception);
        $this->assertSame(Exception::GENERAL_CACHE_UNAVAILABLE, $exception->getType());
        $this->assertSame($error, $exception->getPrevious());
    }

    public function testStructureIsArgumentInvalidWithOriginalMessage(): void
    {
        $error = new Structure('Invalid document structure: Missing required attribute "name"');
        $exception = Exception::tryFrom($error);

        $this->assertInstanceOf(Exception::class, $exception);
        $this->assertSame(Exception::GENERAL_ARGUMENT_INVALID, $exception->getType());
        $this->assertSame(400, $exception->getCode());
        $this->assertSame($error->getMessage(), $exception->getMessage());
        $this->assertSame($error, $exception->getPrevious());
    }

    /**
     * @return Iterator<string, array{int, string}>
     */
    public static function httpErrors(): Iterator
    {
        yield 'not found' => [404, Exception::GENERAL_ROUTE_NOT_FOUND];
        yield 'bad request' => [400, Exception::GENERAL_ARGUMENT_INVALID];
        yield 'other' => [405, Exception::GENERAL_UNKNOWN];
    }

    #[DataProvider('httpErrors')]
    public function testHttpErrorKeepsItsMessageAndCode(int $code, string $type): void
    {
        $error = new HttpException('Route not matched', $code);
        $exception = Exception::tryFrom($error);

        $this->assertInstanceOf(Exception::class, $exception);
        $this->assertSame($type, $exception->getType());
        $this->assertSame($code, $exception->getCode());
        $this->assertSame('Route not matched', $exception->getMessage());
        $this->assertSame($error, $exception->getPrevious());
    }

    public function testUnknownErrorIsNotMapped(): void
    {
        $this->assertNull(Exception::tryFrom(new RuntimeException('Unexpected')));
    }

    public function testAppwriteExceptionIsNotMapped(): void
    {
        $this->assertNull(Exception::tryFrom(new Exception(Exception::GENERAL_UNKNOWN)));
    }

    public function testSubclassOfMappedErrorIsNotMapped(): void
    {
        $this->assertNull(Exception::tryFrom(new class ('Query took too long') extends Timeout {}));
    }
}
