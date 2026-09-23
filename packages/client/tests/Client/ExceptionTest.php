<?php

declare(strict_types=1);

namespace Utopia\Tests\Client;

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Utopia\Client\Exception\AdapterInitializationException;
use Utopia\Client\Exception\AdapterPreconditionException;
use Utopia\Client\Exception\ConnectionException;
use Utopia\Client\Exception\DnsException;
use Utopia\Client\Exception\InvalidResponseException;
use Utopia\Client\Exception\InvalidUriException;
use Utopia\Client\Exception\NetworkException;
use Utopia\Client\Exception\ProtocolException;
use Utopia\Client\Exception\ProxyException;
use Utopia\Client\Exception\RequestException;
use Utopia\Client\Exception\TimeoutException;
use Utopia\Client\Exception\TlsException;

final class ExceptionTest extends TestCase
{
    public function testRequestExceptionsRemainPsrRequestExceptions(): void
    {
        $this->assertContains(RequestExceptionInterface::class, class_implements(RequestException::class));
        $this->assertContains(RequestExceptionInterface::class, class_implements(AdapterInitializationException::class));
        $this->assertContains(RequestExceptionInterface::class, class_implements(AdapterPreconditionException::class));
        $this->assertContains(RequestExceptionInterface::class, class_implements(InvalidResponseException::class));
        $this->assertContains(RequestExceptionInterface::class, class_implements(InvalidUriException::class));
    }

    public function testNetworkExceptionsRemainPsrNetworkExceptions(): void
    {
        $this->assertContains(NetworkExceptionInterface::class, class_implements(ConnectionException::class));
        $this->assertContains(NetworkExceptionInterface::class, class_implements(DnsException::class));
        $this->assertContains(NetworkExceptionInterface::class, class_implements(NetworkException::class));
        $this->assertContains(NetworkExceptionInterface::class, class_implements(ProtocolException::class));
        $this->assertContains(NetworkExceptionInterface::class, class_implements(ProxyException::class));
        $this->assertContains(NetworkExceptionInterface::class, class_implements(TlsException::class));
        $this->assertContains(NetworkExceptionInterface::class, class_implements(TimeoutException::class));
    }
}
