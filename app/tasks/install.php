<?php

global $cli;

use Appwrite\Docker\Compose;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\View;

$cli
    ->task('install')
    ->desc('Install Appwrite')
    ->action(function () {
        /**
         * 1. Start - DONE
         * 2. Check for older setup and get older version - DONE
         *  2.1 If older version is equal or bigger(?) than current version, **stop setup**
         *  2.2. Get ENV vars - DONE
         *   2.2.1 Fetch from older docker-compose.yml file
         *   2.2.2 Fetch from older .env file (manually parse)
         *  2.3 Use old ENV vars as default values
         *  2.4 Ask for all required vars not given as CLI args and if in interactive mode
         *      Otherwise, just use default vars. - DONE
         * 3. Ask user to backup important volumes, env vars, and SQL tables
         *      In th future we can try and automate this for smaller/medium size setups 
         * 4. Drop new docker-compose.yml setup (located inside the container, no network dependencies with appwrite.io) - DONE
         * 5. Run docker-compose up -d - DONE
         * 6. Run data migration
         */
        $vars = Config::getParam('variables');
        $path = '/usr/src/code/appwrite';
        $version = null;
        
        Console::success('Starting Appwrite installation...');

        // Create directory with write permissions
        if (null !== $path && !\file_exists(\dirname($path))) {
            if (!@\mkdir(\dirname($path), 0755, true)) {
                Console::error('Can\'t create directory '.\dirname($path));
                exit(1);
            }
        }

        $data = @file_get_contents($path.'/docker-compose.yml');

        if($data !== false) {
            $compose = new Compose($data);
            $service = $compose->getService('appwrite');
            $version = ($service) ? $service->getImageVersion() : $version;

            if($version) {
                foreach($compose->getServices() as $service) { // Fetch all env vars from previous compose file
                    if(!$service) {
                        continue;
                    }

                    $env = $service->getEnvironment()->list();

                    var_dump($env);
                }

                 // Fetch all env vars from previous .env file
            }
        }

        $httpPort = Console::confirm('Choose your server HTTP port: (default: 80)');
        $httpPort = ($httpPort) ? $httpPort : 80;

        $httpsPort = Console::confirm('Choose your server HTTPS port: (default: 443)');
        $httpsPort = ($httpsPort) ? $httpsPort : 443;
    
        $input = [];

        foreach($vars as $key => $var) {
            if(!$var['required']) {
                $input[$var['name']] = $var['default'];
                continue;
            }

            $input[$var['name']] = Console::confirm($var['question'].' (default: \''.$var['default'].'\')');

            if(empty($input[$key])) {
                $input[$var['name']] = $var['default'];
            }
        }

        $templateForCompose = new View(__DIR__.'/../views/install/compose.phtml');
        $templateForEnv = new View(__DIR__.'/../views/install/env.phtml');
        
        $templateForCompose
            ->setParam('httpPort', $httpPort)
            ->setParam('httpsPort', $httpsPort)
            ->setParam('version', APP_VERSION_STABLE)
        ;
        
        $templateForEnv
            ->setParam('vars', $input)
        ;

        if(!file_put_contents($path.'/docker-compose.yml', $templateForCompose->render(false))) {
            Console::error('Failed to save Docker Compose file');
            exit(1);
        }

        if(!file_put_contents($path.'/.env', $templateForEnv->render(false))) {
            Console::error('Failed to save environment variables file');
            exit(1);
        }

        $stdout = '';
        $stderr = '';

        Console::execute("docker-compose -f {$path}.'/docker-compose.yml up -d --remove-orphans", null, $stdout, $stderr);

        if ($stderr !== '') {
            Console::error("Failed to install Appwrite dockers");
        } else {
            Console::success("Appwrite installed successfully");
        }

        $files1 = scandir($path);

        var_dump($files1);
        var_dump(file_get_contents($path.'/.env'));
        var_dump(file_get_contents($path.'/docker-compose.yml'));
    });