<?php

namespace Appwrite\Alert\Probe;

use Appwrite\Alert\Probe;
use Utopia\Client\Adapter\SwooleCoroutine\Client as SwooleAdapter;
use Utopia\Client\Client;
use Utopia\Client\Exception\ConnectionException;
use Utopia\Client\Exception\DestinationException;
use Utopia\Client\Exception\DnsException;
use Utopia\Client\Exception\TimeoutException;
use Utopia\Client\Exception\TlsException;
use Utopia\Database\Document;
use Utopia\Psr7\Header;
use Utopia\Psr7\Request\Factory as RequestFactory;
use Utopia\Psr7\Stream\Factory as StreamFactory;
use Utopia\Psr7\Uri;
use Utopia\System\System;

/**
 * Requests the monitor's URL, following redirects, and passes when the final
 * status is one it expects (any 2xx by default). The body is discarded as it
 * arrives, never held. Over HTTPS it also reads when the certificate expires,
 * and fails once that is fewer than the monitor's `certificateDays` away.
 */
final readonly class HTTP implements Probe
{
    public const array METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    private const int REDIRECTS = 10;

    public function check(Document $monitor, Egress $egress): Check
    {
        $timeout = (int) $monitor->getAttribute('timeout', 10);
        $deadline = \microtime(true) + $timeout;
        $url = (string) $monitor->getAttribute('url');

        $request = (new RequestFactory())->createRequest((string) $monitor->getAttribute('method', 'GET'), $url);
        // Stored as a JSON object of header names and values.
        foreach (\json_decode((string) $monitor->getAttribute('headers', ''), true) ?: [] as $name => $value) {
            $request = $request->withHeader((string) $name, (string) $value);
        }
        $body = (string) $monitor->getAttribute('body', '');
        if ($body !== '') {
            $request = $request->withBody((new StreamFactory())->createStream($body));
        }

        $client = (new Client(new SwooleAdapter(), $egress))
            ->withTimeout($timeout)
            ->withConnectTimeout($timeout)
            ->withFollowRedirects(true, self::REDIRECTS)
            ->withHeaders([Header::USER_AGENT => \sprintf(APP_USERAGENT, System::getEnv('_APP_VERSION', 'UNKNOWN'), System::getEnv('_APP_EMAIL_SECURITY', System::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS', APP_EMAIL_SECURITY)))]);

        $start = \hrtime(true);

        try {
            $response = $client->stream($request, static function (string $chunk): void {
            });
        } catch (\Throwable $error) {
            return new Check(match (true) {
                $error instanceof DestinationException => Result::TargetDenied,
                $error instanceof TlsException => Result::TlsFailed,
                $error instanceof DnsException => Result::DnsFailed,
                $error instanceof TimeoutException => Result::Timeout,
                $error instanceof ConnectionException && $error->getCode() === SOCKET_ECONNREFUSED => Result::ConnectRefused,
                default => Result::ConnectFailed,
            }, (int) \round((\hrtime(true) - $start) / 1e6));
        }

        $latency = (int) \round((\hrtime(true) - $start) / 1e6);
        $code = $response->getStatusCode();
        $expected = $monitor->getAttribute('statusCodes', []) ?: [];

        if ($expected === [] ? ($code < 200 || $code > 299) : !\in_array($code, \array_map('intval', $expected), true)) {
            return new Check(Result::StatusMismatch, $latency, $code);
        }

        $uri = Uri::parse($url);
        if ($uri->getScheme() !== 'https') {
            return new Check(Result::Ok, $latency, $code);
        }

        // The request already verified the chain; this reads the expiry of the certificate the host presents.
        try {
            $connection = Connection::open($uri->getHost(), $uri->getPort() ?? 443, true, false, $egress, $deadline);
            $expiry = $connection->certificateExpiresAt();
            $connection->close();
        } catch (Failure) {
            $expiry = null;
        }

        $days = (int) $monitor->getAttribute('certificateDays', 0);
        if ($days > 0 && $expiry !== null && $expiry->getTimestamp() - \time() < $days * 86400) {
            return new Check(Result::TlsExpiring, $latency, $code, $expiry);
        }

        return new Check(Result::Ok, $latency, $code, $expiry);
    }
}
