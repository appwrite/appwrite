<?php

namespace Appwrite\Utopia;

use Exception;

class Files
{
    /**
     * @var array
     */
    static protected $loaded = [];
    
    /**
     * @var int
     */
    static protected $count = 0;

    /**
     * @var array
     */
    static protected $mimeTypes = [];

    /**
     * @var array
     */
    static protected $extensions = [
        'css' => 'text/css',
        'js' => 'text/javascript',
        'svg' => 'image/svg+xml',
    ];

    /**
     * Add MimeType
     * 
     * @var string $mimeType
     */
    public static function addMimeType(string $mimeType)
    {
        self::$mimeTypes[$mimeType] = true;
    }

    /**
     * Remove MimeType
     * 
     * @var string $mimeType
     */
    public static function removeMimeType(string $mimeType)
    {
        if(isset(self::$mimeTypes[$mimeType])) {
            unset(self::$mimeTypes[$mimeType]);
        }
    }

    /**
     * Get MimeType List
     * 
     * @return array
     */
    public static function getMimeTypes(): array
    {
        return self::$mimeTypes;
    }

    /**
     * Get Files Loaded Count
     * 
     * @return int
     */
    public static function getCount(): int
    {
        return self::$count;
    }

    /**
     * Load
     * 
     * @var string $path
     */
    public static function load(string $directory, string $root = null)
    {
        if(!is_readable($directory)) {
            throw new Exception('Failed to load directory: '.$directory);
        }

        $directory = realpath($directory);

        $root = ($root) ? $root : $directory;

        $handle = opendir($directory);

        while ($path = readdir($handle)) {
            $extension = pathinfo($path, PATHINFO_EXTENSION);

            if (in_array($path, ['.', '..'])) {
                continue;
            }

            if (in_array($extension, ['php', 'phtml'])) {
                continue;
            }

            if(substr($path, 0, 1) === '.') {
                continue;
            }
            
            if (is_dir($directory.'/'.$path)) {
                self::load($directory.'/'.$path, $root);
                continue;
            }
    
            self::$count++;

            self::$loaded[substr($directory.'/'.$path , strlen($root))] = [
                'contents' => file_get_contents($directory.'/'.$path),
                'mimeType' => (array_key_exists($extension, self::$extensions))
                    ? self::$extensions[$extension]
                    : mime_content_type($directory.'/'.$path)
                ];
        }

        closedir($handle);

        if($directory === $root) {
            echo '[Static Files] Loadded '.self::$count.' files'.PHP_EOL;
        }
    }

    /**
     * Is File Loaded
     * 
     * @var string $uri
     */
    public static function isFileLoaded(string $uri): bool
    {
        if(!array_key_exists($uri, self::$loaded)) {
            return false;
        }

        return true;
    }

    /**
     * Get File Contants
     * 
     * @var string $uri
     */
    public static function getFileContents(string $uri): string
    {
        if(!array_key_exists($uri, self::$loaded)) {
            throw new Exception('File not found or not loaded: '.$uri);
        }

        return self::$loaded[$uri]['contents'];
    }

    /**
     * Get File MimeType
     * 
     * @var string $uri
     */
    public static function getFileMimeType(string $uri): string
    {
        if(!array_key_exists($uri, self::$loaded)) {
            throw new Exception('File not found or not loaded: '.$uri);
        }

        return self::$loaded[$uri]['mimeType'];
    }
}
