<?php

namespace Appwrite\Docker\Compose;

/**
 * The compose files Docker Compose loads for a project directory: the main file,
 * then its override when one sits next to it. Passing `-f` turns Compose's own
 * override discovery off, so callers that pass `-f` must pass every file here.
 */
readonly class Files
{
    private const array EXTENSIONS = ['.yml', '.yaml'];

    public function __construct(
        private string $directory,
        private string $main,
    ) {
    }

    /**
     * @return string[] File names relative to the directory, in load order.
     */
    public function names(): array
    {
        $names = [$this->main];
        $base = $this->main;
        foreach (self::EXTENSIONS as $extension) {
            if (str_ends_with($base, $extension)) {
                $base = substr($base, 0, -strlen($extension));
                break;
            }
        }
        foreach (self::EXTENSIONS as $extension) {
            $override = $base . '.override' . $extension;
            if (is_file($this->directory . '/' . $override)) {
                $names[] = $override;
                break;
            }
        }

        return $names;
    }
}
