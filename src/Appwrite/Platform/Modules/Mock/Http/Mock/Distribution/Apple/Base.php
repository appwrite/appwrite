<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple;

use Appwrite\Platform\Action;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Platform\Scope\HTTP;

abstract class Base extends Action
{
    use HTTP;

    protected const string PREFIX = '/v1/mock/distribution/apple';
    protected const string SEEDS = self::PREFIX . '/seeds';
    protected const int TTL = 86400;
    protected const string CACHE_KEYS = 'mock:distribution:apple:keys:';
    protected const string CACHE_APPS = 'mock:distribution:apple:apps:';
    protected const string CACHE_APP = 'mock:distribution:apple:app:';
    protected const string CACHE_BUILDS = 'mock:distribution:apple:builds:';
    protected const string CODE_PARAMETER_INVALID = 'PARAMETER_ERROR.INVALID';
    protected const string CODE_PARAMETER_REQUIRED = 'PARAMETER_ERROR.REQUIRED';

    private const string CACHE_REQUESTS = 'mock:distribution:apple:requests:';
    private const string HEADER_RATE_LIMIT = 'X-Rate-Limit';
    private const string ALGORITHM = 'ES256';
    private const string AUDIENCE = 'appstoreconnect-v1';
    private const int LIFETIME = 1200;
    private const int SKEW = 60;
    private const int RATE_LIMIT = 3500;
    private const int RATE_WINDOW = 3600;
    private const int SIGNATURE_PART = 32;

    /**
     * @return array{keyId: string, issuerId: string, publicKey: string, agreements: bool}|null
     */
    protected function authorize(Request $request, Response $response, Cache $cache): ?array
    {
        $key = $this->verify($request->getHeaderLine('authorization'), $cache);

        if ($key === null) {
            $this->error(
                $response,
                Response::STATUS_CODE_UNAUTHORIZED,
                'NOT_AUTHORIZED',
                'Authentication credentials are missing or invalid.',
                'Provide a properly configured and signed bearer token, and make sure that it has not expired.',
            );

            return null;
        }

        $remaining = $this->count($cache, $key['keyId']);
        $response->setHeader(self::HEADER_RATE_LIMIT, $this->rate(\max(0, $remaining)));

        if ($remaining < 0) {
            $this->error(
                $response,
                Response::STATUS_CODE_TOO_MANY_REQUESTS,
                'RATE_LIMIT_EXCEEDED',
                'The request rate limit has been reached.',
                "We've received too many requests for this API. Please wait and try again or slow down your request rate.",
            );

            return null;
        }

        if (!$key['agreements']) {
            $this->error(
                $response,
                Response::STATUS_CODE_FORBIDDEN,
                'FORBIDDEN.REQUIRED_AGREEMENTS_MISSING_OR_EXPIRED',
                'A required agreement is missing or has expired.',
                'This request requires an in-effect agreement that has not been signed or has expired.',
            );

            return null;
        }

        return $key;
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function respond(Response $response, array $body, int $status = Response::STATUS_CODE_OK): void
    {
        if (!$response->hasHeader(self::HEADER_RATE_LIMIT)) {
            $response->setHeader(self::HEADER_RATE_LIMIT, $this->rate(self::RATE_LIMIT));
        }

        $response
            ->setStatusCode($status)
            ->json($body);
    }

    /**
     * @param array<string, string> $source
     */
    protected function error(Response $response, int $status, string $code, string $title, string $detail, array $source = []): void
    {
        $error = [
            'id' => $this->uuid(),
            'status' => (string) $status,
            'code' => $code,
            'title' => $title,
            'detail' => $detail,
        ];

        if ($source !== []) {
            $error['source'] = $source;
        }

        $this->respond($response, ['errors' => [$error]], $status);
    }

    protected function link(Request $request): string
    {
        $query = $request->getServer('query_string') ?? '';
        $link = $request->getProtocol() . '://' . $request->getHostname() . $request->getURI();

        return $query === '' ? $link : $link . '?' . $query;
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $fields
     * @return array<string, mixed>|\stdClass
     */
    protected function select(array $attributes, array $fields, string $type): array|\stdClass
    {
        $selected = $fields[$type] ?? null;

        if (!\is_string($selected) || $selected === '') {
            return $attributes;
        }

        $attributes = \array_intersect_key($attributes, \array_flip(\explode(',', $selected)));

        return $attributes === [] ? new \stdClass() : $attributes;
    }

    protected function uuid(): string
    {
        $bytes = \random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3f) | 0x80);
        $hex = \bin2hex($bytes);

        return \implode('-', [
            \substr($hex, 0, 8),
            \substr($hex, 8, 4),
            \substr($hex, 12, 4),
            \substr($hex, 16, 4),
            \substr($hex, 20),
        ]);
    }

    /**
     * @return array{keyId: string, issuerId: string, publicKey: string, agreements: bool}|null
     */
    private function verify(string $authorization, Cache $cache): ?array
    {
        if (!\str_starts_with($authorization, 'Bearer ')) {
            return null;
        }

        $parts = \explode('.', \substr($authorization, \strlen('Bearer ')));

        if (\count($parts) !== 3) {
            return null;
        }

        [$headerEncoded, $claimsEncoded, $signatureEncoded] = $parts;
        $header = \json_decode($this->decode($headerEncoded) ?? '', true);
        $claims = \json_decode($this->decode($claimsEncoded) ?? '', true);
        $signature = $this->decode($signatureEncoded);

        if (!\is_array($header) || !\is_array($claims) || $signature === null || \strlen($signature) !== self::SIGNATURE_PART * 2) {
            return null;
        }

        $keyId = $header['kid'] ?? null;

        if (($header['alg'] ?? null) !== self::ALGORITHM || !\is_string($keyId) || $keyId === '') {
            return null;
        }

        $key = $cache->load(self::CACHE_KEYS . $keyId, self::TTL);

        if (!\is_array($key)) {
            return null;
        }

        $issuedAt = $claims['iat'] ?? null;
        $expiresAt = $claims['exp'] ?? null;
        $now = \time();

        if (
            !\is_int($issuedAt)
            || !\is_int($expiresAt)
            || ($claims['iss'] ?? null) !== $key['issuerId']
            || ($claims['aud'] ?? null) !== self::AUDIENCE
            || $expiresAt <= $issuedAt
            || $expiresAt - $issuedAt > self::LIFETIME
            || $expiresAt < $now
            || $issuedAt > $now + self::SKEW
        ) {
            return null;
        }

        $publicKey = \openssl_pkey_get_public($key['publicKey']);

        if ($publicKey === false) {
            return null;
        }

        $verified = \openssl_verify($headerEncoded . '.' . $claimsEncoded, $this->der($signature), $publicKey, OPENSSL_ALGO_SHA256);

        return $verified === 1 ? $key : null;
    }

    private function decode(string $text): ?string
    {
        $padding = \str_repeat('=', (4 - \strlen($text) % 4) % 4);
        $decoded = \base64_decode(\strtr($text, '-_', '+/') . $padding, true);

        return $decoded === false ? null : $decoded;
    }

    private function der(string $raw): string
    {
        $body = $this->integer(\substr($raw, 0, self::SIGNATURE_PART)) . $this->integer(\substr($raw, self::SIGNATURE_PART));

        return "\x30" . \chr(\strlen($body)) . $body;
    }

    private function integer(string $part): string
    {
        $part = \ltrim($part, "\x00");

        if ($part === '' || (\ord($part[0]) & 0x80) !== 0) {
            $part = "\x00" . $part;
        }

        return "\x02" . \chr(\strlen($part)) . $part;
    }

    private function count(Cache $cache, string $keyId): int
    {
        $now = \time();
        $window = $cache->load(self::CACHE_REQUESTS . $keyId, self::RATE_WINDOW);

        if (!\is_array($window) || $now - $window['start'] >= self::RATE_WINDOW) {
            $window = ['start' => $now, 'count' => 0];
        }

        $window['count']++;
        $cache->save(self::CACHE_REQUESTS . $keyId, $window);

        return self::RATE_LIMIT - $window['count'];
    }

    private function rate(int $remaining): string
    {
        return 'user-hour-lim:' . self::RATE_LIMIT . ';user-hour-rem:' . $remaining . ';';
    }
}
