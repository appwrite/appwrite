<?php

namespace Appwrite\Platform\Modules\VCS\Http\Installations\Repositories\Detection;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Swoole\Coroutine\WaitGroup;
use Utopia\Config\Adapters\Dotenv as ConfigDotenv;
use Utopia\Config\Config;
use Utopia\Config\Exceptions\Parse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Detector\Detection\Framework\Analog;
use Utopia\Detector\Detection\Framework\Angular;
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
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;
use Utopia\VCS\Adapter\Git\GitHub;
use Utopia\VCS\Exception\FileNotFound;
use Utopia\VCS\Exception\RepositoryNotFound;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createRepositoryDetection';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/vcs/github/installations/:installationId/detections')
            ->httpAlias('/v1/vcs/github/installations/:installationId/providerRepositories/:providerRepositoryId/detection')
            ->desc('Create repository detection')
            ->groups(['api', 'vcs'])
            ->label('scope', 'vcs.write')
            ->label('resourceType', RESOURCE_TYPE_VCS)
            ->label('sdk', new Method(
                namespace: 'vcs',
                group: 'repositories',
                name: 'createRepositoryDetection',
                description: '/docs/references/vcs/create-repository-detection.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_DETECTION_RUNTIME,
                    ),
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_DETECTION_FRAMEWORK,
                    )
                ]
            ))
            ->param('installationId', '', new Text(256), 'Installation Id')
            ->param('providerRepositoryId', '', new Text(256), 'Repository Id')
            ->param('type', '', new WhiteList(['runtime', 'framework']), 'Detector type. Must be one of the following: runtime, framework')
            ->param('providerRootDirectory', '', new Text(256, 0), 'Path to Root Directory', true)
            ->inject('gitHub')
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(
        string $installationId,
        string $providerRepositoryId,
        string $type,
        string $providerRootDirectory,
        GitHub $github,
        Response $response,
        Database $dbForPlatform
    ) {
        $installation = $dbForPlatform->getDocument('installations', $installationId);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $providerInstallationId = $installation->getAttribute('providerInstallationId');
        $privateKey = System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
        $githubAppId = System::getEnv('_APP_VCS_GITHUB_APP_ID');
        $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);

        $owner = $github->getOwnerName($providerInstallationId);
        try {
            $repositoryName = $github->getRepositoryName($providerRepositoryId) ?? '';
            if (empty($repositoryName)) {
                throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
            }
        } catch (RepositoryNotFound $e) {
            throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
        }

        $files = $github->listRepositoryContents($owner, $repositoryName, $providerRootDirectory);
        $files = \array_column($files, 'name');
        $languages = $github->listRepositoryLanguages($owner, $repositoryName);

        $detector = new Packager();
        foreach ($files as $file) {
            $detector->addInput($file);
        }
        $detector
            ->addOption(new Yarn())
            ->addOption(new PNPM())
            ->addOption(new NPM());
        $detection = $detector->detect();

        $packager = !\is_null($detection) ? $detection->getName() : 'npm';

        if ($type === 'framework') {
            $packages = '';
            try {
                $contentResponse = $github->getRepositoryContent($owner, $repositoryName, \rtrim($providerRootDirectory, '/') . '/package.json');
                $packages = $contentResponse['content'] ?? '';
            } catch (FileNotFound $e) {
                // Continue detection without package.json
            }

            $output = new Document([
                'framework' => '',
                'installCommand' => '',
                'buildCommand' => '',
                'outputDirectory' => '',
            ]);

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

            $framework = $detector->detect();

            if (!\is_null($framework)) {
                $output->setAttribute('installCommand', $framework->getInstallCommand());
                $output->setAttribute('buildCommand', $framework->getBuildCommand());
                $output->setAttribute('outputDirectory', $framework->getOutputDirectory());
                $framework = $framework->getName();
            } else {
                $framework = 'other';
                $output->setAttribute('installCommand', '');
                $output->setAttribute('buildCommand', '');
                $output->setAttribute('outputDirectory', '');
            }

            $frameworks = Config::getParam('frameworks');
            if (!\in_array($framework, \array_keys($frameworks), true)) {
                $framework = 'other';
            }
            $output->setAttribute('framework', $framework);
        } else {
            $output = new Document([
                'runtime' => '',
                'commands' => '',
                'entrypoint' => '',
            ]);

            $strategies = [
                new Strategy(Strategy::FILEMATCH),
                new Strategy(Strategy::LANGUAGES),
                new Strategy(Strategy::EXTENSION),
            ];

            foreach ($strategies as $strategy) {
                $detector = new Runtime($strategy, $packager);

                if ($strategy === Strategy::LANGUAGES) {
                    foreach ($languages as $language) {
                        $detector->addInput($language);
                    }
                } else {
                    foreach ($files as $file) {
                        $detector->addInput($file);
                    }
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
                    $output->setAttribute('commands', $runtime->getCommands());
                    $output->setAttribute('entrypoint', $runtime->getEntrypoint());
                    $runtime = $runtime->getName();
                    break;
                }
            }

            if (!empty($runtime)) {
                $runtimes = Config::getParam('runtimes');
                $runtimeWithVersion = '';
                foreach ($runtimes as $runtimeKey => $runtimeConfig) {
                    if ($runtimeConfig['key'] === $runtime) {
                        $runtimeWithVersion = $runtimeKey;
                    }
                }

                if (empty($runtimeWithVersion)) {
                    throw new Exception(Exception::FUNCTION_RUNTIME_NOT_DETECTED);
                }

                $output->setAttribute('runtime', $runtimeWithVersion);
            } else {
                throw new Exception(Exception::FUNCTION_RUNTIME_NOT_DETECTED);
            }
        }

        $wg = new WaitGroup();
        $envs = [];
        foreach ($files as $file) {
            if (!(\str_starts_with($file, '.env'))) {
                continue;
            }

            $wg->add();
            go(function () use ($github, $owner, $repositoryName, $providerRootDirectory, $file, $wg, &$envs) {
                try {
                    $contentResponse = $github->getRepositoryContent($owner, $repositoryName, \rtrim($providerRootDirectory, '/') . '/' . $file);
                    $envFile = $contentResponse['content'] ?? '';

                    $configAdapter = new ConfigDotenv();
                    try {
                        $envObject = $configAdapter->parse($envFile);
                        foreach ($envObject as $envName => $envValue) {
                            $envs[$envName] = $envValue;
                        }
                    } catch (Parse $err) {
                        // Silence error, so rest of endpoint can return
                    }
                } finally {
                    $wg->done();
                }
            });
        }
        $wg->wait();

        $variables = [];
        foreach ($envs as $key => $value) {
            $variables[] = [
                'name' => $key,
                'value' => $value,
            ];
        }

        $output->setAttribute('variables', $variables);

        $response->dynamic($output, $type === 'framework' ? Response::MODEL_DETECTION_FRAMEWORK : Response::MODEL_DETECTION_RUNTIME);
    }
}
