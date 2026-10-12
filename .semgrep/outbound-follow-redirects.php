<?php

// ruleid: php.appwrite.outbound-follow-redirects
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

// ruleid: php.appwrite.outbound-follow-redirects
\curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);

// ruleid: php.appwrite.outbound-follow-redirects
curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_FOLLOWLOCATION => true]);

// ruleid: php.appwrite.outbound-follow-redirects
$client->setMaxRedirects(5);

// ok: php.appwrite.outbound-follow-redirects
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

// ok: php.appwrite.outbound-follow-redirects
$client->setMaxRedirects(0);
