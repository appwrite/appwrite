<?php

// ruleid: php.appwrite.debug-output-in-handler
var_dump($user);

// ruleid: php.appwrite.debug-output-in-handler
\var_dump($user);

// ruleid: php.appwrite.debug-output-in-handler
print_r($session);

// ruleid: php.appwrite.debug-output-in-handler
var_export($document);

// ruleid: php.appwrite.debug-output-in-handler
phpinfo();

// ruleid: php.appwrite.debug-output-in-handler
header('Location: ' . $url);

// ok: php.appwrite.debug-output-in-handler
$dump = print_r($session, true);

// ok: php.appwrite.debug-output-in-handler
$code = var_export($config, true);

// ok: php.appwrite.debug-output-in-handler
$response->addHeader('Location', $url);
