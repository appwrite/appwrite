<?php

// Prints a project's internal $sequence — it never appears in API responses.
// Runs inside the appwrite container as its own process, because the platform
// DB pools need a coroutine and Swoole's coroutine hooks would break curl in
// the phpunit process:
//
//   php tests/resources/functions/project-sequence.php <projectId>

require_once __DIR__ . '/../../../app/init.php';

use Utopia\Database\Validator\Authorization;

use function Swoole\Coroutine\run;

global $register;

$projectId = $argv[1] ?? '';

if ($projectId === '') {
    fwrite(STDERR, 'usage: project-sequence.php <projectId>' . PHP_EOL);
    exit(1);
}

$authorization = new Authorization();

$authorization->skip(function () use ($register, $authorization, $projectId): void {
    run(function () use ($register, $authorization, $projectId): void {
        // The pools are coroutine-backed, so the factory can only be built here.
        $factory = $register->get('databaseFactory')($register->get('pools'), $register->get('cache'), $authorization);
        $project = $factory->platform()->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            fwrite(STDERR, 'project not found: ' . $projectId . PHP_EOL);
            exit(1);
        }

        echo $project->getSequence(), PHP_EOL;
    });
});
