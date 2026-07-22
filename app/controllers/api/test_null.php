<?php
require '/usr/src/code/vendor/autoload.php';

use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

try {
    echo "Testing New Behavior (Nullable + Text):\n";
    $newValidator = new Nullable(new Text(128));
    $result = $newValidator->isValid(null);
    echo "Passes null? " . ($result ? 'YES' : 'NO') . "\n";
    
    echo "\nTesting Old Behavior (Text only):\n";
    $oldValidator = new Text(128);
    $oldResult = $oldValidator->isValid(null);
    echo "Passes null? " . ($oldResult ? 'YES' : 'NO') . "\n";
} catch (\Throwable $th) {
    echo "Error on null: " . $th->getMessage() . "\n";
}
