<?php

// ruleid: php.appwrite.unsafe-dynamic-code
eval($code);

// ruleid: php.appwrite.unsafe-dynamic-code
$fn = create_function('$a', 'return $a;');

// ruleid: php.appwrite.unsafe-dynamic-code
assert('$value > 0');

// ruleid: php.appwrite.unsafe-dynamic-code
extract($payload);

// ruleid: php.appwrite.unsafe-dynamic-code
parse_str($query);

// ruleid: php.appwrite.unsafe-dynamic-code
$object = unserialize($blob);

// ruleid: php.appwrite.unsafe-dynamic-code
$object = \unserialize($blob);

// ruleid: php.appwrite.unsafe-dynamic-code
$object = unserialize($blob, ['allowed_classes' => true]);

// ok: php.appwrite.unsafe-dynamic-code
$array = unserialize($blob, ['allowed_classes' => false]);

// ok: php.appwrite.unsafe-dynamic-code
parse_str($query, $params);

// ok: php.appwrite.unsafe-dynamic-code
$data = json_decode($blob, true);

// ok: php.appwrite.unsafe-dynamic-code
assert($value > 0);
