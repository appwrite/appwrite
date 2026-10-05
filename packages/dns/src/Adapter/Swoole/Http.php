<?php

namespace Utopia\DNS\Adapter\Swoole;

use Exception;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Server;
use Swoole\Server\Port;
use Utopia\DNS\Protocol;

/**
 * DNS over HTTPS transport per RFC 8484.
 *
 * Serves wire-format DNS messages via GET (`?dns=` base64url) and POST
 * (`application/dns-message` body). Leave $certPath null to serve plain
 * HTTP behind a TLS-terminating proxy.
 */
class Http extends Transport
{
    public const string CONTENT_TYPE = 'application/dns-message';

    /**
     * @param bool $trustProxy Report the client address from the
     *     X-Forwarded-For header set by a load balancer or reverse proxy
     */
    public function __construct(
        string $host = '0.0.0.0',
        int $port = 443,
        public readonly ?string $certPath = null,
        public readonly ?string $keyPath = null,
        public readonly bool $trustProxy = false,
    ) {
        if (($this->certPath === null) !== ($this->keyPath === null)) {
            throw new Exception('TLS requires both a certificate and a key path.');
        }

        parent::__construct($host, $port);
    }

    public function getSockType(): int
    {
        return $this->certPath !== null ? SWOOLE_SOCK_TCP | SWOOLE_SSL : SWOOLE_SOCK_TCP;
    }

    public function getSettings(): array
    {
        $settings = ['open_http_protocol' => true];

        if ($this->certPath !== null) {
            $settings['ssl_cert_file'] = $this->certPath;
            $settings['ssl_key_file'] = $this->keyPath;
            // RFC 8484 clients negotiate HTTP/2 over TLS via ALPN
            $settings['open_http2_protocol'] = true;
        }

        return $settings;
    }

    public function attach(Server|Port $target, callable $onPacket): void
    {
        $target->on('Request', function (Request $request, Response $response) use ($onPacket): void {
            $query = $this->readQuery($request);

            if ($query === null) {
                $response->status(400);
                $response->end();
                return;
            }

            $ip = \is_string($request->server['remote_addr'] ?? null) ? $request->server['remote_addr'] : '';
            $port = \is_int($request->server['remote_port'] ?? null) ? $request->server['remote_port'] : 0;

            if ($this->trustProxy) {
                $forwarded = $request->header['x-forwarded-for'] ?? null;
                if (\is_string($forwarded) && $forwarded !== '') {
                    // The last entry is the one appended by the trusted proxy
                    $entries = array_map(trim(...), explode(',', $forwarded));
                    $ip = end($entries);
                }
            }

            $answer = \call_user_func($onPacket, $query, $ip, $port, Protocol::Https);

            if ($answer === '') {
                $response->status(400);
                $response->end();
                return;
            }

            $response->header('Content-Type', self::CONTENT_TYPE);
            $response->end($answer);
        });
    }

    /**
     * Extract the wire-format DNS query from an RFC 8484 request.
     */
    protected function readQuery(Request $request): ?string
    {
        $method = \is_string($request->server['request_method'] ?? null) ? $request->server['request_method'] : '';

        if ($method === 'GET') {
            $dns = $request->get['dns'] ?? null;
            if (!\is_string($dns) || $dns === '') {
                return null;
            }

            $decoded = base64_decode(strtr($dns, '-_', '+/'), true);
            return $decoded === false || $decoded === '' ? null : $decoded;
        }

        if ($method === 'POST') {
            $contentType = $request->header['content-type'] ?? null;
            if (!\is_string($contentType) || strtolower(trim(explode(';', $contentType)[0])) !== self::CONTENT_TYPE) {
                return null;
            }

            $body = $request->rawContent();
            return \is_string($body) && $body !== '' ? $body : null;
        }

        return null;
    }
}
