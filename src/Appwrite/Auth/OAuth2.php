<?php

namespace Appwrite\Auth;

use Appwrite\Auth\OAuth2\Exception;

abstract class OAuth2
{
    /**
     * @var string
     */
    protected string $appID;

    /**
     * @var string
     */
    protected string $appSecret;

    /**
     * @var string
     */
    protected string $callback;

    /**
     * @var array
     */
    protected array $state;

    /**
     * @var array
     */
    protected array $scopes;

    /**
     * OAuth2 constructor.
     *
     * @param string $appId
     * @param string $appSecret
     * @param string $callback
     * @param array  $state
     * @param array $scopes
     */
    public function __construct(string $appId, string $appSecret, string $callback, array $state = [], array $scopes = [])
    {
        $this->appID = $appId;
        $this->appSecret = $appSecret;
        $this->callback = $callback;
        $this->state = $state;
        foreach ($scopes as $scope) {
            $this->addScope($scope);
        }
    }

    /**
     * @return string
     */
    abstract public function getName(): string;

    /**
     * @return string
     */
    abstract public function getLoginURL(): string;

    /**
     * @param string $code
     *
     * @return array
     */
    abstract protected function getTokens(string $code): array;

    /**
     * @param string $refreshToken
     *
     * @return array
     */
    abstract public function refreshTokens(string $refreshToken): array;

    /**
     * @param string $accessToken
     *
     * @return string
     */
    abstract public function getUserID(string $accessToken): string;

    /**
     * @param string $accessToken
     *
     * @return string
     */
    abstract public function getUserEmail(string $accessToken): string;

    /**
     * Check if the OAuth email is verified
     *
     * @param string $accessToken
     *
     * @return bool
     */
    abstract public function isEmailVerified(string $accessToken): bool;

    /**
     * @param string $accessToken
     *
     * @return string
     */
    abstract public function getUserName(string $accessToken): string;

    /**
     * @param $scope
     *
     * @return $this
     */
    protected function addScope(string $scope): OAuth2
    {
        // Add a scope to the scopes array if it isn't already present
        if (!\in_array($scope, $this->scopes)) {
            $this->scopes[] = $scope;
        }

        return $this;
    }

    /**
     * @return array
     */
    protected function getScopes(): array
    {
        return $this->scopes;
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getAccessToken(string $code): string
    {
        $tokens = $this->getTokens($code);

        return $tokens['access_token'] ?? '';
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getRefreshToken(string $code): string
    {
        $tokens = $this->getTokens($code);

        return $tokens['refresh_token'] ?? '';
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getAccessTokenExpiry(string $code): int
    {
        $tokens = $this->getTokens($code);

        return $tokens['expires_in'] ?? 0;
    }

    // The parseState function was designed specifically for Amazon OAuth2 Adapter to override.
    // The response from Amazon is html encoded and hence it needs to be html_decoded before
    // json_decoding
    /**
     * @param $state
     *
     * @return array
     */
    public function parseState(string $state)
    {
        return \json_decode($state, true);
    }

    /**
     * @param string $method
     * @param string $url
     * @param array  $headers
     * @param string $payload
     * @param bool   $debug
     *
     * @return string
     */
    protected function request(string $method, string $url = '', array $headers = [], string $payload = '', bool $debug = true): string
    {
        if ($debug) {
            error_log("OAuth2 Debug: Starting request to $url with method $method");
        }

        $ch = \curl_init($url);

        \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        \curl_setopt($ch, CURLOPT_HEADER, 0);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        \curl_setopt($ch, CURLOPT_USERAGENT, 'Appwrite OAuth2');
        
        // Set timeout options
        \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // 10 seconds to establish connection
        \curl_setopt($ch, CURLOPT_TIMEOUT,5); // 30 seconds total timeout
        
        // Additional options to prevent silent failures
        \curl_setopt($ch, CURLOPT_FAILONERROR, false); // Don't fail on HTTP errors, we'll handle them
        \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects
        \curl_setopt($ch, CURLOPT_MAXREDIRS, 5); // Max 5 redirects
        \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Verify SSL
        \curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Verify SSL host

        // Enable verbose debugging if requested
        if ($debug) {
            \curl_setopt($ch, CURLOPT_VERBOSE, true);
            $verbose = fopen('php://temp', 'w+');
            \curl_setopt($ch, CURLOPT_STDERR, $verbose);
        }

        if (!empty($payload)) {
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            $headers[] = 'Content-length: ' . \strlen($payload);
        }

        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        if ($debug) {
            error_log("OAuth2 Debug: Executing cURL request...");
        }
        
        // Send the request & save response to $response
        $response = \curl_exec($ch);
        
        if ($debug) {
            error_log("OAuth2 Debug: cURL execution completed. Response type: " . gettype($response));
            if ($response === false) {
                error_log("OAuth2 Debug: cURL returned false");
            } elseif ($response === null) {
                error_log("OAuth2 Debug: cURL returned null");
            } else {
                error_log("OAuth2 Debug: Response length: " . strlen($response));
            }
        }
        
        // Check for cURL errors
        $error = \curl_error($ch);
        $errno = \curl_errno($ch);
        $info = \curl_getinfo($ch);
        
        if ($debug) {
            error_log("OAuth2 Debug: cURL errno: $errno, error: $error");
            error_log("OAuth2 Debug: HTTP code: " . ($info['http_code'] ?? 'unknown'));
            error_log("OAuth2 Debug: Total time: " . ($info['total_time'] ?? 'unknown') . "s");
        }
        
        // Get verbose debug info if enabled
        $verboseLog = '';
        if ($debug && isset($verbose)) {
            rewind($verbose);
            $verboseLog = stream_get_contents($verbose);
            fclose($verbose);
            if ($verboseLog) {
                error_log("OAuth2 Debug: Verbose log: " . $verboseLog);
            }
        }
        
        \curl_close($ch);

        // Handle cURL errors - check for both explicit errors and silent failures
        if ($errno !== CURLE_OK) {
            $errorMessage = "cURL error: $error (errno: $errno)";
            
            // Add specific timeout error messages
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                $errorMessage .= " - Request timed out after {$info['total_time']} seconds";
            } elseif ($errno === CURLE_COULDNT_CONNECT) {
                $errorMessage .= " - Could not connect to server";
            } elseif ($errno === CURLE_COULDNT_RESOLVE_HOST) {
                $errorMessage .= " - Could not resolve hostname";
            } elseif ($errno === CURLE_SSL_CONNECT_ERROR) {
                $errorMessage .= " - SSL connection failed";
            } elseif ($errno === CURLE_SSL_CERTPROBLEM) {
                $errorMessage .= " - SSL certificate problem";
            }
            
            // Include verbose log in debug mode
            if ($debug && $verboseLog) {
                $errorMessage .= "\nVerbose log:\n" . $verboseLog;
            }
            
            throw new Exception($errorMessage, $errno);
        }

        // Check for silent failures - when cURL returns false but no error
        if ($response === false && $errno === CURLE_OK) {
            $errorMessage = "cURL returned false but no error was reported";
            if ($debug) {
                $errorMessage .= "\nDebug info: " . json_encode($info);
            }
            throw new Exception($errorMessage, 0);
        }

        // Check for null response
        if ($response === null) {
            $errorMessage = "cURL returned null response";
            if ($debug) {
                $errorMessage .= "\nDebug info: " . json_encode($info);
            }
            throw new Exception($errorMessage, 0);
        }

        // Check for empty response with successful HTTP code
        if (empty($response) && ($info['http_code'] >= 200 && $info['http_code'] < 300)) {
            if ($debug) {
                error_log("OAuth2 Debug: Warning - Empty response with successful HTTP code: " . $info['http_code']);
            }
        }

        $code = $info['http_code'];
        
        if ($code >= 400) {
            $errorMessage = "HTTP error $code: $response";
            if ($debug) {
                $errorMessage .= "\nRequest info: " . json_encode([
                    'url' => $url,
                    'method' => $method,
                    'headers' => $headers,
                    'payload_length' => strlen($payload),
                    'curl_info' => $info
                ]);
            }
            throw new Exception($errorMessage, $code);
        }

        if ($debug) {
            error_log("OAuth2 Debug: Request successful, returning response");
        }

        return (string)$response;
    }
}
