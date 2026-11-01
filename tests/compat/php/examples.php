<?php

/**
 * Runs the PHP examples `bin/compat examples` wrote under a directory, each
 * in its own process as a reader would run it (`php example.php` next to
 * Composer's `vendor/`), and prints the results as JSON lines.
 */

$dir = $argv[1] ?? '/tmp/compat-fs/examples';
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
$scripts = [];
foreach ($files as $file) {
    if ($file->getExtension() === 'php') {
        $scripts[] = $file->getPathname();
    }
}
sort($scripts);

foreach ($scripts as $script) {
    // `require __DIR__ . '/vendor/autoload.php'` finds this checkout's dependencies.
    $vendor = dirname($script) . '/vendor';
    if (!file_exists($vendor)) {
        symlink('/usr/src/code/vendor', $vendor);
    }
    $process = proc_open(
        ['php', '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL', $script],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname($script),
    );
    if (!is_resource($process)) {
        echo json_encode(['file' => $script, 'ok' => false, 'error' => 'cannot start php']), "\n";
        continue;
    }
    // Read both pipes until the program exits or runs out of time (a deadlock must not hang the run).
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + 30;
    $timedOut = false;
    while (true) {
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        if (!proc_get_status($process)['running']) {
            break;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($process, 9);
            $timedOut = true;
            break;
        }
        usleep(10_000);
    }
    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    // Swoole reports a deadlock or an uncaught coroutine error on stdout and still exits with 0.
    $fatal = str_contains($stdout, '[FATAL ERROR]') || str_contains($stdout, 'deadlock') || str_contains($stdout, 'PHP Fatal error');
    echo json_encode([
        'file' => $script,
        'ok' => !$timedOut && !$fatal && $code === 0 && trim($stderr) === '',
        'timed_out' => $timedOut,
        'exit' => $code,
        'stdout' => mb_substr($stdout, 0, 2000),
        'stderr' => mb_substr($stderr, 0, 2000),
    ], JSON_INVALID_UTF8_SUBSTITUTE), "\n";
}
