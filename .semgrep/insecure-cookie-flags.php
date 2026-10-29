<?php

// ruleid: php.appwrite.insecure-cookie-flags
$response->addCookie('a_session', $secret, $expire, '/', $domain, true, false, 'None');

// ruleid: php.appwrite.insecure-cookie-flags
$response->addCookie(name: 'a_session', value: $secret, httponly: false);

// ruleid: php.appwrite.insecure-cookie-flags
setcookie('a_session', $secret);

// ruleid: php.appwrite.insecure-cookie-flags
\setcookie('a_session', $secret);

// ok: php.appwrite.insecure-cookie-flags
$response->addCookie('a_session', $secret, $expire, '/', $domain, ('https' == $protocol), true, null);

// ok: php.appwrite.insecure-cookie-flags
$response->addCookie(name: 'a_session', value: $secret, httponly: true);
