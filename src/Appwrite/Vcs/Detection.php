<?php

namespace Appwrite\Vcs;

use Swoole\Coroutine\WaitGroup;
use Utopia\Config\Exception\Parse;
use Utopia\Config\Parser\Dotenv;
use Utopia\Detector\Detection\Framework\Analog;
use Utopia\Detector\Detection\Framework\Angular;
use Utopia\Detector\Detection\Framework as FrameworkDetection;
use Utopia\Detector\Detection\Framework\Astro;
use Utopia\Detector\Detection\Framework\Flutter;
use Utopia\Detector\Detection\Framework\Lynx;
use Utopia\Detector\Detection\Framework\NextJs;
use Utopia\Detector\Detection\Framework\Nuxt;
use Utopia\Detector\Detection\Framework\React;
use Utopia\Detector\Detection\Framework\ReactNative;
use Utopia\Detector\Detection\Framework\Remix;
use Utopia\Detector\Detection\Framework\Svelte;
use Utopia\Detector\Detection\Framework\SvelteKit;
use Utopia\Detector\Detection\Framework\TanStackStart;
use Utopia\Detector\Detection\Framework\Vue;
use Utopia\Detector\Detection\Packager\NPM;
use Utopia\Detector\Detection\Packager\PNPM;
use Utopia\Detector\Detection\Packager\Yarn;
use Utopia\Detector\Detection\Runtime as RuntimeDetection;
use Utopia\Detector\Detection\Runtime\Bun;
use Utopia\Detector\Detection\Runtime\CPP;
use Utopia\Detector\Detection\Runtime\Dart;
use Utopia\Detector\Detection\Runtime\Deno;
use Utopia\Detector\Detection\Runtime\Dotnet;
use Utopia\Detector\Detection\Runtime\Java;
use Utopia\Detector\Detection\Runtime\Node;
use Utopia\Detector\Detection\Runtime\PHP;
use Utopia\Detector\Detection\Runtime\Python;
use Utopia\Detector\Detection\Runtime\Ruby;
use Utopia\Detector\Detection\Runtime\Swift;
use Utopia\Detector\Detector\Framework;
use Utopia\Detector\Detector\Packager;
use Utopia\Detector\Detector\Runtime;
use Utopia\Detector\Detector\Strategy;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Exception\FileNotFound;

/**
 * Detects how a repository is built from its files on the VCS provider.
 */
class Detection
{
    /**
     * @param string $prefix Prepended to a file name to get its path in the repository
     */
    public function __construct(
        protected Git $vcs,
        protected string $owner,
        protected string $repository,
        protected string $prefix = '',
    ) {
    }

    /**
     * @param array<string> $files
     */
    public function packager(array $files): string
    {
        $detector = new Packager();
        foreach ($files as $file) {
            $detector->addInput($file);
        }
        $detector
            ->addOption(new Yarn())
            ->addOption(new PNPM())
            ->addOption(new NPM());

        return $detector->detect()?->getName() ?? 'npm';
    }

    /**
     * @param array<string> $files
     */
    public function framework(array $files, string $packager): ?FrameworkDetection
    {
        $packages = '';
        try {
            $content = $this->vcs->getRepositoryContent($this->owner, $this->repository, $this->prefix . 'package.json');
            $packages = $content['content'] ?? '';
        } catch (FileNotFound) {
            // Continue detection without package.json
        }

        $detector = new Framework($packager);
        $detector->addInput($packages, Framework::INPUT_PACKAGES);
        foreach ($files as $file) {
            $detector->addInput($file, Framework::INPUT_FILE);
        }

        $detector
            ->addOption(new Analog())
            ->addOption(new Angular())
            ->addOption(new Astro())
            ->addOption(new Flutter())
            ->addOption(new Lynx())
            ->addOption(new NextJs())
            ->addOption(new Nuxt())
            ->addOption(new React())
            ->addOption(new ReactNative())
            ->addOption(new Remix())
            ->addOption(new Svelte())
            ->addOption(new SvelteKit())
            ->addOption(new TanStackStart())
            ->addOption(new Vue());

        return $detector->detect();
    }

    /**
     * @param array<string> $files
     * @param array<string> $languages
     */
    public function runtime(array $files, array $languages, string $packager): ?RuntimeDetection
    {
        $strategies = [
            new Strategy(Strategy::FILEMATCH),
            new Strategy(Strategy::LANGUAGES),
            new Strategy(Strategy::EXTENSION),
        ];

        foreach ($strategies as $strategy) {
            $detector = new Runtime($strategy, $packager);

            $inputs = $strategy->getValue() === Strategy::LANGUAGES ? $languages : $files;
            foreach ($inputs as $input) {
                $detector->addInput($input);
            }

            $detector
                ->addOption(new Node())
                ->addOption(new Bun())
                ->addOption(new Deno())
                ->addOption(new PHP())
                ->addOption(new Python())
                ->addOption(new Dart())
                ->addOption(new Swift())
                ->addOption(new Ruby())
                ->addOption(new Java())
                ->addOption(new CPP())
                ->addOption(new Dotnet());

            $runtime = $detector->detect();

            if (!\is_null($runtime)) {
                return $runtime;
            }
        }

        return null;
    }

    /**
     * Variables suggested by the repository's .env files.
     *
     * @param array<string> $files
     * @return array<array{name: string, value: mixed}>
     */
    public function variables(array $files): array
    {
        $wg = new WaitGroup();
        $envs = [];
        foreach ($files as $file) {
            if (!\str_starts_with($file, '.env')) {
                continue;
            }

            $wg->add();
            go(function () use ($file, $wg, &$envs) {
                try {
                    $content = $this->vcs->getRepositoryContent($this->owner, $this->repository, $this->prefix . $file);

                    try {
                        $parsed = (new Dotenv())->parse($content['content'] ?? '');
                        foreach ($parsed as $name => $value) {
                            // The parser maps a literal null to null; suggest it as the text the file holds
                            $envs[$name] = $value ?? 'null';
                        }
                    } catch (Parse) {
                        // Silence error, so rest of endpoint can return
                    }
                } finally {
                    $wg->done();
                }
            });
        }
        $wg->wait();

        $variables = [];
        foreach ($envs as $name => $value) {
            $variables[] = [
                'name' => $name,
                'value' => $value,
            ];
        }

        return $variables;
    }
}
