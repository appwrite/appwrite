<?php

// ruleid: php.appwrite.tls-verification-disabled
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

// ruleid: php.appwrite.tls-verification-disabled
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

// ruleid: php.appwrite.tls-verification-disabled
\curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

// ruleid: php.appwrite.tls-verification-disabled
curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_SSL_VERIFYPEER => false]);

// ruleid: php.appwrite.tls-verification-disabled
$context = stream_context_create(['ssl' => ['verify_peer' => false]]);

// ruleid: php.appwrite.tls-verification-disabled
$context = stream_context_create(['ssl' => ['verify_peer_name' => false, 'cafile' => $ca]]);

// ruleid: php.appwrite.tls-verification-disabled
$context = stream_context_create(['ssl' => ['allow_self_signed' => true]]);

// ok: php.appwrite.tls-verification-disabled
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

// ok: php.appwrite.tls-verification-disabled
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

// ok: php.appwrite.tls-verification-disabled
$context = stream_context_create(['ssl' => ['verify_peer' => true, 'cafile' => $ca]]);
