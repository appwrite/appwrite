<?php

namespace Appwrite\Functions;

/**
 * Resolves the runtime start command used when launching a function/site execution.
 */
final class StartCommand
{
    private const string SOURCE_DIR = '/usr/local/server/src/function/';

    /**
     * Resolve the command passed to helpers/start.sh.
     *
     * Framework and runtime defaults use relative paths such as
     * `bash helpers/server.sh`, which must be resolved from `/usr/local/server`.
     * Console creation flows persist that same default onto the deployment. The
     * previous path always wrapped a non-empty deployment startCommand with
     * `cd .../src/function`, which broke relative helper paths and caused the
     * runtime to crash-loop until the request timed out (HTTP 408).
     *
     * Only truly custom deployment start commands are prefixed with a cd into
     * the function source directory.
     */
    public static function resolve(string $defaultCommand, string $deploymentCommand): string
    {
        if ($deploymentCommand === '') {
            return $defaultCommand;
        }

        if ($deploymentCommand === $defaultCommand) {
            return $defaultCommand;
        }

        $escaped = \str_replace(['"', '`', '$'], ['\\"', '\\`', '\\$'], $deploymentCommand);

        return 'cd ' . self::SOURCE_DIR . ' && ' . $escaped;
    }
}
