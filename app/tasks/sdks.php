#!/bin/env php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Utopia\CLI\CLI;
use Utopia\CLI\Console;
use Appwrite\Spec\Swagger2;
use Appwrite\SDK\SDK;
use Appwrite\SDK\Language\PHP;
use Appwrite\SDK\Language\JS;
use Appwrite\SDK\Language\Node;
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

        $spec   = getSSLPage('https://appwrite.io/v1/open-api-2.json?extensions=1');
        $spec   = getSSLPage('https://appwrite.test/v1/open-api-2.json?extensions=1');

        $clients = [
            'php' => [
                'version'       => 'v1.0.6',
                'result'        => __DIR__ . '/../sdks/php/',
                'gitURL'        => 'https://github.com/appwrite/sdk-for-php.git',
                'gitRepo'       => 'git@github.com:appwrite/sdk-for-php.git',
                'gitRepoName'   => 'sdk-for-php',
                'gitUserName'   => 'appwrite',
            ],
            'js' => [
                'version'       => 'v1.0.19',
                'result'        => __DIR__ . '/../sdks/js/',
                'gitURL'        => 'https://github.com/appwrite/sdk-for-js.git',
                'gitRepo'       => 'git@github.com:appwrite/sdk-for-js.git',
                'gitRepoName'   => 'sdk-for-js',
                'gitUserName'   => 'appwrite',
            ],
            'node' => [
                'version'       => 'v1.0.23',
                'result'        => __DIR__ . '/../sdks/node/',
                'gitURL'        => 'https://github.com/appwrite/sdk-for-node.git',
                'gitRepo'       => 'git@github.com:appwrite/sdk-for-node.git',
                'gitRepoName'   => 'sdk-for-node',
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
                case 'node':
                    $language = new Node();
                    $language
                        ->setNPMPackage('node-appwrite')
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
                ->setLicense('BSD-3-Clause')
                ->setLicenseContent("Copyright (c) 2019 Appwrite (https://appwrite.io) and individual contributors.
All rights reserved.

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

    1. Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.

    2. Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.

    3. Neither the name Appwrite nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS \"AS IS\" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.")
                ->setVersion($client['version'])
                ->setGitRepo($client['gitRepo'])
                ->setGitURL($client['gitURL'])
                ->setGitRepoName($client['gitRepoName'])
                ->setGitUserName($client['gitUserName'])
                ->setLogo('https://appwrite.io/images/github.png')
                ->setURL('https://appwrite.io')
                ->setShareText('Appwrite is a backend as a service for building web or mobile apps')
                ->setShareURL('http://appwrite.io')
                ->setShareTags('JS,javascript,reactjs,angular,ios,android')
                ->setShareVia('appwrite_io')
                //->setWarning('**WORK IN PROGRESS - NOT READY FOR USAGE**')
                ->setWarning('')
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