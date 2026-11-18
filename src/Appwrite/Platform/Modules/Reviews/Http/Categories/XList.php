<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Categories;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\Finding;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\WhiteList;

/**
 * How the findings of each category landed with the people they were posted for: how many were
 * fixed or liked, and how many were declined or disliked. A category with many noisy findings is
 * one the reviewer should flag less, or that a learning should narrow. Computed from the completed
 * reviews of the range, newest first, up to APP_LIMIT_REVIEW_AUTHORS of them.
 */
class XList extends Action
{
    use HTTP;

    private const array RANGES = [
        '7d' => 7,
        '30d' => 30,
        '90d' => 90,
    ];

    private const int PAGE = 1000;

    /** Reviews whose findings are read in one query: at most 100 findings each. */
    private const int FINDINGS_CHUNK = 50;

    public static function getName()
    {
        return 'listReviewCategories';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/reviews/categories')
            ->desc('List review categories')
            ->groups(['api', 'reviews'])
            ->label('scope', 'reviews.read')
            ->label('sdk', new Method(
                namespace: 'reviews',
                group: 'categories',
                name: 'listCategories',
                description: <<<EOT
                List the categories of the findings posted on the project's pull requests over a range, with how many people found useful (fixed, or liked with 👍) and how many noisy (dismissed, resolved without a change, or disliked with 👎). Categories with the most noisy findings come first.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_REVIEW_CATEGORY_LIST,
                    )
                ],
                // Hidden until code review is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('range', '30d', new WhiteList(\array_keys(self::RANGES), true), 'Date range of the reviews.', true)
            ->param('repositoryId', '', new UID(), 'Count only the reviews of this repository.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $range,
        string $repositoryId,
        Response $response,
        Database $dbForProject,
    ): void {
        $since = DateTime::addSeconds(new \DateTime(), -self::RANGES[$range] * 86400);

        $filters = [
            Query::equal('status', ['completed']),
            Query::greaterThanEqual('$createdAt', $since),
        ];
        if ($repositoryId !== '') {
            $filters[] = Query::equal('repositoryId', [$repositoryId]);
        }

        $categories = [];
        foreach (\array_chunk($this->listReviews($filters, $dbForProject), self::FINDINGS_CHUNK) as $chunk) {
            // Dropped findings were never posted, so nobody could judge them.
            $findings = $dbForProject->find('reviewFindings', [
                Query::equal('reviewInternalId', $chunk),
                Query::notEqual('status', 'dropped'),
                Query::select(['category', 'status', 'upvotes', 'downvotes']),
                Query::limit(self::FINDINGS_CHUNK * APP_LIMIT_ARRAY_PARAMS_SIZE),
            ]);

            foreach ($findings as $finding) {
                $name = $finding->getAttribute('category');
                $category = &$categories[$name];
                $category ??= [
                    'category' => $name,
                    'findings' => 0,
                    'useful' => 0,
                    'noisy' => 0,
                    'resolved' => 0,
                    'dismissed' => 0,
                    'upvotes' => 0,
                    'downvotes' => 0,
                ];

                $category['findings']++;
                $status = $finding->getAttribute('status');
                if ($status === 'resolved' || $status === 'dismissed') {
                    $category[$status]++;
                }
                $category['upvotes'] += $finding->getAttribute('upvotes', 0);
                $category['downvotes'] += $finding->getAttribute('downvotes', 0);
                $useful = (new Finding($finding->getArrayCopy()))->isUseful();
                if ($useful !== null) {
                    $category[$useful ? 'useful' : 'noisy']++;
                }
                unset($category);
            }
        }

        $documents = \array_map(fn (array $category) => new Document($category), \array_values($categories));
        \usort($documents, fn (Document $a, Document $b) => [$b->getAttribute('noisy'), $b->getAttribute('findings')] <=> [$a->getAttribute('noisy'), $a->getAttribute('findings')]);

        $response->dynamic(new Document([
            'categories' => $documents,
            'total' => \count($documents),
        ]), Response::MODEL_REVIEW_CATEGORY_LIST);
    }

    /**
     * Sequences of the completed reviews of the range, newest first.
     *
     * @param array<Query> $filters
     * @return array<int|string>
     */
    private function listReviews(array $filters, Database $dbForProject): array
    {
        $reviews = [];
        $cursor = null;

        while (\count($reviews) < APP_LIMIT_REVIEW_AUTHORS) {
            $page = $dbForProject->find('reviews', [
                ...$filters,
                Query::select(['$id', '$sequence', '$createdAt']),
                Query::orderDesc('$createdAt'),
                Query::limit(\min(self::PAGE, APP_LIMIT_REVIEW_AUTHORS - \count($reviews))),
                ...($cursor === null ? [] : [Query::cursorAfter($cursor)]),
            ]);

            \array_push($reviews, ...\array_map(fn (Document $review) => $review->getSequence(), $page));

            if (\count($page) < self::PAGE) {
                break;
            }

            $cursor = $page[\count($page) - 1];
        }

        return $reviews;
    }
}
