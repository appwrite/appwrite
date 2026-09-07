<?php

declare(strict_types=1);

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git\GitHub;

// The existing CI job must authenticate before any repository-mutating E2E test.
if (getenv('GITHUB_ACTIONS') !== 'true') {
    exit(0);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$identifier = System::getEnv('TESTS_GITHUB_APP_IDENTIFIER') ?? '';
$installation = System::getEnv('TESTS_GITHUB_INSTALLATION_ID') ?? '';
$privateKey = str_replace('\\n', "\n", System::getEnv('TESTS_GITHUB_PRIVATE_KEY') ?? '');
$diagnostic = [
    'identifierPresent' => $identifier !== '' && $identifier !== '0',
    'identifierNumeric' => ctype_digit($identifier),
    'identifierClientIdPrefix' => str_starts_with($identifier, 'Iv1.'),
    'identifierWhitespace' => $identifier !== trim($identifier),
    'identifierQuoted' => str_starts_with($identifier, '"') || str_ends_with($identifier, '"')
        || str_starts_with($identifier, "'") || str_ends_with($identifier, "'"),
    'installationPresent' => $installation !== '' && $installation !== '0',
    'privateKeyPresent' => $privateKey !== '',
    'privateKeyParsed' => $privateKey !== '' && openssl_pkey_get_private($privateKey) !== false,
];

try {
    if (!$diagnostic['identifierPresent'] || !$diagnostic['installationPresent'] || !$diagnostic['privateKeyParsed']) {
        $diagnostic['errorCategory'] = 'credential-configuration';
        throw new RuntimeException();
    }

    // Capture the JWT produced by the real public initialization path without
    // requesting an installation token or performing any remote write.
    $adapter = new class (new Cache(new None())) extends GitHub {
        public string $authorization = '';

        #[Override]
        protected function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, bool $followRedirects = true): array
        {
            $this->authorization = $headers['Authorization'];

            return ['body' => ['token' => 'preflight'], 'headers' => ['status-code' => 201]];
        }
    };
    $adapter->initializeVariables($installation, $privateKey, $identifier);
    $token = substr($adapter->authorization, strlen('Bearer '));
    $parts = explode('.', $token);
    $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/'), true), true, flags: JSON_THROW_ON_ERROR);
    $diagnostic['issuerType'] = get_debug_type($claims['iss'] ?? null);
    $diagnostic['issuerMatchesIdentifier'] = (string) ($claims['iss'] ?? '') === $identifier;
    if ($diagnostic['identifierNumeric'] && $diagnostic['issuerType'] !== 'int') {
        $diagnostic['errorCategory'] = 'issuer';
        throw new RuntimeException();
    }

    $request = curl_init('https://api.github.com/app');
    curl_setopt_array($request, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'utopia-vcs-ci-preflight',
        CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'Authorization: ' . $adapter->authorization],
    ]);
    $body = curl_exec($request);
    $diagnostic['appStatus'] = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    $response = is_string($body) ? json_decode($body, true) : null;
    $message = is_array($response) && is_string($response['message'] ?? null) ? $response['message'] : '';
    $diagnostic['errorCategory'] = match (true) {
        $diagnostic['appStatus'] === 200 => 'none',
        str_contains($message, "'Issuer' claim") => 'issuer',
        str_contains(strtolower($message), 'signature') => 'signature',
        $message === 'Bad credentials' => 'credentials',
        $diagnostic['appStatus'] === 0 => 'transport',
        default => 'other-auth-response',
    };
} catch (Throwable) {
    $diagnostic['errorCategory'] ??= 'local-signing';
}

// Never print identifiers, PEM, JWT, tokens, URLs containing credentials, or a
// raw GitHub response. Only the closed diagnostic fields above reach CI logs.
fwrite(STDOUT, 'GitHub authentication preflight: ' . json_encode($diagnostic, JSON_THROW_ON_ERROR) . PHP_EOL);
exit(($diagnostic['appStatus'] ?? 0) === 200 && ($diagnostic['issuerMatchesIdentifier'] ?? false) ? 0 : 1);
