<?php

namespace Appwrite\Platform\Modules\VCS\Http\Installations\Repositories;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\VcsRepositories;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Detection;
use Appwrite\Vcs\Factory as VcsFactory;
use Appwrite\Vcs\InstallationTokens;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Query\Method as QueryMethod;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

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
                        model: [
                            Response::MODEL_PROVIDER_REPOSITORY_RUNTIME_LIST,
                            Response::MODEL_PROVIDER_REPOSITORY_FRAMEWORK_LIST,
                        ],
                    ),
                ]
            ))
            ->param('installationId', '', new Text(256), 'Installation Id')
            ->param('type', '', new WhiteList(['runtime', 'framework']), 'Detector type. Must be one of the following: runtime, framework', enum: new Enum(name: 'VCSDetectionType'))
            ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
            ->param('queries', [], new VcsRepositories(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit, offset, and equal on namespace.', true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('vcsFactory')
            ->inject('installationTokens')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        string $installationId,
        string $type,
        string $search,
        array $queries,
        bool $includeTotal,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Response $response,
        Database $dbForPlatform,
        Document $project
    ) {
        if (empty($search)) {
            $search = "";
        }

        $installation = $dbForPlatform->getDocument('installations', $installationId);

        if ($installation->isEmpty() || $installation->getAttribute('projectInternalId') !== $project->getSequence()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $installation = $installationTokens->refreshForInstallation($installation, $dbForPlatform, $vcsFactory);
        $providerInstallationId = $installation->getAttribute('providerInstallationId');
        $vcs = $vcsFactory->fromInstallation($installation);

        $queries = Query::parseQueries($queries);
        $limitQuery = current(array_filter($queries, fn ($query) => $query->getMethod() === QueryMethod::Limit));
        $offsetQuery = current(array_filter($queries, fn ($query) => $query->getMethod() === QueryMethod::Offset));
        $namespaceQueries = array_filter($queries, fn ($query) => $query->getMethod() === QueryMethod::Equal && $query->getAttribute() === 'namespace');

        $limit = !empty($limitQuery) ? $limitQuery->getValue() : 4;
        $offset = !empty($offsetQuery) ? $offsetQuery->getValue() : 0;
        $namespaceQuery = current($namespaceQueries);
        $providerNamespace = !empty($namespaceQuery) ? $namespaceQuery->getValue('') : '';

        if ($offset % $limit !== 0) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'offset must be a multiple of the limit');
        }

        $page = ($offset / $limit) + 1;
        $namespace = !empty($providerNamespace) ? $providerNamespace : $vcs->getOwnerName($providerInstallationId);
        ['items' => $repos, 'total' => $total] = $vcs->searchRepositories($namespace, $page, $limit, $search);

        $repos = \array_map(function ($repo) use ($installation) {
            $repo['id'] = \strval($repo['id'] ?? '');
            $repo['pushedAt'] = $repo['pushed_at'] ?? null;
            $repo['provider'] = $installation->getAttribute('provider', '') ?? '';
            // Entire names each repository's owner, as a mirrors project holds several GitHub owners
            $repo['organization'] = \is_string($repo['organization'] ?? null) && $repo['organization'] !== ''
                ? $repo['organization']
                : $installation->getAttribute('organization', '') ?? '';
            $repo['providerInstallationId'] = $installation->getAttribute('providerInstallationId', '');
            $repo['authorized'] = true;
            return $repo;
        }, $repos);

        $repos = batch(\array_map(function ($repo) use ($type, $vcs) {
            return function () use ($repo, $type, $vcs) {
                $files = $vcs->listRepositoryContents($repo['organization'], $repo['name'], '');
                $files = \array_column($files, 'name');

                $detection = new Detection($vcs, $repo['organization'], $repo['name']);
                $packager = $detection->packager($files);

                if ($type === 'framework') {
                    $detectedFramework = $detection->framework($files, $packager);

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
                    $languages = $vcs->listRepositoryLanguages($repo['organization'], $repo['name']);

                    $runtime = $detection->runtime($files, $languages, $packager)?->getName();

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

                $repo['variables'] = $detection->variables($files);

                return $repo;
            };
        }, $repos));

        $repos = \array_map(function ($repo) {
            return new Document($repo);
        }, $repos);

        $response->dynamic(new Document([
            'type' => $type,
            $type === 'framework' ? 'frameworkProviderRepositories' : 'runtimeProviderRepositories' => $repos,
            'total' => $includeTotal ? $total : 0,
        ]), ($type === 'framework') ? Response::MODEL_PROVIDER_REPOSITORY_FRAMEWORK_LIST : Response::MODEL_PROVIDER_REPOSITORY_RUNTIME_LIST);
    }
}
