<?php

namespace Utopia\Http;

use Exception;

class Files
{
    /**
     * @var array<string, mixed>
     */
    protected array $loaded = [];

    protected int $count = 0;

    /**
     * @var array<string, mixed>
     */
    protected array $mimeTypes = [];

    /**
     * @var array<string, mixed>
     */
    public const array EXTENSIONS = [
        'css' => 'text/css',
        'js' => 'text/javascript',
        'svg' => 'image/svg+xml',
    ];

    /**
     * Add MIME type.
     */
    public function addMimeType(string $mimeType): void
    {
        $this->mimeTypes[$mimeType] = true;
    }

    /**
     * Remove MIME type.
     */
    public function removeMimeType(string $mimeType): void
    {
        if (isset($this->mimeTypes[$mimeType])) {
            unset($this->mimeTypes[$mimeType]);
        }
    }

    /**
     * Get MimeType List
     *
     * @return array<string, mixed>
     */
    public function getMimeTypes(): array
    {
        return $this->mimeTypes;
    }

    /**
     * Get Files Loaded Count
     */
    public function getCount(): int
    {
        return $this->count;
    }

    /**
     * Load directory.
     *
     *
     * @throws \Exception
     */
    public function load(string $directory, ?string $root = null): void
    {
        if (!is_readable($directory)) {
            throw new Exception("Failed to load directory: {$directory}");
        }

        $directory = realpath($directory);

        $root ??= $directory;

        $handle = opendir(\strval($directory));

        if ($handle === false) {
            throw new Exception("Failed to open directory: {$directory}");
        }

        while ($path = readdir($handle)) {
            $extension = pathinfo($path, PATHINFO_EXTENSION);

            if (\in_array($path, ['.', '..'])) {
                continue;
            }

            if (\in_array($extension, ['php', 'phtml'])) {
                continue;
            }

            if (str_starts_with($path, '.')) {
                continue;
            }

            $dirPath = $directory . '/' . $path;

            if (is_dir($dirPath)) {
                $this->load($dirPath, \strval($root));

                continue;
            }

            $key = substr($dirPath, \strlen(\strval($root)));

            if (\array_key_exists($key, $this->loaded)) {
                continue;
            }

            $this->loaded[$key] = [
                'contents' => file_get_contents($dirPath),
                'mimeType' => (\array_key_exists($extension, self::EXTENSIONS))
                    ? self::EXTENSIONS[$extension]
                    : mime_content_type($dirPath),
            ];

            $this->count++;
        }

        closedir($handle);
    }

    /**
     * Is file loaded.
     */
    public function isFileLoaded(string $uri): bool
    {
        return \array_key_exists($uri, $this->loaded);
    }

    /**
     * Get file contents.
     *
     * @return string
     * @throws \Exception
     */
    public function getFileContents(string $uri): mixed
    {
        if (!\array_key_exists($uri, $this->loaded)) {
            throw new Exception('File not found or not loaded: ' . $uri);
        }

        return $this->loaded[$uri]['contents'];
    }

    /**
     * Get file MIME type.
     *
     * @return string
     * @throws \Exception
     */
    public function getFileMimeType(string $uri): mixed
    {
        if (!\array_key_exists($uri, $this->loaded)) {
            throw new Exception('File not found or not loaded: ' . $uri);
        }

        return $this->loaded[$uri]['mimeType'];
    }

    /**
     * Reset.
     */
    public function reset(): void
    {
        $this->count = 0;
        $this->loaded = [];
        $this->mimeTypes = [];
    }
}
