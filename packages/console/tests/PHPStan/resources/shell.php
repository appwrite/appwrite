<?php

declare(strict_types=1);

use Utopia\Console\Command;
use Utopia\Console\Console;

/**
 * @param list<string> $argv
 */
function shell(string $directory, array $argv): void
{
    $stdout = '';
    $stderr = '';
    $pipes = [];

    exec('rm -rf ' . $directory);
    shell_exec('ls');
    system('ls');
    passthru('ls');
    popen('ls', 'r');
    escapeshellarg($directory);
    escapeshellcmd('ls');
    array_map(escapeshellarg(...), $argv);
    proc_open('ls ' . $directory, [], $pipes);
    Console::execute('rm -rf ' . $directory, '', $stdout, $stderr);
    \Utopia\Console::execute('ls', '', $stdout, $stderr);
    proc_open(descriptor_spec: [], command: 'ls ' . $directory, pipes: $pipes);
    Console::execute(stdin: '', cmd: 'ls', stdout: $stdout, stderr: $stderr);
    Console::execute($directory === '' ? 'ls' : new Command('ls'), '', $stdout, $stderr);

    // Allowed: no value ever reaches a shell unescaped
    proc_open($argv, [], $pipes);
    proc_open(descriptor_spec: [], command: $argv, pipes: $pipes);
    Console::execute(new Command('rm')->flag('-rf')->argument($directory), '', $stdout, $stderr);
    Console::execute($argv, '', $stdout, $stderr);
    \strlen($directory);
}
