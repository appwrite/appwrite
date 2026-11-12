<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Reviews\Events;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\ReviewEvents;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Query\Method as QueryMethod;
use Utopia\System\System;
use Utopia\Validator\Boolean;

/**
 * The review agent's trace, oldest step first unless the queries order it otherwise.
 */
class XList extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'listReviewEvents';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/reviews/:reviewId/events')
            ->desc('List review events')
            ->groups(['api', 'reviews'])
            ->label('scope', 'reviews.read')
            ->label('sdk', new Method(
                namespace: 'reviews',
                group: 'reviews',
                name: 'listEvents',
                description: <<<EOT
                List the steps the review agent took during a code review, oldest first. You can use the query params to filter your results.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_REVIEW_EVENT_LIST,
                    )
                ],
                // Hidden until code review is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('reviewId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Review ID.', false, ['dbForProject'])
            ->param('queries', [], new ReviewEvents(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', ReviewEvents::ALLOWED_ATTRIBUTES), true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $reviewId,
        array $queries,
        bool $includeTotal,
        Response $response,
        Database $dbForProject,
    ): void {
        $review = $dbForProject->getDocument('reviews', $reviewId);

        if ($review->isEmpty()) {
            throw new Exception(Exception::REVIEW_NOT_FOUND);
        }

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

            $eventId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('reviewEvents', $eventId);

            if ($cursorDocument->isEmpty() || $cursorDocument->getAttribute('reviewInternalId') !== $review->getSequence()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Event '{$eventId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $ordered = \array_filter($queries, fn (Query $query) => \in_array($query->getMethod(), [QueryMethod::OrderAsc, QueryMethod::OrderDesc, QueryMethod::OrderRandom], true));
        if (empty($ordered)) {
            $queries[] = Query::orderAsc('time');
        }

        $queries[] = Query::equal('reviewInternalId', [$review->getSequence()]);
        $filterQueries = Query::groupByType($queries)->filters;

        $events = $dbForProject->find('reviewEvents', $queries);
        $total = $includeTotal ? $dbForProject->count('reviewEvents', $filterQueries, APP_LIMIT_COUNT) : 0;

        $response->dynamic(new Document([
            'events' => $events,
            'total' => $total,
        ]), Response::MODEL_REVIEW_EVENT_LIST);
    }
}
