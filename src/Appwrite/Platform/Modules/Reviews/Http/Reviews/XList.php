<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Reviews;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Reviews;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Boolean;

/**
 * Lists reviews without their findings; getting one review returns them. Each review says how
 * many findings it posted and how severe the worst one is, so a list shows which reviews found
 * something.
 */
class XList extends Action
{
    use HTTP;

    /** Reviews whose findings are counted in one query: at most 100 findings each. */
    private const int FINDINGS_CHUNK = 50;

    /** Finding severities, least severe first, so a severity ranks by its index. */
    private const array SEVERITIES = ['info', 'minor', 'major', 'critical'];

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
            ->label('sdk', new Method(
                namespace: 'reviews',
                group: 'reviews',
                name: 'list',
                description: <<<EOT
                List the code reviews of the project. Reviews are returned without their findings, but with how many they posted and the severity of the worst one; get a review to read them. You can use the query params to filter your results.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_REVIEW_LIST,
                    )
                ],
                // Hidden until code review is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
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

        $this->countFindings($reviews, $dbForProject);

        $response->dynamic(new Document([
            'reviews' => $reviews,
            'total' => $total,
        ]), Response::MODEL_REVIEW_LIST);
    }

    /**
     * Sets on each review how many findings it posted and the severity of the worst. Dropped
     * findings were never posted, so they do not count.
     *
     * @param array<Document> $reviews
     */
    private function countFindings(array $reviews, Database $dbForProject): void
    {
        $counts = [];

        foreach (\array_chunk($reviews, self::FINDINGS_CHUNK) as $chunk) {
            $findings = $dbForProject->find('reviewFindings', [
                Query::equal('reviewInternalId', \array_map(fn (Document $review) => $review->getSequence(), $chunk)),
                Query::notEqual('status', 'dropped'),
                Query::select(['reviewInternalId', 'severity']),
                Query::limit(self::FINDINGS_CHUNK * APP_LIMIT_ARRAY_PARAMS_SIZE),
            ]);

            foreach ($findings as $finding) {
                $reviewId = (string) $finding->getAttribute('reviewInternalId');
                $rank = \array_search($finding->getAttribute('severity'), self::SEVERITIES, true);
                $counts[$reviewId] = [
                    'total' => ($counts[$reviewId]['total'] ?? 0) + 1,
                    'rank' => \max($counts[$reviewId]['rank'] ?? -1, $rank === false ? -1 : $rank),
                ];
            }
        }

        foreach ($reviews as $review) {
            $count = $counts[(string) $review->getSequence()] ?? ['total' => 0, 'rank' => -1];
            $review->setAttribute('findingsTotal', $count['total']);
            $review->setAttribute('severity', $count['rank'] >= 0 ? self::SEVERITIES[$count['rank']] : '');
        }
    }
}
