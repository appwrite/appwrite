<?php

$mainRepoRoot = realpath(__DIR__ . '/../../../../appwrite');
$worktreeRoot = realpath(__DIR__ . '/../..');

require_once $mainRepoRoot . '/vendor/autoload.php';

// The worktree uses utopia-php/cli 0.15.* which has Utopia\CLI\Console,
// but the main repo has utopia-php/console which uses Utopia\Console.
// Create an alias for test compatibility.
if (!class_exists('Utopia\CLI\Console') && class_exists('Utopia\Console')) {
    class_alias('Utopia\Console', 'Utopia\CLI\Console');
}

// Prepend the worktree's src directory so its classes take priority
spl_autoload_register(function ($class) use ($worktreeRoot) {
    $prefixes = [
        'Appwrite\\' => '/src/Appwrite/',
        'Executor\\' => '/src/Executor/',
        'Utopia\\Bus\\' => '/src/Utopia/Bus/',
    ];

    foreach ($prefixes as $prefix => $dir) {
        if (strpos($class, $prefix) === 0) {
            $relativeClass = substr($class, strlen($prefix));
            $file = $worktreeRoot . $dir . str_replace('\\', '/', $relativeClass) . '.php';
            if (file_exists($file)) {
                require_once $file;
                return true;
            }
        }
    }
    return false;
}, true, true);

// Load constants needed by the worker
require_once $worktreeRoot . '/app/init/constants.php';
