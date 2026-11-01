<?php

namespace Appwrite\Auth;

/**
 * A one-time passcode bound to the origin that asked for it, delivered as the
 * `OTP-Token` email header so mail clients and autofill can hand the code to
 * that origin only.
 *
 * @see https://datatracker.ietf.org/doc/draft-goto-otp-token/
 */
final readonly class OTPToken
{
    public const string HEADER = 'OTP-Token';

    private function __construct(
        public string $code,
        public string $origin,
    ) {
    }

    /**
     * Bind a code to an origin, or null when the origin cannot carry one: the
     * draft accepts only potentially trustworthy origins in canonical form.
     */
    public static function from(string $code, string $origin): ?self
    {
        if (!self::isPrintable($code)) {
            return null;
        }

        $origin = self::canonicalize($origin);
        if ($origin === null) {
            return null;
        }

        return new self($code, $origin);
    }

    /**
     * The header, ready for a mail message.
     *
     * @return array<string, string>
     */
    public function toHeaders(): array
    {
        return [self::HEADER => $this->getValue()];
    }

    /**
     * An RFC 9651 Structured Field item: a String with an `origin` parameter.
     */
    public function getValue(): string
    {
        return self::string($this->code) . '; origin=' . self::string($this->origin);
    }

    /**
     * RFC 6454 ASCII serialization: lowercase, default port omitted, nothing
     * past the authority.
     */
    private static function canonicalize(string $origin): ?string
    {
        if (!self::isPrintable($origin)) {
            return null;
        }

        $parts = \parse_url($origin);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        foreach (['user', 'pass', 'path', 'query', 'fragment'] as $part) {
            if (isset($parts[$part])) {
                return null;
            }
        }

        $scheme = \strtolower($parts['scheme']);
        $host = \strtolower($parts['host']);
        $port = $parts['port'] ?? null;

        $default = match ($scheme) {
            'https' => 443,
            'http' => 80,
            default => null,
        };

        if ($default === null) {
            return null;
        }

        if ($scheme === 'http' && !self::isLoopback($host)) {
            return null;
        }

        $serialized = $scheme . '://' . $host;
        if ($port !== null && $port !== $default) {
            $serialized .= ':' . $port;
        }

        return $serialized;
    }

    /**
     * Secure Contexts: plain http is only trustworthy on the local machine.
     */
    private static function isLoopback(string $host): bool
    {
        if ($host === 'localhost' || \str_ends_with($host, '.localhost') || $host === '[::1]') {
            return true;
        }

        return \filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && \str_starts_with($host, '127.');
    }

    private static function isPrintable(string $value): bool
    {
        return $value !== '' && \ctype_print($value);
    }

    private static function string(string $value): string
    {
        return '"' . \addcslashes($value, '"\\') . '"';
    }
}
