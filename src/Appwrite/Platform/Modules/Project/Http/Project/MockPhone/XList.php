<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\MockPhone;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
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
        return 'listProjectMockPhones';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/project/mock-phones')
            ->desc('List project mock phones')
            ->groups(['api', 'project'])
            ->label('scope', 'mocks.read')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'mocks',
                name: 'listMockPhones',
                description: <<<EOT
                Get a list of all mock phones in the project. This endpoint returns an array of all mock phones and their OTPs.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_MOCK_NUMBER_LIST,
                    )
                ]
            ))
            ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        array $queries,
        bool $includeTotal,
        Response $response,
        Document $project,
    ) {
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $auths = $project->getAttribute('auths', []);
        $mockNumbers = $auths['mockNumbers'] ?? [];
        $grouped = Query::groupByType($queries);
        $limit = $grouped['limit'] ?? null;
        $offset = $grouped['offset'] ?? 0;

        $total = $includeTotal ? \count($mockNumbers) : 0;
        $mockNumbers = \array_slice($mockNumbers, $offset, $limit);

        $mockNumbers = \array_map(fn ($mockNumber) => new Document($mockNumber), $mockNumbers);

        $response->dynamic(new Document([
            'mockNumbers' => $mockNumbers,
            'total' => $total,
        ]), Response::MODEL_MOCK_NUMBER_LIST);
    }
}
