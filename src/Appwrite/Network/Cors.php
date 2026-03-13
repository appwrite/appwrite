<?php

namespace Appwrite\Network;

use Utopia\Validator\Hostname;

/**
 * Generate CORS response headers for an incoming request.
 *
 * Allowed origins are matched by hostname only. Arrays passed to the
 * constructor (methods, headers, exposed headers) are formatted into
 * comma-separated header strings.
 */
final class Cors
{
    public const string HEADER_ALLOW_ORIGIN      = 'Access-Control-Allow-Origin';
    public const string HEADER_ALLOW_METHODS     = 'Access-Control-Allow-Methods';
    public const string HEADER_ALLOW_HEADERS     = 'Access-Control-Allow-Headers';
    public const string HEADER_ALLOW_CREDENTIALS = 'Access-Control-Allow-Credentials';
    public const string HEADER_EXPOSE_HEADERS    = 'Access-Control-Expose-Headers';
    public const string HEADER_MAX_AGE           = 'Access-Control-Max-Age';

    /**
     * @param array<string> $allowedHosts     Array of allowed hosts
     * @param array<string> $allowedMethods   Array of allowed methods
     * @param array<string> $allowedHeaders   Array of allowed header
     * @param array<string> $exposedHeaders   Array of exposed headers
     * @param bool          $allowCredentials Whether to allow credentials (default: false)
     * @param int           $maxAge           Maximum age of the preflight response (default: 86400 seconds)
     */
    public function __construct(
        private array $allowedHosts,
        private array $allowedMethods,
        private array $allowedHeaders,
        private array $exposedHeaders,
        private bool  $allowCredentials = false,
        private int   $maxAge = 86400,
    ) {
        $this->allowedHosts = \array_map('strtolower', $this->allowedHosts);

        if ($this->allowedHosts === ['*'] && $allowCredentials === true) {
            throw new \InvalidArgumentException(
                'CORS invariant violated: cannot use wildcard origin "*" when credentials are enabled.'
            );
        }
    }

    /**
     * Build CORS headers for a given request origin.
     *
     * @return array<string,string>
     */
    public function headers(string $origin): array
    {
        $headers = [
            self::HEADER_ALLOW_METHODS     => implode(', ', $this->allowedMethods),
            self::HEADER_ALLOW_HEADERS     => implode(', ', $this->allowedHeaders),
            self::HEADER_EXPOSE_HEADERS    => implode(', ', $this->exposedHeaders),
            self::HEADER_ALLOW_CREDENTIALS => $this->allowCredentials ? 'true' : 'false',
            self::HEADER_MAX_AGE           => $this->maxAge,
        ];

        // Wildcard allow-all
        if ($this->allowedHosts === ['*']) {
            $headers[self::HEADER_ALLOW_ORIGIN] = $origin;
            return $headers;
        }

        // Normal origin handling
        $origin = strtolower(trim($origin));
        if ($origin === '') {
            return $headers;
        }

        $host = parse_url($origin, PHP_URL_HOST);
        if (!\is_string($host) || $host === '') {
            return $headers;
        }

        // Match only by host
        $validator = new Hostname($this->allowedHosts);
        if (!$validator->isValid($host)) {
            return $headers;
        }

        // Accepted
        $headers[self::HEADER_ALLOW_ORIGIN] = $origin;

        return $headers;
    }
}
