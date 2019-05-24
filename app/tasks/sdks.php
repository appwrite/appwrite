#!/bin/env php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Utopia\CLI\CLI;
use Utopia\CLI\Console;
use Appwrite\Spec\Swagger2;
use Appwrite\SDK\SDK;
use Appwrite\SDK\Language\PHP;
use Appwrite\SDK\Language\JS;
use Appwrite\SDK\Language\Python;
use Appwrite\SDK\Language\Ruby;

$cli = new CLI();

$cli
    ->task('generate')
    ->action(function () {

        function getSSLPage($url) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $result = curl_exec($ch);
            curl_close($ch);
            return $result;
        }

        Console::success('Fetching API Spec');

        $spec   = getSSLPage('https://appwrite.test/v1/open-api-2.json');
        $spec   = getSSLPage('https://appwrite.io/v1/open-api-2.json');

        $clients = [
            'php' => [
                'version'       => 'v1.0.0',
                'result'        => __DIR__ . '/../sdks/php/',
                'gitURL'        => 'https://github.com/appwrite/sdk-for-php.git',
                'gitRepo'       => 'git@github.com:appwrite/sdk-for-php.git',
                'gitRepoName'   => 'sdk-for-php',
                'gitUserName'   => 'appwrite',
            ],
            'js' => [
                'version'       => 'v1.0.0',
                'result'        => __DIR__ . '/../sdks/js/',
                'gitURL'        => 'https://github.com/appwrite/sdk-for-js.git',
                'gitRepo'       => 'git@github.com:appwrite/sdk-for-js.git',
                'gitRepoName'   => 'sdk-for-js',
                'gitUserName'   => 'appwrite',
            ],
            'python' => [
                'version'       => 'v1.0.0',
                'result'        => __DIR__ . '/../sdks/python/',
                'gitURL'        => 'https://github.com/appwrite/sdk-for-python.git',
                'gitRepo'       => 'git@github.com:appwrite/sdk-for-python.git',
                'gitRepoName'   => 'sdk-for-python',
                'gitUserName'   => 'appwrite',
            ],
            'ruby' => [
                'version'       => 'v1.0.0',
                'result'        => __DIR__ . '/../sdks/ruby/',
                'gitURL'        => 'https://github.com/appwrite/sdk-for-ruby.git',
                'gitRepo'       => 'git@github.com:appwrite/sdk-for-ruby.git',
                'gitRepoName'   => 'sdk-for-ruby',
                'gitUserName'   => 'appwrite',
            ],
        ];

        foreach ($clients as $name => $client) {
            switch ($name) {
                case 'php':
                    $language = new PHP();
                    $language
                        ->setComposerVendor('appwrite')
                        ->setComposerPackage('appwrite')
                    ;
                    break;
                case 'js':
                    $language = new JS();
                    $language
                        ->setNPMPackage('appwrite')
                        ->setBowerPackage('appwrite')
                    ;
                    break;
                case 'python':
                    $language = new Python();
                    $language
                        ->setPipPackage('appwrite')
                    ;
                    break;
                case 'ruby':
                    $language = new Ruby();
                    $language
                        ->setGemPackage('appwrite')
                    ;
                    break;
                default:
                    throw new Exception('Language not supported');
                    break;
            }

            $sdk = new SDK($language, new Swagger2($spec));

            $sdk
                ->setLicense('MIT')
                ->setLicenseContent("The MIT License (MIT)

Copyright (c) 2019 Appwrite

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the \"Software\"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED \"AS IS\", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.")
                ->setVersion($client['version'])
                ->setGitRepo($client['gitRepo'])
                ->setGitURL($client['gitURL'])
                ->setGitRepoName($client['gitRepoName'])
                ->setGitUserName($client['gitUserName'])
                ->setLogo('https://appwrite.io/v1/images/github.png')
                ->setURL('https://appwrite.io')
                ->setShareText('Appwrite is a backend as a service for building web or mobile apps')
                ->setShareURL('http://appwrite.io')
                ->setShareTags('JS,javascript,reactjs,angular,ios,android')
                ->setShareVia('appwrite_io')
                ->setWarning('**WORK IN PROGRESS - NOT READY FOR USAGE**')
            ;

            $target = __DIR__ . '/../sdks/git/' . $name;

            Console::success("Generating {$name} SDK");

            try {
                $sdk->generate($client['result']);
            }
            catch (Exception $exception) {
                echo $exception->getMessage() . "\n";
            }
            catch (Throwable $exception) {
                echo $exception->getMessage() . "\n";
            }

            exec('rm -rf ' .$target . ' && \
                mkdir -p ' . $target . ' && \
                cd ' . $target . ' && \
                git init && \
                git remote add origin ' . $client['gitRepo'] . ' && \
                git fetch && \
                git pull ' . $client['gitRepo'] . ' && \
                rm -rf ' . $target . '/* && \
                cp -r ' . $client['result'] .' ' . $target . ' && \
                git add . && \
                git commit -m "Initial commit" && \
                git push -u origin master');

            Console::success("Pushing {$name} SDK to {$client['gitRepo']}");

            exec('rm -rf ' . $target);

            Console::success("Remove temp directory '{$target}' for {$name} SDK");
        }
    });

$cli->run();