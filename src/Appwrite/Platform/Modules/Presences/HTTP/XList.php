<?php

namespace Appwrite\Platform\Modules\Presences\HTTP;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Base as QueryValidator;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Exception\Relationship as RelationshipException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class XList extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'listPresences';
    }

    public function __construct()
    {
        $allowedAttributes = ['userInternalId', 'userId', 'expiry', 'status', 'source'];

        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/presences')
            ->desc('List presences')
            ->groups(['api', 'presences'])
            ->label('scope', 'presence.read')
            ->label('sdk', new Method(
                namespace: 'presences',
                group: 'presences',
                name: 'listPresences',
                description: 'List presence logs.',
                auth: [AuthType::ADMIN, AuthType::KEY, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PRESENCE_LIST,
                    ),
                ],
            ))
            ->param('queries', [], new QueryValidator('presenceLogs', $allowedAttributes), 'Array of query strings generated using the Query class provided by the SDK.', true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(array $queries, bool $includeTotal, Response $response, Database $dbForProject): void
    {
        try {
            // TODO: make sure to add one more query here if not given -> send only not-expired presence -> presence will be cleared by the maintainance workers
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $cursor = Query::getCursorQueries($queries, false);
        $cursor = \reset($cursor);

        if ($cursor !== false) {
            $validator = new Cursor();

            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $presenceId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('presenceLogs', $presenceId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Presence '{$presenceId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        try {
            $documents = $dbForProject->find('presenceLogs', $queries);
            $total = $includeTotal ? $dbForProject->count('presenceLogs', $filterQueries, APP_LIMIT_COUNT) : 0;
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        } catch (StructureException $e) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $e->getMessage(), previous: $e);
        } catch (RelationshipException $e) {
            throw new Exception(Exception::RELATIONSHIP_VALUE_INVALID, $e->getMessage(), previous: $e);
        }

        $response->dynamic(new Document([
            'presences' => $documents,
            'total' => $total,
        ]), Response::MODEL_PRESENCE_LIST);
    }
}
