<?php

declare(strict_types=1);

namespace Utopia\Cdn\Aws;

use Psr\Http\Message\RequestInterface;

/**
 * AWS Signature Version 4 for requests to an AWS API.
 *
 * Signs a PSR-7 request in place of the AWS SDK, which this library does not depend on. The signed
 * headers are host, content-type when present, and every x-amz-* header, which is what the AWS JSON
 * and XML APIs require; the payload hash is computed from the body rather than sent as a header,
 * because only S3 asks for x-amz-content-sha256. Paths are encoded twice, as SigV4 specifies for
 * every service except S3, so this signer is not for S3.
 *
 * @see https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_sigv-create-signed-request.html
 */
final readonly class Signature
{
    public const string ALGORITHM = 'AWS4-HMAC-SHA256';

    public function __construct(
        private string $accessKeyId,
        private string $secretAccessKey,
        private ?string $sessionToken = null,
    ) {
    }

    /**
     * @param string $service Signing name of the service, e.g. "cloudfront".
     * @param string $region Signing region. Global services such as CloudFront sign with "us-east-1".
     * @param \DateTimeImmutable|null $time Signing time; now when omitted.
     */
    public function sign(RequestInterface $request, string $service, string $region, ?\DateTimeImmutable $time = null): RequestInterface
    {
        $time = ($time ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'));
        $amzDate = $time->format('Ymd\THis\Z');
        $date = $time->format('Ymd');

        $request = $request
            ->withoutHeader('Authorization')
            ->withHeader('Host', $this->host($request))
            ->withHeader('X-Amz-Date', $amzDate);

        if ($this->sessionToken !== null && $this->sessionToken !== '') {
            $request = $request->withHeader('X-Amz-Security-Token', $this->sessionToken);
        }

        $headers = $this->canonicalHeaders($request);
        $signedHeaders = implode(';', array_keys($headers));

        $canonicalRequest = implode("\n", [
            strtoupper($request->getMethod()),
            $this->canonicalPath($request->getUri()->getPath()),
            $this->canonicalQuery($request->getUri()->getQuery()),
            implode('', array_map(static fn (string $name, string $value): string => $name . ':' . $value . "\n", array_keys($headers), $headers)),
            $signedHeaders,
            hash('sha256', (string) $request->getBody()),
        ]);

        $scope = $date . '/' . $region . '/' . $service . '/aws4_request';
        $stringToSign = implode("\n", [self::ALGORITHM, $amzDate, $scope, hash('sha256', $canonicalRequest)]);

        $key = hash_hmac('sha256', $date, 'AWS4' . $this->secretAccessKey, true);
        $key = hash_hmac('sha256', $region, $key, true);
        $key = hash_hmac('sha256', $service, $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);

        $signature = hash_hmac('sha256', $stringToSign, $key);

        return $request->withHeader(
            'Authorization',
            self::ALGORITHM . ' Credential=' . $this->accessKeyId . '/' . $scope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature,
        );
    }

    private function host(RequestInterface $request): string
    {
        $uri = $request->getUri();
        $port = $uri->getPort();

        return $uri->getHost() . ($port === null ? '' : ':' . $port);
    }

    /**
     * @return array<string, string> Lowercase header names, sorted, mapped to their trimmed values.
     */
    private function canonicalHeaders(RequestInterface $request): array
    {
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            $name = strtolower((string) $name);

            if ($name !== 'host' && $name !== 'content-type' && !str_starts_with($name, 'x-amz-')) {
                continue;
            }

            $values = array_map($this->collapseSpaces(...), $values);
            $headers[$name] = implode(',', $values);
        }

        ksort($headers, \SORT_STRING);

        return $headers;
    }

    /**
     * Trims the value and folds every run of whitespace inside it to one space.
     */
    private function collapseSpaces(string $value): string
    {
        $words = explode(' ', str_replace(["\t", "\n", "\r"], ' ', trim($value)));

        return implode(' ', array_filter($words, static fn (string $word): bool => $word !== ''));
    }

    /**
     * The URI's path is already percent-encoded once; SigV4 encodes each segment again.
     */
    private function canonicalPath(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }

    private function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $pairs[] = [rawurlencode(rawurldecode($name)), rawurlencode(rawurldecode($value))];
        }

        // Byte order, not <=>, which compares numeric-looking strings as numbers.
        usort($pairs, static fn (array $a, array $b): int => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));

        return implode('&', array_map(static fn (array $pair): string => $pair[0] . '=' . $pair[1], $pairs));
    }
}
