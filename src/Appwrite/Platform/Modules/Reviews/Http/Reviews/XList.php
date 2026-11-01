<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Reviews;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Database\Validator\Queries\Reviews;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

/**
 * Lists reviews without their findings; getting one review returns them.
 */
class XList extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'listReviews';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/reviews')
            ->desc('List reviews')
            ->groups(['api', 'reviews'])
            ->label('scope', 'reviews.read')
            ->param('queries', [], new Reviews(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Reviews::ALLOWED_ATTRIBUTES), true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        array $queries,
        bool $includeTotal,
        Response $response,
        Database $dbForProject,
    ): void {
        try {
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

            $reviewId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('reviews', $reviewId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Review '{$reviewId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)->filters;

        try {
            $reviews = $dbForProject->find('reviews', $queries);
            $total = $includeTotal ? $dbForProject->count('reviews', $filterQueries, APP_LIMIT_COUNT) : 0;
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        }

        $response->dynamic(new Document([
            'reviews' => $reviews,
            'total' => $total,
        ]), Response::MODEL_REVIEW_LIST);
    }
}
