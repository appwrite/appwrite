<?php

// Source: https://github.com/disposable-email-domains/disposable-email-domains/blob/main/disposable_email_blocklist.conf
// License: MIT (per upstream repo). Keep this list periodically updated.

$domains = [];

// Allow overriding source URL via environment variable
// $url = \getenv('_APP_DISPOSABLE_DOMAINS_URL') ?: 'https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/main/disposable_email_blocklist.conf';

// // Try to fetch from upstream directly
// $context = \stream_context_create([
//     'http'  => [
//         'timeout'       => 3,
//         'ignore_errors' => true,
//     ],
//     'https' => [
//         'timeout'       => 3,
//         'ignore_errors' => true,
//     ],
// ]);

// $raw = @\file_get_contents($url, false, $context);
// if ($raw !== false && \is_string($raw)) {
//     foreach (\explode("\n", $raw) as $line) {
//         $domain = \trim($line);
//         if ($domain === '' || $domain[0] === '#') {
//             continue;
//         }
//         $domains[\strtolower($domain)] = true;
//     }
// }

// Fallback to local file if remote failed
if (empty($domains)) {
    $path = __DIR__ . '/../assets/security/disposable_email_blocklist.conf';
    if (\is_file($path)) {
        $lines = \file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $domain = \trim($line);
            if ($domain === '' || $domain[0] === '#') {
                continue;
            }
            $domains[\strtolower($domain)] = true;
        }
    }
}

// Last-resort minimal seeds
if (empty($domains)) {
    $domains = [
        'hunterio.tk'      => true,
        'mailinator.com'   => true,
        'temp-mail.org'    => true,
        'yopmail.com'      => true,
        '10minutemail.com' => true,
    ];
}

return $domains;
