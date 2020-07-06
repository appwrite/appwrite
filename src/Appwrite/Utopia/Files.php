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

            if (in_array($path, ['.', '..'])) {
                continue;
            }

            if (in_array(pathinfo($path, PATHINFO_EXTENSION), ['php', 'phtml'])) {
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

            self::$loaded[substr($directory.'/'.$path , strlen($root))] = file_get_contents($directory.'/'.$path);
        }

        closedir($handle);

        if($directory === $root) {
            echo '[Static Files] Loadded '.self::$count.' files'.PHP_EOL;
        }
    }
}
