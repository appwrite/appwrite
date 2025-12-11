<?php

namespace Appwrite\Network;

use Appwrite\Utopia\Request;
use Utopia\System\System;

class TrustedIp
{
    /**
     * Extract the trusted client IP address from a request.
     *
     * This method checks configured trusted headers (e.g., X-Forwarded-For)
     * and falls back to the remote address if no valid IP is found.
     *
     * @param Request $request The Utopia request object
     * @return string The trusted client IP address
     *
     */
    public static function extract(Request $request): string
    {
        // Fallback to remote address
        $remoteAddr = $request->getServer('remote_addr') ?? '0.0.0.0';

        // Fetch and parse the list of trusted headers from configuration
        $trustedHeadersConfig = System::getEnv('_APP_TRUSTED_HEADERS', 'x-forwarded-for');

        $trustedHeaders = explode(',', $trustedHeadersConfig);
        $trustedHeaders = array_map('trim', $trustedHeaders);
        $trustedHeaders = array_map('strtolower', $trustedHeaders);
        $trustedHeaders = array_filter($trustedHeaders);

        foreach ($trustedHeaders as $header) {
            $headerValue = $request->getHeader($header);

            if (empty($headerValue)) {
                continue;
            }

            // Leftmost IP address is the address of the originating client
            $ips = explode(',', $headerValue);
            $ip = trim($ips[0]);

            // Validate IP format (supports both IPv4 and IPv6)
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return $remoteAddr;
    }
}
