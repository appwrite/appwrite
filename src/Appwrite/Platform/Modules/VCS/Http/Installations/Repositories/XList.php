<?php

namespace Appwrite\Platform\Modules\VCS\Http\Installations\Repositories;

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
use Utopia\Database\Query;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
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

use function Swoole\Coroutine\batch;

class XList extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'listRepositories';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/vcs/github/installations/:installationId/providerRepositories')
            ->desc('List repositories')
            ->groups(['api', 'vcs'])
            ->label('scope', 'vcs.read')
            ->label('resourceType', RESOURCE_TYPE_VCS)
            ->label('sdk', new Method(
                namespace: 'vcs',
                group: 'repositories',
                name: 'listRepositories',
                description: '/docs/references/vcs/list-repositories.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROVIDER_REPOSITORY_RUNTIME_LIST,
                    ),
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROVIDER_REPOSITORY_FRAMEWORK_LIST,
                    )
                ]
            ))
            ->param('installationId', '', new Text(256), 'Installation Id')
            ->param('type', '', new WhiteList(['runtime', 'framework']), 'Detector type. Must be one of the following: runtime, framework')
            ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
            ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
            ->inject('gitHub')
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(
        string $installationId,
        string $type,
        string $search,
        array $queries,
        GitHub $github,
        Response $response,
        Database $dbForPlatform
    ) {
        if (empty($search)) {
            $search = "";
        }

        $installation = $dbForPlatform->getDocument('installations', $installationId);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $providerInstallationId = $installation->getAttribute('providerInstallationId');
        $privateKey = System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
        $githubAppId = System::getEnv('_APP_VCS_GITHUB_APP_ID');
        $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);

        $queries = Query::parseQueries($queries);
        $limitQuery = current(array_filter($queries, fn ($query) => $query->getMethod() === Query::TYPE_LIMIT));
        $offsetQuery = current(array_filter($queries, fn ($query) => $query->getMethod() === Query::TYPE_OFFSET));

        $limit = !empty($limitQuery) ? $limitQuery->getValue() : 4;
        $offset = !empty($offsetQuery) ? $offsetQuery->getValue() : 0;

        if ($offset % $limit !== 0) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'offset must be a multiple of the limit');
        }

        $page = ($offset / $limit) + 1;
        $owner = $github->getOwnerName($providerInstallationId);
        ['items' => $repos, 'total' => $total] = $github->searchRepositories($owner, $page, $limit, $search);

        $repos = \array_map(function ($repo) use ($installation) {
            $repo['id'] = \strval($repo['id'] ?? '');
            $repo['pushedAt'] = $repo['pushed_at'] ?? null;
            $repo['provider'] = $installation->getAttribute('provider', '') ?? '';
            $repo['organization'] = $installation->getAttribute('organization', '') ?? '';
            return $repo;
        }, $repos);

        $repos = batch(\array_map(function ($repo) use ($type, $github) {
            return function () use ($repo, $type, $github) {
                $files = $github->listRepositoryContents($repo['organization'], $repo['name'], '');
                $files = \array_column($files, 'name');

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
                        $contentResponse = $github->getRepositoryContent($repo['organization'], $repo['name'], 'package.json');
                        $packages = $contentResponse['content'] ?? '';
                    } catch (FileNotFound $e) {
                        // Continue detection without package.json
                    }

                    $frameworkDetector = new Framework($packager);
                    $frameworkDetector->addInput($packages, Framework::INPUT_PACKAGES);
                    foreach ($files as $file) {
                        $frameworkDetector->addInput($file, Framework::INPUT_FILE);
                    }

                    $frameworkDetector
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

                    $detectedFramework = $frameworkDetector->detect();

                    if (!\is_null($detectedFramework)) {
                        $framework = $detectedFramework->getName();
                    } else {
                        $framework = 'other';
                    }

                    $frameworks = Config::getParam('frameworks');
                    if (!\in_array($framework, \array_keys($frameworks), true)) {
                        $framework = 'other';
                    }
                    $repo['framework'] = $framework;
                } else {
                    $languages = $github->listRepositoryLanguages($repo['organization'], $repo['name']);

                    $strategies = [
                        new Strategy(Strategy::FILEMATCH),
                        new Strategy(Strategy::LANGUAGES),
                        new Strategy(Strategy::EXTENSION),
                    ];

                    foreach ($strategies as $strategy) {
                        $detector = new Runtime($strategy, $packager);
                        if ($strategy->getValue() === Strategy::LANGUAGES) {
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

                        $repo['runtime'] = $runtimeWithVersion ?? '';
                    }
                }

                $wg = new WaitGroup();
                $envs = [];
                foreach ($files as $file) {
                    if (!(\str_starts_with($file, '.env'))) {
                        continue;
                    }

                    $wg->add();
                    go(function () use ($github, $repo, $file, $wg, &$envs) {
                        try {
                            $contentResponse = $github->getRepositoryContent($repo['organization'], $repo['name'], $file);
                            $envFile = $contentResponse['content'] ?? '';

                            $configAdapter = new ConfigDotenv();
                            try {
                                $envObject = $configAdapter->parse($envFile);
                                foreach ($envObject as $envName => $envValue) {
                                    $envs[$envName] = $envValue;
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

                $repo['variables'] = [];
                foreach ($envs as $key => $value) {
                    $repo['variables'][] = [
                        'name' => $key,
                        'value' => $value,
                    ];
                }

                return $repo;
            };
        }, $repos));

        $repos = \array_map(function ($repo) {
            return new Document($repo);
        }, $repos);

        $response->dynamic(new Document([
            $type === 'framework' ? 'frameworkProviderRepositories' : 'runtimeProviderRepositories' => $repos,
            'total' => $total,
        ]), ($type === 'framework') ? Response::MODEL_PROVIDER_REPOSITORY_FRAMEWORK_LIST : Response::MODEL_PROVIDER_REPOSITORY_RUNTIME_LIST);
    }
}
