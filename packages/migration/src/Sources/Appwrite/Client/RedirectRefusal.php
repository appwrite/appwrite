<?php

namespace Utopia\Migration\Sources\Appwrite\Client;

use Override;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client\Decorator;
use Utopia\Client\Exception\RequestException;

final class RedirectRefusal extends Decorator
{
    #[Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->refuse($request, $this->adapter->sendRequest($request));
    }

    #[Override]
    public function stream(RequestInterface $request, callable $sink): ResponseInterface
    {
        return $this->refuse($request, $this->adapter->stream($request, $sink));
    }

    private function refuse(RequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $status = $response->getStatusCode();

        if ($status >= 300 && $status < 400) {
            throw new RequestException($request, $status.': Redirects are not followed', $status);
        }

        return $response;
    }
}
