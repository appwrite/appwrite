<?php

namespace Appwrite\Platform\Modules\VCS\Http\Installations\Namespaces;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Appwrite\Vcs\InstallationTokens;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class XList extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'listNamespaces';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/vcs/installations/:installationId/namespaces')
            ->desc('List namespaces')
            ->groups(['api', 'vcs'])
            ->label('scope', 'vcs.read')
            ->label('resourceType', RESOURCE_TYPE_VCS)
            ->label('sdk', new Method(
                namespace: 'vcs',
                group: 'installations',
                name: 'listNamespaces',
                description: '/docs/references/vcs/list-namespaces.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_VCS_NAMESPACE_LIST,
                    )
                ]
            ))
            ->param('installationId', '', new Text(256), 'Installation Id')
            ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
            ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
            ->inject('vcsFactory')
            ->inject('installationTokens')
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(
        string $installationId,
        string $search,
        array $queries,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Response $response,
        Database $dbForPlatform
    ) {
        $installation = $dbForPlatform->getDocument('installations', $installationId);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $installation = $installationTokens->refreshForInstallation($installation, $dbForPlatform, $vcsFactory);
        $vcs = $vcsFactory->fromInstallation($installation);

        $queries = Query::parseQueries($queries);
        $limitQuery = current(array_filter($queries, fn ($query) => $query->getMethod() === Query::TYPE_LIMIT));
        $offsetQuery = current(array_filter($queries, fn ($query) => $query->getMethod() === Query::TYPE_OFFSET));

        $limit = !empty($limitQuery) ? $limitQuery->getValue() : 20;
        $offset = !empty($offsetQuery) ? $offsetQuery->getValue() : 0;

        if ($offset % $limit !== 0) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'offset must be a multiple of the limit');
        }

        $page = ($offset / $limit) + 1;

        // Only GitLab has more than one namespace per installation: GitHub's
        // OAuth App flow already scopes an installation to a single org/user
        // during authorization, so there's nothing else to list here.
        if (\method_exists($vcs, 'listNamespaces')) {
            ['items' => $namespaces, 'total' => $total] = $vcs->listNamespaces($page, $limit, $search);
        } else {
            $organization = $installation->getAttribute('organization', '');
            $matches = empty($search) || \stripos($organization, $search) !== false;
            $namespaces = $matches ? [[
                'id' => $installation->getAttribute('providerInstallationId', ''),
                'name' => $organization,
                'path' => $organization,
                'kind' => 'org',
                'avatarUrl' => '',
            ]] : [];
            $total = \count($namespaces);
        }

        $namespaces = \array_map(fn ($namespace) => new Document($namespace), $namespaces);

        $response->dynamic(new Document([
            'namespaces' => $namespaces,
            'total' => $total,
        ]), Response::MODEL_VCS_NAMESPACE_LIST);
    }
}
