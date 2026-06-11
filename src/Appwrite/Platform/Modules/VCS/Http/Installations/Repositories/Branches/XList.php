<?php

namespace Appwrite\Platform\Modules\VCS\Http\Installations\Repositories\Branches;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Branches;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Text;
use Utopia\VCS\Adapter\Git\GitHub;
use Utopia\VCS\Exception\RepositoryNotFound;

class XList extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'listRepositoryBranches';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/vcs/github/installations/:installationId/providerRepositories/:providerRepositoryId/branches')
            ->desc('List repository branches')
            ->groups(['api', 'vcs'])
            ->label('scope', 'vcs.read')
            ->label('resourceType', RESOURCE_TYPE_VCS)
            ->label('sdk', new Method(
                namespace: 'vcs',
                group: 'repositories',
                name: 'listRepositoryBranches',
                description: '/docs/references/vcs/list-repository-branches.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_BRANCH_LIST,
                    )
                ]
            ))
            ->param('installationId', '', new Text(256), 'Installation Id')
            ->param('providerRepositoryId', '', new Text(256), 'Repository Id')
            ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
            ->param('queries', [], new Branches(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit, offset, cursorAfter, and cursorBefore', true)
            ->inject('gitHub')
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(
        string $installationId,
        string $providerRepositoryId,
        string $search,
        array $queries,
        GitHub $github,
        Response $response,
        Database $dbForPlatform
    ) {
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

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
            $repositoryName = $github->getRepositoryName($providerRepositoryId);
            if (empty($repositoryName)) {
                throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
            }
        } catch (RepositoryNotFound $e) {
            throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
        }

        [
            'limit' => $limit,
            'offset' => $offset,
        ] = Query::groupByType($queries);

        $limit ??= APP_LIMIT_LIST_DEFAULT;
        $offset ??= 0;

        $page = (int) floor($offset / $limit) + 1;
        $branches = $github->listBranches($owner, $repositoryName, $limit, $page, $search);
        $fetched = \count($branches);
        $total = $fetched < $limit ? $offset + $fetched : $offset + $fetched + 1;

        $response->dynamic(new Document([
            'branches' => \array_map(function ($branch) {
                return new Document(['name' => $branch]);
            }, $branches),
            'total' => $total,
        ]), Response::MODEL_BRANCH_LIST);
    }
}
