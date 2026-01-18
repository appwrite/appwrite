<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\SDK\Language\Android;
use Appwrite\SDK\Language\Apple;
use Appwrite\SDK\Language\CLI;
use Appwrite\SDK\Language\Dart;
use Appwrite\SDK\Language\Deno;
use Appwrite\SDK\Language\DotNet;
use Appwrite\SDK\Language\Flutter;
use Appwrite\SDK\Language\Go;
use Appwrite\SDK\Language\GraphQL;
use Appwrite\SDK\Language\Kotlin;
use Appwrite\SDK\Language\Node;
use Appwrite\SDK\Language\PHP;
use Appwrite\SDK\Language\Python;
use Appwrite\SDK\Language\ReactNative;
use Appwrite\SDK\Language\REST;
use Appwrite\SDK\Language\Ruby;
use Appwrite\SDK\Language\Swift;
use Appwrite\SDK\Language\Web;
use Appwrite\SDK\SDK;
use Appwrite\Spec\Swagger2;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Platform\Action;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class SDKs extends Action
{
    public static function getName(): string
    {
        return 'sdks';
    }

    public function __construct()
    {
        $this
            ->desc('Generate Appwrite SDKs')
            ->param('platform', null, new Nullable(new Text(256)), 'Selected Platform', optional: true)
            ->param('sdk', null, new Nullable(new Text(256)), 'Selected SDK', optional: true)
            ->param('version', null, new Nullable(new Text(256)), 'Selected SDK', optional: true)
            ->param('git', null, new Nullable(new WhiteList(['yes', 'no'])), 'Should we use git push?', optional: true)
            ->param('production', null, new Nullable(new WhiteList(['yes', 'no'])), 'Should we push to production?', optional: true)
            ->param('message', null, new Nullable(new Text(256)), 'Commit Message', optional: true)
            ->param('release', null, new Nullable(new WhiteList(['yes', 'no'])), 'Should we create releases?', optional: true)
            ->param('commit', null, new Nullable(new WhiteList(['yes', 'no'])), 'Actually create releases (yes) or dry-run (no)?', optional: true)
            ->param('sdks', null, new Nullable(new Text(256)), 'Selected SDKs', optional: true)
            ->callback($this->action(...));
    }

    public function action(?string $selectedPlatform, ?string $selectedSDK, ?string $version, ?string $git, ?string $production, ?string $message, ?string $release, ?string $commit, ?string $sdks): void
    {
        if (!$sdks) {
            $selectedPlatform ??= Console::confirm('Choose Platform ("' . APP_SDK_PLATFORM_CLIENT . '", "' . APP_SDK_PLATFORM_SERVER . '", "' . APP_SDK_PLATFORM_CONSOLE . '" or "*" for all):');
            $selectedSDK ??= \strtolower(Console::confirm('Choose SDK ("*" for all):'));
        } else {
            $sdks = explode(',', $sdks);
        }
        $version ??= Console::confirm('Choose an Appwrite version');

        $createRelease = ($release === 'yes');
        $commitRelease =  ($commit === 'yes');

        if (!$createRelease) {
            $git ??= Console::confirm('Should we use git push? (yes/no)');
            $git = ($git === 'yes');

            $prUrls = [];
            $createPr = false;

            if ($git) {
                $production ??= Console::confirm('Type "Appwrite" to push code to production git repos');
                $production = $production === 'Appwrite';
                $message ??= Console::confirm('Please enter your commit message:');

                $createPr = Console::confirm('Should we create pull request automatically? (yes/no)');
                $createPr = ($createPr === 'yes');
            }
        }

        if (!\in_array($version, [
            '0.6.x',
            '0.7.x',
            '0.8.x',
            '0.9.x',
            '0.10.x',
            '0.11.x',
            '0.12.x',
            '0.13.x',
            '0.14.x',
            '0.15.x',
            '1.0.x',
            '1.1.x',
            '1.2.x',
            '1.3.x',
            '1.4.x',
            '1.5.x',
            '1.6.x',
            '1.7.x',
            '1.8.x',
            'latest'
        ])) {
            throw new \Exception('Unknown version given');
        }

        $platforms = Config::getParam('sdks');
        foreach ($platforms as $key => $platform) {
            if ($selectedPlatform !== $key && $selectedPlatform !== '*' && ($sdks === null)) {
                continue;
            }

            foreach ($platform['sdks'] as $language) {
                if ($selectedSDK !== $language['key'] && $selectedSDK !== '*' && ($sdks === null || !\in_array($language['key'], $sdks))) {
                    continue;
                }

                if (!$language['enabled']) {
                    Console::warning($language['name'] . ' for ' . $platform['name'] . ' is disabled');
                    continue;
                }

                Console::info('Fetching API Spec for ' . $language['name'] . ' for ' . $platform['name'] . ' (version: ' . $version . ')');

                $spec = file_get_contents(__DIR__ . '/../../../../app/config/specs/swagger2-' . $version . '-' . $language['family'] . '.json');

                $cover = 'https://github.com/appwrite/appwrite/raw/main/public/images/github.png';
                $result = \realpath(__DIR__ . '/../../../../app') . '/sdks/' . $key . '-' . $language['key'];
                $resultExamples = \realpath(__DIR__ . '/../../../..') . '/docs/examples/' . $version . '/' . $key . '-' . $language['key'];
                $target = \realpath(__DIR__ . '/../../../../app') . '/sdks/git/' . $language['key'] . '/';
                $readme = \realpath(__DIR__ . '/../../../../docs/sdks/' . $language['key'] . '/README.md');
                $readme = ($readme) ? \file_get_contents($readme) : '';
                $gettingStarted = \realpath(__DIR__ . '/../../../../docs/sdks/' . $language['key'] . '/GETTING_STARTED.md');
                $gettingStarted = ($gettingStarted) ? \file_get_contents($gettingStarted) : '';
                $examples = \realpath(__DIR__ . '/../../../../docs/sdks/' . $language['key'] . '/EXAMPLES.md');
                $examples = ($examples) ? \file_get_contents($examples) : '';
                $changelog = $language['changelog'] ?? '';
                $changelog = ($changelog) ? \file_get_contents($changelog) : '# Change Log';
                $warning = '**This SDK is compatible with Appwrite server version ' . $version . '. For older versions, please check [previous releases](' . $language['url'] . '/releases).**';
                $license = 'BSD-3-Clause';
                $licenseContent = 'Copyright (c) ' . date('Y') . ' Appwrite (https://appwrite.io) and individual contributors.
All rights reserved.

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

    1. Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.

    2. Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.

    3. Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.';

                switch ($language['key']) {
                    case 'web':
                        $config = new Web();
                        if ($platform['key'] === APP_SDK_PLATFORM_CONSOLE) {
                            $config->setNPMPackage('@appwrite.io/console');
                            $config->setBowerPackage('@appwrite.io/console');
                        } else {
                            $config->setNPMPackage('appwrite');
                            $config->setBowerPackage('appwrite');
                        }
                        break;
                    case 'cli':
                        $config = new CLI();
                        $config->setNPMPackage('appwrite-cli');
                        $config->setExecutableName('appwrite');
                        $config->setLogo(json_encode("
    _                            _ _           ___   __   _____
   /_\  _ __  _ ____      ___ __(_) |_ ___    / __\ / /   \_   \
  //_\\\| '_ \| '_ \ \ /\ / / '__| | __/ _ \  / /   / /     / /\/
 /  _  \ |_) | |_) \ V  V /| |  | | ||  __/ / /___/ /___/\/ /_
 \_/ \_/ .__/| .__/ \_/\_/ |_|  |_|\__\___| \____/\____/\____/
       |_|   |_|

"));
                        $config->setLogoUnescaped("
     _                            _ _           ___   __   _____
    /_\  _ __  _ ____      ___ __(_) |_ ___    / __\ / /   \_   \
   //_\\\| '_ \| '_ \ \ /\ / / '__| | __/ _ \  / /   / /     / /\/
  /  _  \ |_) | |_) \ V  V /| |  | | ||  __/ / /___/ /___/\/ /_
  \_/ \_/ .__/| .__/ \_/\_/ |_|  |_|\__\___| \____/\____/\____/
        |_|   |_|                                                ");
                        break;
                    case 'php':
                        $config = new PHP();
                        $config->setComposerVendor('appwrite');
                        $config->setComposerPackage('appwrite');
                        break;
                    case 'nodejs':
                        $config = new Node();
                        $config->setNPMPackage('node-appwrite');
                        $config->setBowerPackage('appwrite');
                        $warning = $warning . "\n\n > This is the Node.js SDK for integrating with Appwrite from your Node.js server-side code.
                            If you're looking to integrate from the browser, you should check [appwrite/sdk-for-web](https://github.com/appwrite/sdk-for-web)";
                        break;
                    case 'deno':
                        $config = new Deno();
                        break;
                    case 'python':
                        $config = new Python();
                        $config->setPipPackage('appwrite');
                        $license = 'BSD License'; // license edited due to classifiers in pypi
                        break;
                    case 'ruby':
                        $config = new Ruby();
                        $config->setGemPackage('appwrite');
                        break;
                    case 'flutter':
                        $config = new Flutter();
                        $config->setPackageName('appwrite');
                        break;
                    case 'react-native':
                        $config = new ReactNative();
                        $config->setNPMPackage('react-native-appwrite');
                        break;
                    case 'flutter-dev':
                        $config = new Flutter();
                        $config->setPackageName('appwrite_dev');
                        break;
                    case 'dart':
                        $config = new Dart();
                        $config->setPackageName('dart_appwrite');
                        $warning = $warning . "\n\n > This is the Dart SDK for integrating with Appwrite from your Dart server-side code. If you're looking for the Flutter SDK you should check [appwrite/sdk-for-flutter](https://github.com/appwrite/sdk-for-flutter)";
                        break;
                    case 'go':
                        $config = new Go();
                        break;
                    case 'swift':
                        $config = new Swift();
                        $warning = $warning . "\n\n > This is the Swift SDK for integrating with Appwrite from your Swift server-side code. If you're looking for the Apple SDK you should check [appwrite/sdk-for-apple](https://github.com/appwrite/sdk-for-apple)";
                        break;
                    case 'apple':
                        $config = new Apple();
                        break;
                    case 'dotnet':
                        $cover = '';
                        $config = new DotNet();
                        break;
                    case 'android':
                        $config = new Android();
                        break;
                    case 'kotlin':
                        $config = new Kotlin();
                        $warning = $warning . "\n\n > This is the Kotlin SDK for integrating with Appwrite from your Kotlin server-side code. If you're looking for the Android SDK you should check [appwrite/sdk-for-android](https://github.com/appwrite/sdk-for-android)";
                        break;
                    case 'graphql':
                        $config = new GraphQL();
                        break;
                    case 'rest':
                        $config = new REST();
                        break;
                    default:
                        throw new \Exception('Language "' . $language['key'] . '" not supported');
                }

                if ($createRelease) {
                    $releaseVersion = $language['version'];

                    $repoName = $language['gitUserName'] . '/' . $language['gitRepoName'];
                    $releaseNotes = $this->extractReleaseNotes($changelog, $releaseVersion);
                    if (empty($releaseNotes)) {
                        $releaseNotes = "Release version {$releaseVersion}";
                    }

                    $releaseTitle = $releaseVersion;
                    $releaseTarget = $language['repoBranch'] ?? 'main';

                    if ($repoName === '/') {
                        Console::warning("{$language['name']} SDK is not an SDK, skipping release");
                        continue;
                    }

                    // Check if release already exists
                    $checkReleaseCommand = 'gh release view "' . $releaseVersion . '" --repo "' . $repoName . '" --json url --jq ".url" 2>/dev/null';
                    $existingReleaseUrl = trim(\shell_exec($checkReleaseCommand) ?? '');

                    if (!empty($existingReleaseUrl)) {
                        Console::warning("Release {$releaseVersion} already exists for {$language['name']} SDK, skipping...");
                        Console::info("Existing release: {$existingReleaseUrl}");
                        continue;
                    }

                    // Check if the latest commit on the target branch already has a release
                    $latestCommitCommand = 'gh api repos/' . $repoName . '/commits/' . $releaseTarget . ' --jq ".sha" 2>/dev/null';
                    $latestCommitSha = trim(\shell_exec($latestCommitCommand) ?? '');

                    if (!empty($latestCommitSha)) {
                        $latestReleaseTagCommand = 'gh api repos/' . $repoName . '/releases --jq ".[0] | .tag_name" 2>/dev/null';
                        $latestReleaseTag = trim(\shell_exec($latestReleaseTagCommand) ?? '');

                        if (!empty($latestReleaseTag)) {
                            $tagCommitCommand = 'gh api repos/' . $repoName . '/git/ref/tags/' . $latestReleaseTag . ' --jq ".object.sha" 2>/dev/null';
                            $tagCommitSha = trim(\shell_exec($tagCommitCommand) ?? '');

                            if (!empty($tagCommitSha) && $latestCommitSha === $tagCommitSha) {
                                Console::warning("Latest commit on {$releaseTarget} already has a release ({$latestReleaseTag}) for {$language['name']} SDK, skipping to avoid empty release...");
                                continue;
                            }
                        }
                    }

                    $previousVersion = '';
                    $tagListCommand = 'gh release list --repo "' . $repoName . '" --limit 1 --json tagName --jq ".[0].tagName" 2>&1';
                    $previousVersion = trim(\shell_exec($tagListCommand) ?? '');

                    $formattedNotes = "## What's Changed\n\n";
                    $formattedNotes .= $releaseNotes . "\n\n";

                    if (!empty($previousVersion)) {
                        $formattedNotes .= "**Full Changelog**: https://github.com/" . $repoName . "/compare/" . $previousVersion . "..." . $releaseVersion;
                    } else {
                        $formattedNotes .= "**Full Changelog**: https://github.com/" . $repoName . "/releases/tag/" . $releaseVersion;
                    }

                    if (!$commitRelease) {
                        Console::info("[DRY RUN] Would create release for {$language['name']} SDK:");
                        Console::log("  Repository: {$repoName}");
                        Console::log("  Version: {$releaseVersion}");
                        Console::log("  Title: {$releaseTitle}");
                        Console::log("  Target Branch: {$releaseTarget}");
                        Console::log("  Previous Version: " . ($previousVersion ?: 'N/A'));
                        Console::log("  Release Notes:");
                        Console::log("  " . str_replace("\n", "\n  ", $formattedNotes));
                        Console::log('');
                    } else {
                        Console::info("Creating release {$releaseVersion} for {$language['name']} SDK...");

                        $tempNotesFile = \tempnam(\sys_get_temp_dir(), 'release_notes_');
                        \file_put_contents($tempNotesFile, $formattedNotes);

                        $releaseCommand = 'gh release create "' . $releaseVersion . '" \
                            --repo "' . $repoName . '" \
                            --title "' . $releaseTitle . '" \
                            --notes-file "' . $tempNotesFile . '" \
                            --target "' . $releaseTarget . '" \
                            2>&1';

                        $releaseOutput = [];
                        $releaseReturnCode = 0;
                        \exec($releaseCommand, $releaseOutput, $releaseReturnCode);

                        \unlink($tempNotesFile);

                        if ($releaseReturnCode === 0) {
                            // Extract release URL from output
                            $releaseUrl = '';
                            foreach ($releaseOutput as $line) {
                                if (strpos($line, 'https://github.com/') !== false) {
                                    $releaseUrl = trim($line);
                                    break;
                                }
                            }

                            Console::success("Successfully created release {$releaseVersion} for {$language['name']} SDK");
                            if (!empty($releaseUrl)) {
                                Console::info("Release URL: {$releaseUrl}");
                            }
                        } else {
                            $errorMessage = implode("\n", $releaseOutput);
                            Console::error("Failed to create release for {$language['name']} SDK: " . $errorMessage);
                        }
                    }
                    continue;
                }

                Console::info("Generating {$language['name']} SDK...");

                $sdk = new SDK($config, new Swagger2($spec));

                $sdk
                    ->setName($language['name'])
                    ->setNamespace('io appwrite')
                    ->setDescription("Appwrite is an open-source backend as a service server that abstract and simplify complex and repetitive development tasks behind a very simple to use REST API. Appwrite aims to help you develop your apps faster and in a more secure way. Use the {$language['name']} SDK to integrate your app with the Appwrite server to easily start interacting with all of Appwrite backend APIs and tools. For full API documentation and tutorials go to [https://appwrite.io/docs](https://appwrite.io/docs)")
                    ->setShortDescription('Appwrite is an open-source self-hosted backend server that abstract and simplify complex and repetitive development tasks behind a very simple REST API')
                    ->setLicense($license)
                    ->setLicenseContent($licenseContent)
                    ->setVersion($language['version'])
                    ->setPlatform($key)
                    ->setGitURL($language['url'])
                    ->setGitRepo($language['gitUrl'])
                    ->setGitRepoName($language['gitRepoName'])
                    ->setGitUserName($language['gitUserName'])
                    ->setLogo($cover)
                    ->setURL('https://appwrite.io')
                    ->setShareText('Appwrite is a backend as a service for building web or mobile apps')
                    ->setShareURL('http://appwrite.io')
                    ->setShareTags('JS,javascript,reactjs,angular,ios,android,serverless')
                    ->setShareVia('appwrite')
                    ->setWarning($warning)
                    ->setReadme($readme)
                    ->setGettingStarted($gettingStarted)
                    ->setChangelog($changelog)
                    ->setExamples($examples)
                    ->setTwitter(APP_SOCIAL_TWITTER_HANDLE)
                    ->setDiscord(APP_SOCIAL_DISCORD_CHANNEL, APP_SOCIAL_DISCORD)
                    ->setDefaultHeaders([
                        'X-Appwrite-Response-Format' => '1.8.0',
                    ])
                    ->setExclude($language['exclude'] ?? []);

                // Make sure we have a clean slate.
                // Otherwise, all files in this dir will be pushed,
                // regardless of whether they were just generated or not.
                \exec('chmod -R u+w ' . $result . ' 2>/dev/null; rm -rf ' . $result);

                try {
                    $sdk->generate($result);
                } catch (\Throwable $exception) {
                    Console::error($exception->getMessage());
                }

                $gitUrl = $language['gitUrl'];
                $gitBranch = $language['gitBranch'];

                if (!$production) {
                    $gitUrl = 'git@github.com:aw-tests/' . $language['gitRepoName'] . '.git';
                }

                $repoBranch = $language['repoBranch'] ?? 'main';
                if ($git && !empty($gitUrl)) {
                    \exec('rm -rf ' . $target . ' && \
                        mkdir -p ' . $target . ' && \
                        cd ' . $target . ' && \
                        git init && \
                        git config core.ignorecase false && \
                        git config pull.rebase false && \
                        git remote add origin ' . $gitUrl . ' && \
                        git fetch origin && \
                        (git checkout -f ' . $repoBranch . ' 2>/dev/null || git checkout -b ' . $repoBranch . ') && \
                        git pull origin ' . $repoBranch . ' && \
                        (git checkout -f ' . $gitBranch . ' 2>/dev/null || git checkout -b ' . $gitBranch . ') && \
                        (git fetch origin ' . $gitBranch . ' 2>/dev/null || git push -u origin ' . $gitBranch . ') && \
                        git reset --hard origin/' . $gitBranch . ' 2>/dev/null || true && \
                        (test -d .github && cp -r .github /tmp/.github-backup-$$ || true) && \
                        git rm -rf --cached . && \
                        git clean -fdx -e .git -e .github && \
                        cp -r ' . $result . '/. ' . $target . '/ && \
                        (test -d /tmp/.github-backup-$$ && cp -r /tmp/.github-backup-$$/.github . && rm -rf /tmp/.github-backup-$$ || true) && \
                        git add -A && \
                        git commit -m "' . $message . '" && \
                        git push -u origin ' . $gitBranch . '
                    ');

                    Console::success("Pushed {$language['name']} SDK to {$gitUrl}");
                    if ($createPr) {
                        $prTitle = "feat: {$language['name']} SDK update for version {$language['version']}";
                        $prBody = "This PR contains updates to the {$language['name']} SDK for version {$language['version']}.";

                        $repoName = $language['gitRepoName'];
                        if (!$production) {
                            $repoName = 'aw-tests/' . $language['gitRepoName'];
                        } else {
                            $repoName = $language['gitUserName'] . '/' . $language['gitRepoName'];
                        }

                        Console::info("Creating pull request for {$language['name']} SDK...");

                        $prCommand = 'cd ' . $target . ' && \
                            gh pr create \
                            --repo "' . $repoName . '" \
                            --title "' . $prTitle . '" \
                            --body "' . $prBody . '" \
                            --base "' . $repoBranch . '" \
                            --head "' . $gitBranch . '" \
                            2>&1';

                        $prOutput = [];
                        $prReturnCode = 0;
                        \exec($prCommand, $prOutput, $prReturnCode);

                        if ($prReturnCode === 0) {
                            Console::success("Successfully created pull request for {$language['name']} SDK");
                            if (!empty($prOutput)) {
                                $prUrls[$language['name']] = end($prOutput);
                            }
                        } else {
                            $errorMessage = implode("\n", $prOutput);
                            if (strpos($errorMessage, 'already exists') !== false) {
                                Console::warning("Pull request already exists for {$language['name']} SDK, updating title and body...");
                                $prNumberCommand = 'cd ' . $target . ' && \
                                    gh pr list \
                                    --repo "' . $repoName . '" \
                                    --head "' . $gitBranch . '" \
                                    --json number \
                                    --jq ".[0].number" \
                                    2>&1';

                                $prNumberOutput = [];
                                $prNumberReturnCode = 0;
                                \exec($prNumberCommand, $prNumberOutput, $prNumberReturnCode);

                                if ($prNumberReturnCode === 0 && !empty($prNumberOutput[0])) {
                                    $prNumber = trim($prNumberOutput[0]);

                                    // Use API directly to update PR to avoid deprecated projectCards field
                                    $updateCommand = 'cd ' . $target . ' && \
                                        gh api \
                                        --method PATCH \
                                        -H "Accept: application/vnd.github+json" \
                                        -H "X-GitHub-Api-Version: 2022-11-28" \
                                        /repos/' . $repoName . '/pulls/' . $prNumber . ' \
                                        -f title="' . $prTitle . '" \
                                        -f body="' . $prBody . '" \
                                        2>&1';

                                    $updateOutput = [];
                                    $updateReturnCode = 0;
                                    \exec($updateCommand, $updateOutput, $updateReturnCode);

                                    if ($updateReturnCode === 0) {
                                        Console::success("Successfully updated pull request for {$language['name']} SDK");

                                        $prUrlCommand = 'cd ' . $target . ' && \
                                            gh pr list \
                                            --repo "' . $repoName . '" \
                                            --head "' . $gitBranch . '" \
                                            --json url \
                                            --jq ".[0].url" \
                                            2>&1';

                                        $prUrlOutput = [];
                                        $prUrlReturnCode = 0;
                                        \exec($prUrlCommand, $prUrlOutput, $prUrlReturnCode);

                                        if ($prUrlReturnCode === 0 && !empty($prUrlOutput)) {
                                            $prUrls[$language['name']] = trim($prUrlOutput[0]);
                                        }
                                    } else {
                                        $updateErrorMessage = implode("\n", $updateOutput);
                                        Console::error("Failed to update pull request for {$language['name']} SDK: " . $updateErrorMessage);
                                    }
                                } else {
                                    Console::error("Failed to get PR number for {$language['name']} SDK");
                                }
                            } else {
                                Console::error("Failed to create pull request for {$language['name']} SDK: " . $errorMessage);
                            }
                        }
                    }

                    \exec('chmod -R u+w ' . $target . ' && rm -rf ' . $target);
                    Console::success("Remove temp directory '{$target}' for {$language['name']} SDK");
                }

                $docDirectories = $language['docDirectories'] ?? [''];

                if ($version === 'latest') {
                    continue;
                }

                foreach ($docDirectories as $languageTitle => $path) {
                    $languagePath = strtolower($languageTitle !== 0 ? '/' . $languageTitle : '');
                    \exec(
                        'mkdir -p ' . $resultExamples . $languagePath . ' && \
                        cp -r ' . $result . '/docs/examples' . $languagePath . ' ' . $resultExamples
                    );
                    Console::success("Copied code examples for {$language['name']} SDK to: {$resultExamples}");
                }
            }
        }

        if (!empty($prUrls)) {
            Console::log('');
            Console::log('Pull Request Summary');
            foreach ($prUrls as $sdkName => $url) {
                Console::log("{$sdkName}: {$url}");
            }
            Console::log('');
        }
    }

    /**
     * Extract release notes from changelog for a specific version
     *
     * @param string $changelog
     * @param string $version
     * @return string
     */
    private function extractReleaseNotes(string $changelog, string $version): string
    {
        if (empty($changelog)) {
            return '';
        }

        // Changelog version header pattern: ## 0.14.0
        $pattern = '/^##\s+' . preg_quote($version, '/') . '\s*$/m';
        $startPos = false;
        if (preg_match($pattern, $changelog, $matches, PREG_OFFSET_CAPTURE)) {
            $startPos = $matches[0][1];
        }

        if ($startPos === false) {
            return '';
        }

        $contentStart = strpos($changelog, "\n", $startPos);
        if ($contentStart === false) {
            return '';
        }
        $contentStart++;

        $nextHeaderPattern = '/^##?\s+/m';
        $remainingContent = substr($changelog, $contentStart);

        if (preg_match($nextHeaderPattern, $remainingContent, $matches, PREG_OFFSET_CAPTURE)) {
            $endPos = $matches[0][1];
            $notes = substr($remainingContent, 0, $endPos);
        } else {
            $notes = $remainingContent;
        }

        return trim($notes);
    }
}
