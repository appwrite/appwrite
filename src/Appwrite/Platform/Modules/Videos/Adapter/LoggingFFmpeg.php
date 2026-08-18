<?php

namespace Appwrite\Platform\Modules\Videos\Adapter;

use Utopia\Console;
use Utopia\Video\Adapter\FFmpeg;

/**
 * FFmpeg adapter that prints the exact argv before each invocation.
 *
 * Used by the videos worker so docker logs show the pack/encode/tile command
 * the Utopia Video packager and encoder send to ffmpeg.
 */
final class LoggingFFmpeg extends FFmpeg
{
    /**
     * @param  list<string>  $args
     */
    protected function execute(array $args, float $duration): void
    {
        Console::info('Videos worker: ffmpeg command: ' . self::formatCommand($args));

        parent::execute($args, $duration);
    }

    /**
     * @param  list<string>  $args
     */
    private static function formatCommand(array $args): string
    {
        return \implode(' ', \array_map(
            static fn (string $arg): string => \escapeshellarg($arg),
            $args
        ));
    }
}
