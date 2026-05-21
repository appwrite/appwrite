<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class XList extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'listProjectOAuth2';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/project/oauth2')
            ->desc('List project OAuth2 providers')
            ->groups(['api', 'project'])
            ->label('scope', 'oauth2.read')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'oauth2',
                name: 'listOAuth2Providers',
                description: <<<EOT
                Get a list of all OAuth2 providers supported by the server, along with the project's configuration for each. Credential fields are write-only and always returned empty.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_OAUTH2_PROVIDER_LIST,
                    )
                ]
            ))
            ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('project')
            ->callback($this->action(...));
    }

    /**
     * @param array<string> $queries
     */
    public function action(
        array $queries,
        bool $includeTotal,
        Response $response,
        Document $project,
    ): void {
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $providers = Config::getParam('oAuthProviders', []);
        $actions = Base::getProviderActions();

        $documents = [];
        foreach ($actions as $providerId => $updateClass) {
            if (!($providers[$providerId]['enabled'] ?? false)) {
                // Disabled by Appwrite configuration, exclude from response
                continue;
            }

            $action = new $updateClass();
            $documents[] = $action->buildReadResponse($project);
        }

        $total = $includeTotal ? \count($documents) : 0;

        $grouped = Query::groupByType($queries);
        $offset = $grouped['offset'] ?? 0;
        $limit = $grouped['limit'] ?? null;

        $documents = \array_slice($documents, $offset, $limit);

        $response->dynamic(new Document([
            'total' => $total,
            'providers' => $documents,
        ]), Response::MODEL_OAUTH2_PROVIDER_LIST);
    }
}
