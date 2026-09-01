<?php

declare(strict_types=1);

namespace Appwrite\Smtp;

use InvalidArgumentException;
use JsonException;

final readonly class RecipientToken
{
    public function __construct(
        private string $secret,
        private int $ttlSeconds = 300,
    ) {
        if ($this->secret === '') {
            throw new InvalidArgumentException('SMTP token secret cannot be empty.');
        }
    }

    /**
     * @param  array<string, string>  $claims
     *
     * @throws JsonException
     */
    public function issue(array $claims, ?int $now = null): string
    {
        $now ??= time();
        $payload = $claims + ['iat' => $now, 'exp' => $now + $this->ttlSeconds];
        $encoded = $this->encode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = $this->encode(hash_hmac('sha256', $encoded, $this->secret, true));

        return $encoded.'.'.$signature;
    }

    public function expiresAt(int $now): int
    {
        return $now + $this->ttlSeconds;
    }

    /** @return array<string, mixed> */
    public function verify(string $token, ?int $now = null): array
    {
        $now ??= time();
        [$payload, $signature] = array_pad(explode('.', $token, 2), 2, '');
        if ($payload === '' || $signature === '') {
            throw new InvalidArgumentException('Malformed SMTP token.');
        }

        $expected = $this->encode(hash_hmac('sha256', $payload, $this->secret, true));
        if (! hash_equals($expected, $signature)) {
            throw new InvalidArgumentException('Invalid SMTP token signature.');
        }

        try {
            $claims = json_decode($this->decode($payload), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Invalid SMTP token payload.', previous: $error);
        }
        if (! is_array($claims) || ! isset($claims['exp']) || ! is_int($claims['exp']) || $claims['exp'] < $now) {
            throw new InvalidArgumentException('Expired SMTP token.');
        }

        return $claims;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value.str_repeat('=', $padding), '-_', '+/'), true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Invalid SMTP token encoding.');
        }

        return $decoded;
    }
}
