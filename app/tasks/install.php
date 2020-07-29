<?php

global $cli;

use Utopia\CLI\Console;
use Utopia\Config\Config;

$cli
    ->task('install')
    ->desc('Install Appwrite')
    ->action(function () {
        /**
         * 1. Start - DONE
         * 2. Check for older setup and get older version
         *  2.1 If older version is equal or bigger(?) than current version, **stop setup**
         *  2.2. Get ENV vars
         *   2.2.1 Fetch from older docker-compose.yml file
         *   2.2.2 Fetch from older .env file (manually parse)
         *  2.3 Use old ENV vars as default values 
         *  2.4 Ask for all required vars not given as CLI args and if in interactive mode
         *      Otherwise, just use default vars. - DONE
         * 3. Ask user to backup important volumes, env vars, and SQL tables
         *      In th future we can try and automate this for smaller/medium size setups 
         * 4. Drop new docker-compose.yml setup (located inside the container, no network dependencies with appwrite.io)
         * 5. Run docker-compose up -d
         * 6. Run data migration
         */

        $vars = Config::getParam('variables');

        // var_dump(realpath(__DIR__.'/docker-compose.yml'));
        // var_dump(yaml_parse_file(__DIR__.'/docker-compose.yml'));
        
        Console::success('Starting Appwrite installation...');

        if(!empty($httpPort)) {
            $httpPort = Console::confirm('Choose your server HTTP port: (default: 80)');
            $httpPort = ($httpPort) ? $httpPort : 80;
        }
        
        if(!empty($httpsPort)) {
            $httpsPort = Console::confirm('Choose your server HTTPS port: (default: 443)');
            $httpsPort = ($httpsPort) ? $httpsPort : 443;
        }
        
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

        var_dump($input);
        
        // $composeUrl = $source.'/docker-compose.yml?'.http_build_query([
        //     'version' => $version,
        //     'domain' => $domain,
        //     'httpPort' => $httpPort,
        //     'httpsPort' => $httpsPort,
        //     'target' => $target,
        // ]);

        // $composeFile = @file_get_contents($composeUrl);

        // if(!$composeFile) {
        //     throw new Exception('Failed to fetch Docker Compose file');
        // }
        
        // if(!file_put_contents('/install/appwrite/docker-compose.yml', $composeFile)) {
        //     throw new Exception('Failed to save Docker Compose file');
        // }

        $stdout = '';
        $stderr = '';

        Console::execute('docker-compose -f /install/appwrite/docker-compose.yml up -d', null, $stdout, $stderr);
        if ($stdout != NULL) {
            Console::error("Failed to install Appwrite dockers");
        } else {
            Console::success("Appwrite installed successfully");
        }
    });