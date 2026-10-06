<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\SDK\Language\Android;
use Appwrite\SDK\Language\Apple;
use Appwrite\SDK\Language\ClaudePlugin;
use Appwrite\SDK\Language\CLI;
use Appwrite\SDK\Language\CodexPlugin;
use Appwrite\SDK\Language\CursorPlugin;
use Appwrite\SDK\Language\Dart;
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
use Appwrite\SDK\Language\Rust;
use Appwrite\SDK\Language\Skills;
use Appwrite\SDK\Language\Swift;
use Appwrite\SDK\Language\Unity;
use Appwrite\SDK\Language\Web;
use Appwrite\SDK\SDK;
use CzProject\GitPhp\Git;
use Utopia\Config\Config;
use Utopia\Console;
use Utopia\OpenAPI\Parser;
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

    public static function getPlatforms(): array
    {
        return [
            ...Specs::getPlatforms(),
            APP_SDK_PLATFORM_STATIC,
        ];
    }

    protected function getSupportedSDKs(): array
    {
        return \array_unique(\array_merge(...\array_values(\array_map(
            fn ($platform) => \array_column($platform['sdks'], 'key'),
            Config::getParam('sdks')
        ))));
    }

    public function __construct()
    {
        $this
            ->desc('Generate Appwrite SDKs')
            ->param('platform', null, new Nullable(new Text(256)), 'Selected Platform', optional: true)
            ->param('sdk', null, new Nullable(new Text(256)), 'Selected SDK', optional: true)
            ->param('version', null, new Nullable(new Text(256)), 'Selected SDK', optional: true)
            ->param('git', null, new Nullable(new WhiteList(['yes', 'no'])), 'Should we use git push?', optional: true)
            ->param('message', null, new Nullable(new Text(256)), 'Commit Message', optional: true)
            ->param('release', null, new Nullable(new WhiteList(['yes', 'no'])), 'Should we create releases?', optional: true)
            ->param('commit', null, new Nullable(new WhiteList(['yes', 'no'])), 'Actually create releases (yes) or dry-run (no)?', optional: true)
            ->param('sdks', null, new Nullable(new Text(256)), 'Selected SDKs', optional: true)
            ->param('mode', 'full', new WhiteList(['full', 'examples']), 'Generation mode: full (default) or examples (only generate and copy examples)', optional: true)
            ->callback($this->action(...));
    }

    public function action(?string $platform, ?string $sdk, ?string $version, ?string $git, ?string $message, ?string $release, ?string $commit, ?string $sdks, string $mode): void
    {
        $examplesOnly = ($mode === 'examples');
        $selectedPlatform = $platform;
        $selectedSDK = $sdk;

        if (! $sdks) {
            $selectedPlatform ??= Console::confirm('Choose Platform ("' . implode('", "', static::getPlatforms()) . '", comma-separated, or "*" for all):');
            $selectedSDK ??= \strtolower(Console::confirm('Choose SDK ("*" for all):'));
            $supportedSDKs = $this->getSupportedSDKs();
            if ($selectedSDK !== '*' && ! \in_array($selectedSDK, $supportedSDKs)) {
                throw new \Exception('Unknown SDK "' . $selectedSDK . '" given. Options are: ' . implode(', ', $supportedSDKs));
            }
        } else {
            $sdks = explode(',', $sdks);
        }

        $createRelease = ($release === 'yes');
        $commitRelease = ($commit === 'yes');

        if ($createRelease && $examplesOnly) {
            throw new \Exception('Cannot use --release=yes with --mode=examples');
        }

        if (! $createRelease && ! $examplesOnly) {
            $git ??= Console::confirm('Should we use git push? (yes/no)');
            $git = ($git === 'yes');

            $prUrls = [];

        } elseif ($examplesOnly) {
            $git = false;
            $prUrls = [];
        }

        if (! $createRelease) {
            $version ??= Console::confirm('Choose an Appwrite version');
        }

        $selectedPlatforms = ($selectedPlatform === '*' || $selectedPlatform === null) ? null : \array_map('trim', \explode(',', $selectedPlatform));

        if ($selectedPlatforms !== null) {
            $validPlatforms = static::getPlatforms();
            foreach ($selectedPlatforms as $p) {
                if (! \in_array($p, $validPlatforms)) {
                    throw new \Exception('Unknown platform "' . $p . '". Options are: ' . implode(', ', $validPlatforms));
                }
            }
        }

        $platforms = Config::getParam('sdks');
        foreach ($platforms as $key => $platform) {
            if ($selectedPlatforms !== null && ! \in_array($key, $selectedPlatforms) && ($sdks === null)) {
                continue;
            }

            foreach ($platform['sdks'] as $language) {
                if ($selectedSDK !== $language['key'] && $selectedSDK !== '*' && ($sdks === null || ! \in_array($language['key'], $sdks))) {
                    continue;
                }

                if (! $language['enabled']) {
                    Console::warning("{$language['name']} for {$platform['name']} is disabled");

                    continue;
                }

                Console::log('');

                if ($createRelease) {
                    Console::info("━━━ {$language['name']} SDK ({$platform['name']}, {$language['version']}) ━━━");
                    $changelog = $language['changelog'] ?? '';
                    $changelog = ($changelog) ? \file_get_contents($changelog) : '# Change Log';

                    $repoName = $language['gitUserName'] . '/' . $language['gitRepoName'];
                    $releaseVersion = $language['version'];
                    $releaseNotes = $this->extractReleaseNotes($changelog, $releaseVersion);

                    if (empty($releaseNotes)) {
                        $releaseNotes = "Release version {$releaseVersion}";
                    }

                    $releaseTitle = $releaseVersion;
                    $releaseTarget = $language['repoBranch'] ?? 'main';
                    $isPrerelease = (bool) \preg_match('/^v?\d+\.\d+\.\d+-/', $releaseVersion);

                    if ($repoName === '/') {
                        Console::warning('  Not a releasable SDK, skipping');

                        continue;
                    }

                    // Check if release already exists
                    $checkReleaseCommand = 'gh release view ' . \escapeshellarg($releaseVersion) . ' --repo ' . \escapeshellarg($repoName) . ' --json url --jq ".url" 2>/dev/null';
                    $existingReleaseUrl = trim(\shell_exec($checkReleaseCommand) ?? '');

                    if (! empty($existingReleaseUrl)) {
                        Console::warning("  Release {$releaseVersion} already exists, skipping");
                        Console::log("  {$existingReleaseUrl}");

                        continue;
                    }

                    // Check if the latest commit on the target branch already has a release
                    $latestCommitCommand = 'gh api repos/' . $repoName . '/commits/' . $releaseTarget . ' --jq ".sha" 2>/dev/null';
                    $latestCommitSha = trim(\shell_exec($latestCommitCommand) ?? '');

                    if (! empty($latestCommitSha)) {
                        $latestReleaseTagCommand = 'gh api repos/' . $repoName . '/releases --jq ".[0] | .tag_name" 2>/dev/null';
                        $latestReleaseTag = trim(\shell_exec($latestReleaseTagCommand) ?? '');

                        if (! empty($latestReleaseTag)) {
                            $tagCommitCommand = 'gh api repos/' . $repoName . '/git/ref/tags/' . $latestReleaseTag . ' --jq ".object.sha" 2>/dev/null';
                            $tagCommitSha = trim(\shell_exec($tagCommitCommand) ?? '');

                            if (! empty($tagCommitSha) && $latestCommitSha === $tagCommitSha) {
                                Console::warning("  Latest commit already released ({$latestReleaseTag}), skipping");

                                continue;
                            }
                        }
                    }

                    $previousVersion = '';
                    $tagListCommand = 'gh release list --repo ' . \escapeshellarg($repoName) . ' --limit 1 --json tagName --jq ".[0].tagName" 2>&1';
                    $previousVersion = trim(\shell_exec($tagListCommand) ?? '');

                    $formattedNotes = "## What's Changed\n\n";
                    $formattedNotes .= $releaseNotes . "\n\n";

                    if (! empty($previousVersion)) {
                        $formattedNotes .= '**Full Changelog**: https://github.com/' . $repoName . '/compare/' . $previousVersion . '...' . $releaseVersion;
                    } else {
                        $formattedNotes .= '**Full Changelog**: https://github.com/' . $repoName . '/releases/tag/' . $releaseVersion;
                    }

                    if (! $commitRelease) {
                        Console::info('  [DRY RUN] Would create release:');
                        Console::log("    Repository:       {$repoName}");
                        Console::log("    Version:          {$releaseVersion}");
                        Console::log("    Title:            {$releaseTitle}");
                        Console::log("    Target Branch:    {$releaseTarget}");
                        Console::log('    Prerelease:       ' . ($isPrerelease ? 'yes' : 'no'));
                        Console::log('    Previous Version: ' . ($previousVersion ?: 'N/A'));
                        Console::log('    Release Notes:');
                        Console::log('    ' . str_replace("\n", "\n    ", $formattedNotes));
                    } else {
                        Console::log("  Creating release {$releaseVersion}...");

                        $tempNotesFile = \tempnam(\sys_get_temp_dir(), 'release_notes_');
                        \file_put_contents($tempNotesFile, $formattedNotes);

                        $releaseCommand = 'gh release create ' . \escapeshellarg($releaseVersion)
                            . ' --repo ' . \escapeshellarg($repoName)
                            . ' --title ' . \escapeshellarg($releaseTitle)
                            . ' --notes-file ' . \escapeshellarg($tempNotesFile)
                            . ' --target ' . \escapeshellarg($releaseTarget)
                            . ($isPrerelease ? ' --prerelease' : '')
                            . ' 2>&1';

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

                            Console::success("  Release {$releaseVersion} created");
                            if (! empty($releaseUrl)) {
                                Console::log("  {$releaseUrl}");
                            }
                        } else {
                            $errorMessage = implode("\n", $releaseOutput);
                            Console::error("  Failed to create release: " . $errorMessage);
                        }
                    }

                    continue;
                }

                Console::info("━━━ {$language['name']} SDK ({$platform['name']}, {$version}) ━━━");
                $specFormat = $language['spec'] ?? 'openapi3';
                $spec = null;
                if ($specFormat === 'static') {
                    Console::log('  Using static SDK spec...');
                } else {
                    Console::log('  Fetching API spec...');

                    $specPath = __DIR__ . '/../../../../app/config/specs/open-api3-' . $version . '-' . $language['family'] . '.json';

                    if (!file_exists($specPath)) {
                        throw new \Exception('Spec file not found: ' . $specPath . '. Please run "docker compose exec appwrite specs --version=' . $version . '" first to generate the specs.');
                    }

                    $spec = file_get_contents($specPath);
                }

                $cover = 'https://github.com/appwrite/appwrite/raw/main/public/images/github.png';
                $result = \realpath(__DIR__ . '/../../../../app') . '/sdks/' . $key . '-' . $language['key'];
                $resultExamples = \realpath(__DIR__ . '/../../../..') . '/docs/examples/' . $version . '/' . $key . '-' . $language['key'];
                $target = \realpath(__DIR__ . '/../../../../app') . '/sdks/git/' . $language['key'] . '/';
                $readme = \realpath(__DIR__ . '/../../../../docs/sdks/' . $language['key'] . '/README.md');
                $readme = ($readme) ? \file_get_contents($readme) : '';
                $gettingStarted = $language['gettingStarted'] ?? \realpath(__DIR__ . '/../../../../docs/sdks/' . $language['key'] . '/GETTING_STARTED.md');
                $gettingStarted = ($gettingStarted) ? \file_get_contents($gettingStarted) : '';
                $examples = \realpath(__DIR__ . '/../../../../docs/sdks/' . $language['key'] . '/EXAMPLES.md');
                $examples = ($examples) ? \file_get_contents($examples) : '';
                $changelog = $language['changelog'] ?? '';
                $changelog = ($changelog) ? \file_get_contents($changelog) : '# Change Log';
                $warning = '**This SDK targets Appwrite server version ' . $version . ' as shipped on Appwrite Cloud.** Self-hosted releases can lag behind Cloud — if you run an older self-hosted build, use a matching older SDK from [previous releases](' . $language['url'] . '/releases) when APIs differ.';
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
                        } else {
                            $config->setNPMPackage('appwrite');
                        }
                        break;
                    case 'cli':
                        $config = new CLI();
                        $config->setNPMPackage('appwrite-cli');
                        $config->setExecutableName('appwrite');
                        $config->setLogo("
    _                            _ _           ___   __   _____
   /_\  _ __  _ ____      ___ __(_) |_ ___    / __\ / /   \_   \
  //_\\\| '_ \| '_ \ \ /\ / / '__| | __/ _ \  / /   / /     / /\/
 /  _  \ |_) | |_) \ V  V /| |  | | ||  __/ / /___/ /___/\/ /_
 \_/ \_/ .__/| .__/ \_/\_/ |_|  |_|\__\___| \____/\____/\____/
       |_|   |_|

");
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
                        $config->setComposerVendor($language['composerVendor'] ?? 'appwrite');
                        $config->setComposerPackage($language['composerPackage'] ?? 'appwrite');
                        break;
                    case 'nodejs':
                        $config = new Node();
                        $config->setNPMPackage('node-appwrite');
                        $warning = $warning . "\n\n > This is the Node.js SDK for integrating with Appwrite from your Node.js server-side code.
                            If you're looking to integrate from the browser, you should check [appwrite/sdk-for-web](https://github.com/appwrite/sdk-for-web)";
                        break;
                    case 'python':
                        $config = new Python();
                        $config->setPipPackage($language['pipPackage'] ?? 'appwrite');
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
                    case 'unity':
                        $cover = '';
                        $config = new Unity();
                        $config->setPackageName('io.appwrite.unity');
                        break;
                    case 'android':
                        $config = new Android();
                        break;
                    case 'kotlin':
                        $config = new Kotlin();
                        $warning = $warning . "\n\n > This is the Kotlin SDK for integrating with Appwrite from your Kotlin server-side code. If you're looking for the Android SDK you should check [appwrite/sdk-for-android](https://github.com/appwrite/sdk-for-android)";
                        break;
                    case 'rust':
                        $config = new Rust();
                        break;
                    case 'graphql':
                        $config = new GraphQL();
                        break;
                    case 'rest':
                        $config = new REST();
                        break;
                    case 'agent-skills':
                        $config = new Skills();
                        break;
                    case 'cursor-plugin':
                        $config = new CursorPlugin();
                        break;
                    case 'claude-plugin':
                        $config = new ClaudePlugin();
                        break;
                    case 'codex-plugin':
                        $config = new CodexPlugin();
                        break;
                    default:
                        throw new \Exception('Language "' . $language['key'] . '" not supported');
                }

                Console::log($examplesOnly
                    ? '  Generating examples...'
                    : '  Generating SDK...');

                $sdk = new SDK(
                    $config,
                    $specFormat === 'static'
                        ? Parser::parse([
                            'openapi' => '3.0.0',
                            'info' => [
                                'title' => 'Appwrite',
                                'description' => 'Appwrite backend as a service',
                                'version' => $version,
                                'license' => [
                                    'name' => 'BSD-3-Clause',
                                    'url' => 'https://raw.githubusercontent.com/appwrite/appwrite/master/LICENSE',
                                ],
                            ],
                            'paths' => [],
                        ])
                        : Parser::parse($spec)
                );

                $sdk
                    ->setName($language['name'])
                    ->setNamespace($language['namespace'] ?? 'appwrite')
                    ->setDescription($language['description'] ?? "Appwrite is an open-source backend as a service server that abstracts and simplifies complex and repetitive development tasks behind a very simple to use REST API. Appwrite aims to help you develop your apps faster and in a more secure way. Use the {$language['name']} SDK to integrate your app with the Appwrite server to easily start interacting with all of Appwrite backend APIs and tools. For full API documentation and tutorials go to [https://appwrite.io/docs](https://appwrite.io/docs)")
                    ->setShortDescription($language['shortDescription'] ?? 'Appwrite is an open-source self-hosted backend server that abstracts and simplifies complex and repetitive development tasks behind a very simple REST API')
                    ->setLicense($license)
                    ->setLicenseContent($licenseContent)
                    ->setVersion($language['version'])
                    ->setPlatform($language['family'])
                    ->setGitURL($language['url'])
                    ->setGitRepo($language['gitUrl'])
                    ->setGitRepoName($language['gitRepoName'])
                    ->setGitUserName($language['gitUserName'])
                    ->setCoverImage($cover)
                    ->setURL('https://appwrite.io')
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
                    ->setExclude($language['exclude'] ?? [])
                    ->setTest(false);

                // Make sure we have a clean slate.
                // Otherwise, all files in this dir will be pushed,
                // regardless of whether they were just generated or not.
                \exec('chmod -R u+w ' . $result . ' 2>/dev/null; rm -rf ' . $result);

                try {
                    $sdk->generate($result);
                    Console::success($examplesOnly
                        ? "  Examples generated at {$result}"
                        : "  SDK generated at {$result}");
                } catch (\Throwable $exception) {
                    Console::error($exception->getMessage());
                }

                $gitUrl = $language['gitUrl'];
                $gitBranch = $language['gitBranch'];

                $repoBranch = $language['repoBranch'] ?? 'main';
                if ($git && !empty($gitUrl)) {
                    $prUrls = [];

                    $commitMessage = $message ?: "chore: update {$language['name']} SDK to {$language['version']}";

                    $pushSuccess = $this->pushToGit($language, $target, $result, $gitUrl, $gitBranch, $repoBranch, $commitMessage);

                    if ($pushSuccess) {
                        $this->createPullRequest($language, $platform['name'], $target, $gitBranch, $repoBranch, $prUrls);
                    }

                    \exec('chmod -R u+w ' . $target . ' && rm -rf ' . $target);
                    Console::log('  Cleaned up temp directory');
                }

                $this->copyExamples($language, $version, $result, $resultExamples);
            }
        }

        if (! empty($prUrls)) {
            Console::log('');
            Console::info('━━━ Pull Request Summary ━━━');
            foreach ($prUrls as $platformName => $sdks) {
                Console::log('');
                Console::info("  {$platformName}:");
                foreach ($sdks as $sdkName => $url) {
                    Console::log("    {$sdkName}: {$url}");
                }
            }
            Console::log('');
        }
    }

    private function pushToGit(array $language, string $target, string $result, string $gitUrl, string $gitBranch, string $repoBranch, string $commitMessage): bool
    {
        Console::log('  Preparing git repository...');

        try {
            // Init fresh repo
            \exec('rm -rf ' . \escapeshellarg($target));
            \mkdir($target, 0755, true);

            $gitClient = new Git();
            $repo = $gitClient->init($target);

            $repo->execute('config', 'core.ignorecase', 'false');
            $repo->execute('config', 'pull.rebase', 'false');
            $repo->execute('config', 'advice.defaultBranchName', 'false');
            $repo->addRemote('origin', $gitUrl);

            // Fetch and checkout the target branch (e.g. dev) if it exists on remote,
            // otherwise create it from the base branch (e.g. main).
            // We build on top of the existing remote branch so a regular push
            // works without force-pushing against protected branches.
            $hasBranch = false;
            try {
                $repo->execute('fetch', 'origin', '--quiet', '--no-tags', '--depth', '1', $gitBranch);
                $hasBranch = true;
            } catch (\Throwable) {
                // Branch doesn't exist on remote yet
            }

            if ($hasBranch) {
                // Squash merges leave the target branch diverged, so start from the base and keep the target as a parent for a fast-forward push
                $repo->execute('fetch', 'origin', '--quiet', '--no-tags', '--depth', '1', $repoBranch);
                $repo->execute('checkout', '-f', '-B', $gitBranch, 'FETCH_HEAD');
                $repo->execute('merge', '--quiet', '-s', 'ours', '--allow-unrelated-histories', '--no-edit', 'origin/' . $gitBranch);
            } else {
                // Fetch base branch to create the target branch from it
                try {
                    $repo->execute('fetch', 'origin', '--quiet', '--no-tags', '--depth', '1', $repoBranch);
                    $repo->execute('checkout', '-f', $repoBranch);
                } catch (\Throwable) {
                    $repo->execute('checkout', '-b', $repoBranch);
                }
                $repo->execute('checkout', '-b', $gitBranch);
            }

            // Backup .github before cleaning working tree
            $githubDir = $target . '/.github';
            $githubBackup = \sys_get_temp_dir() . '/.github-backup-' . \getmypid();
            $hasGithubDir = \is_dir($githubDir);
            if ($hasGithubDir) {
                \exec('cp -r ' . \escapeshellarg($githubDir) . ' ' . \escapeshellarg($githubBackup));
            }

            // Clean working tree
            try {
                $repo->execute('rm', '-rf', '--cached', '.');
            } catch (\Throwable) {
            }
            try {
                $repo->execute('clean', '-fdx', '-e', '.git', '-e', '.github');
            } catch (\Throwable) {
            }

            // Copy generated SDK and restore .github
            \exec('cp -r ' . \escapeshellarg($result . '/.') . ' ' . \escapeshellarg($target . '/'));

            if ($hasGithubDir && \is_dir($githubBackup)) {
                \exec('cp -rn ' . \escapeshellarg($githubBackup . '/.github') . ' ' . \escapeshellarg($target . '/') . ' 2>/dev/null');
                \exec('rm -rf ' . \escapeshellarg($githubBackup));
            }

            // Stage, commit, push
            $repo->addAllChanges();

            if ($repo->hasChanges()) {
                $repo->commit($commitMessage);
            } else {
                Console::log('  No changes to commit, SDK is up to date');
                if (!$hasBranch) {
                    return true;
                }
            }

            $repo->execute('push', '-u', 'origin', $gitBranch, '--quiet');
        } catch (\Throwable $e) {
            Console::warning("  Git push failed: " . $e->getMessage());
            return false;
        }

        Console::success("  Pushed to {$gitUrl}");
        return true;
    }

    private function createPullRequest(array $language, string $platformName, string $target, string $gitBranch, string $repoBranch, array &$prUrls): void
    {
        $prTitle = "feat: {$language['name']} SDK update for version {$language['version']}";
        $prBody = "This PR contains updates to the {$language['name']} SDK for version {$language['version']}.";
        $repoName = $language['gitUserName'] . '/' . $language['gitRepoName'];

        Console::log('  Creating pull request...');

        $prCommand = 'cd ' . $target . ' && \
            gh pr create \
            --repo ' . \escapeshellarg($repoName) . ' \
            --title ' . \escapeshellarg($prTitle) . ' \
            --body ' . \escapeshellarg($prBody) . ' \
            --base ' . \escapeshellarg($repoBranch) . ' \
            --head ' . \escapeshellarg($gitBranch) . ' \
            2>&1';

        $prOutput = [];
        $prReturnCode = 0;
        \exec($prCommand, $prOutput, $prReturnCode);

        if ($prReturnCode === 0) {
            Console::success("  Pull request created");
            foreach ($prOutput as $line) {
                if (\str_starts_with(trim($line), 'https://')) {
                    $prUrls[$platformName][$language['name']] = trim($line);
                    break;
                }
            }
        } else {
            $errorMessage = implode("\n", $prOutput);
            if (strpos($errorMessage, 'already exists') === false) {
                Console::error("  Failed to create pull request: " . $errorMessage);
            } else {
                // Extract PR URL from the error output (gh includes it in "already exists" messages)
                $existingPrUrl = '';
                foreach ($prOutput as $line) {
                    if (\preg_match('#(https://github\.com/[^\s]+/pull/\d+)#', $line, $urlMatch)) {
                        $existingPrUrl = $urlMatch[1];
                        break;
                    }
                }

                $this->updateExistingPr($repoName, $gitBranch, $prTitle, $prBody, $platformName, $language['name'], $prUrls, $existingPrUrl);
            }
        }
    }

    private function copyExamples(array $language, string $version, string $result, string $resultExamples): void
    {
        $docDirectories = $language['docDirectories'] ?? [''];

        if ($version === 'latest') {
            return;
        }

        foreach ($docDirectories as $languageTitle => $path) {
            $languagePath = strtolower($languageTitle !== 0 ? '/' . $languageTitle : '');
            $examplesSource = $result . '/docs/examples' . $languagePath;

            if (! \is_dir($examplesSource)) {
                Console::warning("  No code examples found at: {$examplesSource}");

                continue;
            }

            \exec(
                'mkdir -p ' . $resultExamples . $languagePath . ' && \
                cp -r ' . $examplesSource . ' ' . $resultExamples
            );
            $label = \is_string($languageTitle) ? " ({$languageTitle})" : '';
            Console::success("  Examples{$label} copied to {$resultExamples}{$languagePath}");
        }
    }

    /**
     * Extract release notes from changelog for a specific version
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

    private function updateExistingPr(string $repoName, string $gitBranch, string $prTitle, string $prBody, string $platformName, string $sdkName, array &$prUrls, string $existingPrUrl = ''): void
    {
        Console::log('  Pull request already exists, updating...');

        $prNumber = '';
        $prUrl = '';

        // Try extracting from the gh pr create error output first (free, no API call)
        if (! empty($existingPrUrl) && \preg_match('#/pull/(\d+)#', $existingPrUrl, $matches)) {
            $prNumber = $matches[1];
            $prUrl = $existingPrUrl;
        }

        // Otherwise, look it up via gh pr list
        if (empty($prNumber)) {
            $prListCommand = 'gh pr list'
                . ' --repo ' . \escapeshellarg($repoName)
                . ' --head ' . \escapeshellarg($gitBranch)
                . ' --json number,url'
                . ' --jq ".[0] | (.number|tostring) + \" \" + .url"'
                . ' 2>&1';

            $prListOutput = [];
            \exec($prListCommand, $prListOutput);

            if (! empty($prListOutput[0])) {
                $parts = \explode(' ', trim($prListOutput[0]), 2);
                $prNumber = $parts[0];
                $prUrl = $parts[1] ?? '';
            }
        }

        if (empty($prNumber)) {
            Console::error("  Failed to find existing PR for branch {$gitBranch}");
            return;
        }

        $apiPath = "/repos/{$repoName}/pulls/{$prNumber}";
        $updateCommand = 'gh api'
            . ' --method PATCH'
            . ' -H "Accept: application/vnd.github+json"'
            . ' -H "X-GitHub-Api-Version: 2022-11-28"'
            . ' ' . \escapeshellarg($apiPath)
            . ' -f title=' . \escapeshellarg($prTitle)
            . ' -f body=' . \escapeshellarg($prBody)
            . ' 2>&1';

        $updateOutput = [];
        $updateReturnCode = 0;
        \exec($updateCommand, $updateOutput, $updateReturnCode);

        if ($updateReturnCode !== 0) {
            Console::error("  Failed to update pull request: " . implode("\n", $updateOutput));
            return;
        }

        Console::success("  Pull request updated");

        if (! empty($prUrl)) {
            $prUrls[$platformName][$sdkName] = $prUrl;
        }
    }
}
