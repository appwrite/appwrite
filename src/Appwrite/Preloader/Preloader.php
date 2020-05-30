<?php

namespace Appwrite\Preloader;

class Preloader
{
    /**
     * @var array
     */
    protected $ignores = [];

    private static $count = 0;

    private $paths;

    private $fileMap;

    public function __construct(string ...$paths)
    {
        $this->paths = $paths;

        // We'll use composer's classmap
        // to easily find which classes to autoload,
        // based on their filename
        $classMap = require __DIR__ . '/../../../vendor/composer/autoload_classmap.php';
                
        $this->fileMap = array_flip($classMap);
    }
    
    public function paths(string ...$paths): Preloader
    {
        $this->paths = array_merge(
            $this->paths,
            $paths
        );

        return $this;
    }

    public function ignore(string ...$names): Preloader
    {
        $this->ignores = array_merge(
            $this->ignores,
            $names
        );

        return $this;
    }

    public function load(): void
    {
        // We'll loop over all registered paths
        // and load them one by one
        foreach ($this->paths as $path) {
            $this->loadPath(rtrim($path, '/'));
        }

        $count = self::$count;

        echo "[Preloader] Preloaded {$count} classes" . PHP_EOL;
    }

    private function loadPath(string $path): void
    {
        // If the current path is a directory,
        // we'll load all files in it 
        if (is_dir($path)) {
            $this->loadDir($path);

            return;
        }

        // Otherwise we'll just load this one file
        $this->loadFile($path);
    }

    private function loadDir(string $path): void
    {
        $handle = opendir($path);

        // We'll loop over all files and directories
        // in the current path,
        // and load them one by one
        while ($file = readdir($handle)) {
            if (in_array($file, ['.', '..'])) {
                continue;
            }

            $this->loadPath("{$path}/{$file}");
        }

        closedir($handle);
    }

    private function loadFile(string $path): void
    {
        // We resolve the classname from composer's autoload mapping
        $class = $this->fileMap[$path] ?? $path;
        
        // And use it to make sure the class shouldn't be ignored
        if ($this->shouldIgnore($class)) {
            return;
        }
        echo "[Preloader] Preloaded `{$class}`" . PHP_EOL;
        // Finally we require the path,
        // causing all its dependencies to be loaded as well
        try {
            ob_start(); //Start of build

            require_once $path;

            $output = mb_strlen(ob_get_contents());
    
            ob_end_clean(); //End of build
        } catch (\Throwable $th) {
            echo "[Preloader] Failed to load `{$class}`" . PHP_EOL;
            return;
        }

        self::$count++;

        
    }

    private function shouldIgnore(?string $name): bool
    {
        if($name === null) {
            return true;
        }

        if(!in_array(pathinfo($name, PATHINFO_EXTENSION), ['php'])) {
            return true;
        }

        var_dump($name);
        foreach ($this->ignores as $ignore) {
            if (strpos($name, $ignore) === 0) {
                return true;
            }
        }

        return false;
    }
}