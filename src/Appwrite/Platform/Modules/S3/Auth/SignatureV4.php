<?php

namespace Appwrite\Platform\Modules\S3\Auth;

use Appwrite\Auth\Key;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Database\Documents\User;
use Utopia\Database\Document;
use Utopia\Http\Adapter\Swoole\Request;

class SignatureV4
{
    private const MAX_CLOCK_SKEW = 900;

    private const MAX_PRESIGNED_EXPIRY = 604800;

    /**
     * The AWS access key ID is the Appwrite project ID; the AWS secret is an Appwrite API key secret.
     */
    public function verify(Request $request, Document $project, Document $team, User $user, array $requiredScopes): Key
    {
        $authorization = $request->getHeaderLine('authorization');
        $queryAuthentication = $this->queryAuthentication($request);

        if ($authorization !== '' && $queryAuthentication !== null) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Multiple AWS Signature V4 authentication methods were provided.');
        }

        if ($queryAuthentication !== null) {
            $algorithm = $queryAuthentication['X-Amz-Algorithm'] ?? '';
            if ($algorithm !== 'AWS4-HMAC-SHA256') {
                throw new Exception(Exception::USER_UNAUTHORIZED, 'Unsupported AWS Signature V4 algorithm.');
            }

            $credential = \explode('/', $queryAuthentication['X-Amz-Credential'] ?? '');
            $signedHeaders = \explode(';', $queryAuthentication['X-Amz-SignedHeaders'] ?? '');
            $signature = $queryAuthentication['X-Amz-Signature'] ?? '';
            $date = $queryAuthentication['X-Amz-Date'] ?? '';
            $expires = $queryAuthentication['X-Amz-Expires'] ?? '';
            $presigned = true;
        } else {
            if (!\str_starts_with($authorization, 'AWS4-HMAC-SHA256 ')) {
                throw new Exception(Exception::USER_UNAUTHORIZED, 'Missing AWS Signature V4 authentication.');
            }

            $parts = $this->parseAuthorization($authorization);
            $credential = \explode('/', $parts['Credential'] ?? '');
            $signedHeaders = \explode(';', $parts['SignedHeaders'] ?? '');
            $signature = $parts['Signature'] ?? '';
            $date = $request->getHeaderLine('x-amz-date') ?: $request->getHeaderLine('date');
            $expires = null;
            $presigned = false;
        }

        if (
            \count($credential) !== 5
            || $credential[1] === ''
            || $credential[2] === ''
            || $credential[3] !== 's3'
            || $credential[4] !== 'aws4_request'
        ) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Invalid AWS Signature V4 credential scope.');
        }

        $projectId = $credential[0];

        if ($projectId === '') {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Missing AWS credential project ID.');
        }

        if ($projectId !== $project->getId()) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'AWS credential project ID does not belong to the selected project.');
        }

        $normalizedSignedHeaders = \array_values(\array_filter(\array_map(
            fn (string $header): string => \strtolower(\trim($header)),
            $signedHeaders
        )));
        $sortedSignedHeaders = $normalizedSignedHeaders;
        \sort($sortedSignedHeaders, SORT_STRING);
        if (
            $signature === ''
            || $date === ''
            || ($presigned && \preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1)
            || ($presigned && !\in_array('host', $normalizedSignedHeaders, true))
            || ($presigned && $normalizedSignedHeaders !== $sortedSignedHeaders)
        ) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Incomplete AWS Signature V4 authentication.');
        }

        if ($presigned) {
            $this->validatePresignedDate($date, $credential[1], (string) $expires);
        } else {
            $this->validateDate($date, $credential[1]);
        }

        $canonicalRequest = $this->canonicalRequest($request, $normalizedSignedHeaders, $presigned);
        $scope = \implode('/', \array_slice($credential, 1, 4));
        $stringToSign = "AWS4-HMAC-SHA256\n{$date}\n{$scope}\n" . \hash('sha256', $canonicalRequest);

        foreach ($this->keySecrets($project) as $secret) {
            $signingKey = $this->signingKey($secret, $credential[1], $credential[2], $credential[3]);
            $expected = \hash_hmac('sha256', $stringToSign, $signingKey);

            if (!\hash_equals($expected, $signature)) {
                continue;
            }

            $this->verifyPayload($request, $presigned);

            $apiKey = Key::decode($project, $team, $user, $secret);
            if ($apiKey->getRole() !== User::ROLE_KEYS || $apiKey->isExpired()) {
                throw new Exception(Exception::USER_UNAUTHORIZED, 'Invalid or expired API key.');
            }

            if (empty(\array_intersect($requiredScopes, $apiKey->getScopes()))) {
                throw new Exception(Exception::GENERAL_UNAUTHORIZED_SCOPE, 'API key is missing required S3 storage scopes.');
            }

            return $apiKey;
        }

        throw new Exception(Exception::USER_UNAUTHORIZED, 'Signature does not match.');
    }

    private function verifyPayload(Request $request, bool $presigned): void
    {
        if ($presigned) {
            return;
        }

        $expected = \strtolower($request->getHeaderLine('x-amz-content-sha256'));
        if ($expected === '' || $expected === 'unsigned-payload' || \str_starts_with($expected, 'streaming-')) {
            return;
        }

        if (\preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1 || !\hash_equals($expected, \hash('sha256', $request->getRawPayload()))) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Payload hash does not match x-amz-content-sha256.');
        }
    }

    private function validatePresignedDate(string $date, string $credentialDate, string $expires): void
    {
        if ($expires === '' || !\ctype_digit($expires)) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Invalid AWS Signature V4 presigned URL expiry.');
        }

        $expires = (int) $expires;
        if ($expires < 1 || $expires > self::MAX_PRESIGNED_EXPIRY) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'AWS Signature V4 presigned URL expiry is outside the allowed range.');
        }

        $timestamp = $this->timestamp($date);
        if ($timestamp->format('Ymd') !== $credentialDate) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'AWS Signature V4 credential date does not match request timestamp.');
        }

        $now = \time();
        if ($timestamp->getTimestamp() - $now > self::MAX_CLOCK_SKEW) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'AWS Signature V4 timestamp is outside the allowed time window.');
        }
        if ($now > $timestamp->getTimestamp() + $expires) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'AWS Signature V4 presigned URL has expired.');
        }
    }

    private function validateDate(string $date, string $credentialDate): void
    {
        $timestamp = \DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $date, new \DateTimeZone('UTC'));
        if ($timestamp === false) {
            $parsed = \strtotime($date);
            if ($parsed === false) {
                throw new Exception(Exception::USER_UNAUTHORIZED, 'Invalid AWS Signature V4 timestamp.');
            }

            $timestamp = (new \DateTimeImmutable('@' . $parsed))->setTimezone(new \DateTimeZone('UTC'));
        }

        if ($timestamp->format('Ymd') !== $credentialDate) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'AWS Signature V4 credential date does not match request timestamp.');
        }

        if (\abs(\time() - $timestamp->getTimestamp()) > self::MAX_CLOCK_SKEW) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'AWS Signature V4 timestamp is outside the allowed time window.');
        }
    }

    private function timestamp(string $date): \DateTimeImmutable
    {
        $timestamp = \DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $date, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            $timestamp === false
            || (\is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $timestamp->format('Ymd\THis\Z') !== $date
        ) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Invalid AWS Signature V4 timestamp.');
        }

        return $timestamp;
    }

    /**
     * @return array<string>
     */
    private function keySecrets(Document $project): array
    {
        $secrets = [];
        foreach ($project->getAttribute('keys', []) as $key) {
            $secret = $key instanceof Document
                ? $key->getAttribute('secret', '')
                : (string) ($key['secret'] ?? '');

            if ($secret !== '') {
                $secrets[] = $secret;
            }
        }

        return $secrets;
    }

    private function parseAuthorization(string $authorization): array
    {
        $authorization = \substr($authorization, \strlen('AWS4-HMAC-SHA256 '));
        $result = [];
        foreach (\explode(',', $authorization) as $part) {
            [$key, $value] = \array_pad(\explode('=', \trim($part), 2), 2, '');
            $result[$key] = $value;
        }
        return $result;
    }

    /**
     * @return array<string, string>|null
     */
    private function queryAuthentication(Request $request): ?array
    {
        $authenticationParameters = [
            'X-Amz-Algorithm',
            'X-Amz-Credential',
            'X-Amz-Date',
            'X-Amz-Expires',
            'X-Amz-Signature',
            'X-Amz-SignedHeaders',
        ];
        $parameters = [];
        $hasAuthenticationParameter = false;

        foreach ($this->queryPairs($request) as [$name, $value]) {
            if (!\in_array($name, $authenticationParameters, true)) {
                continue;
            }

            $hasAuthenticationParameter = true;
            if (\array_key_exists($name, $parameters)) {
                throw new Exception(Exception::USER_UNAUTHORIZED, 'Duplicate AWS Signature V4 authentication parameter.');
            }
            $parameters[$name] = $value;
        }

        if (!$hasAuthenticationParameter) {
            return null;
        }

        foreach ($authenticationParameters as $parameter) {
            if (!\array_key_exists($parameter, $parameters)) {
                throw new Exception(Exception::USER_UNAUTHORIZED, 'Incomplete AWS Signature V4 query authentication.');
            }
        }

        return $parameters;
    }

    private function canonicalRequest(Request $request, array $signedHeaders, bool $presigned): string
    {
        $headers = [];
        foreach ($signedHeaders as $header) {
            $header = \strtolower(\trim($header));
            if ($header === '') {
                continue;
            }

            $value = $request->getHeaderLine($header);
            if ($header === 'accept-encoding' && $request->hasHeader('fastly-orig-accept-encoding')) {
                // Fastly normalizes Accept-Encoding before forwarding the request, but
                // preserves the value that the S3 client signed in this header.
                $value = $request->getHeaderLine('fastly-orig-accept-encoding');
            }

            $headers[$header] = \preg_replace('/\s+/', ' ', \trim($value));
        }
        \ksort($headers);

        $canonicalHeaders = '';
        foreach ($headers as $key => $value) {
            $canonicalHeaders .= $key . ':' . $value . "\n";
        }

        $payloadHash = $presigned ? 'UNSIGNED-PAYLOAD' : $request->getHeaderLine('x-amz-content-sha256');
        $payloadHash = $payloadHash === '' ? \hash('sha256', $request->getRawPayload()) : $payloadHash;

        return \implode("\n", [
            $request->getMethod(),
            $this->canonicalUri($request),
            $this->canonicalQuery($request, $presigned),
            $canonicalHeaders,
            \implode(';', \array_keys($headers)),
            $payloadHash,
        ]);
    }

    private function canonicalUri(Request $request): string
    {
        $path = \parse_url($request->getURI(), PHP_URL_PATH);
        return $path === null || $path === false || $path === '' ? '/' : $path;
    }

    private function canonicalQuery(Request $request, bool $presigned): string
    {
        $pairs = [];
        foreach ($this->queryPairs($request) as [$key, $value]) {
            if ($presigned && $key === 'X-Amz-Signature') {
                continue;
            }

            $pairs[] = [\rawurlencode($key), \rawurlencode($value)];
        }

        \usort($pairs, function (array $left, array $right): int {
            $keyOrder = \strcmp($left[0], $right[0]);
            return $keyOrder === 0 ? \strcmp($left[1], $right[1]) : $keyOrder;
        });

        return \implode('&', \array_map(fn (array $pair): string => $pair[0] . '=' . $pair[1], $pairs));
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function queryPairs(Request $request): array
    {
        $query = $request->getServer('query_string', '') ?: (\parse_url($request->getURI(), PHP_URL_QUERY) ?: '');
        if ($query === '') {
            return [];
        }

        $pairs = [];
        foreach (\explode('&', $query) as $part) {
            [$name, $value] = \array_pad(\explode('=', $part, 2), 2, '');
            $pairs[] = [\rawurldecode($name), \rawurldecode($value)];
        }

        return $pairs;
    }

    private function signingKey(string $secret, string $date, string $region, string $service): string
    {
        $kDate = \hash_hmac('sha256', $date, 'AWS4' . $secret, true);
        $kRegion = \hash_hmac('sha256', $region, $kDate, true);
        $kService = \hash_hmac('sha256', $service, $kRegion, true);
        return \hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}
