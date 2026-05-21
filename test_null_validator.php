<?php
require 'vendor/autoload.php';

use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

try {
    echo "--- Testing Old Behavior (Text only) ---\n";
    $oldValidator = new Text(128);
    echo "Passes null? " . ($oldValidator->isValid(null) ? 'YES' : 'NO') . "\n";
} catch (\Throwable $th) {
    echo "Error on null: " . $th->getMessage() . "\n";
}

echo "\n--- Testing New Behavior (Nullable + Text) ---\n";
$newValidator = new Nullable(new Text(128));
echo "Passes null? " . ($newValidator->isValid(null) ? 'YES' : 'NO') . "\n";
echo "Passes 'Aditya'? " . ($newValidator->isValid('Aditya') ? 'YES' : 'NO') . "\n";
echo "Passes '' (empty)? " . ($newValidator->isValid('') ? 'YES' : 'NO') . "\n";
