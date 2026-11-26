<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Authors;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
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
 * How the authors of a project's pull requests fare in review: what their pull requests were
 * found to have, how much of it they fixed, and how often a review approved. Computed from the
 * completed reviews of the range, newest first, up to APP_LIMIT_REVIEW_AUTHORS of them.
 */
class XList extends Action
{
    use HTTP;

    private const array RANGES = [
        '7d' => 7,
        '30d' => 30,
        '90d' => 90,
    ];

    /**
     * Tiers by score, worst first, so the average of a set of tiers maps back to one.
     */
    private const array TIERS = ['F', 'D', 'C', 'B', 'A', 'S'];

    private const int PAGE = 1000;

    /** Reviews whose findings are read in one query: at most 100 findings each. */
    private const int FINDINGS_CHUNK = 50;

    public static function getName()
    {
        return 'listReviewAuthors';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/reviews/authors')
            ->desc('List review authors')
            ->groups(['api', 'reviews'])
            ->label('scope', 'reviews.read')
            ->label('sdk', new Method(
                namespace: 'reviews',
                group: 'authors',
                name: 'listAuthors',
                description: <<<EOT
                List the authors of the project's reviewed pull requests with how their pull requests fared in review over a range: pull requests and reviews, findings by severity, findings fixed and dismissed, approvals, and the average merge confidence tier. Authors with the most reviewed pull requests come first.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_REVIEW_AUTHOR_LIST,
                    )
                ],
                // Hidden until code review is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('range', '30d', new WhiteList(\array_keys(self::RANGES), true), 'Date range of the reviews: whole days, today included.', true)
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
        // Whole UTC days, today included, as the summary counts them.
        $since = DateTime::format((new \DateTime('now', new \DateTimeZone('UTC')))
            ->setTime(0, 0)
            ->modify('-' . (self::RANGES[$range] - 1) . ' days'));

        $filters = [
            Query::equal('status', ['completed']),
            Query::greaterThanEqual('$createdAt', $since),
        ];
        if ($repositoryId !== '') {
            $filters[] = Query::equal('repositoryId', [$repositoryId]);
        }

        $reviews = $this->listReviews($filters, $dbForProject);
        $findings = $this->countFindings($reviews, $dbForProject);

        // Pull requests by repository and number, each with its reviews oldest first.
        $pulls = [];
        foreach (\array_reverse($reviews) as $review) {
            $key = $review->getAttribute('repositoryInternalId') . ':' . $review->getAttribute('providerPullRequestId');
            $pulls[$key][] = $review;
        }

        $authors = [];
        foreach ($pulls as $pull) {
            $first = $pull[0];
            $latest = $pull[\count($pull) - 1];

            // Reviews queued before the author was kept have none; a later one of the pull request may.
            $name = '';
            foreach ($pull as $review) {
                $name = $review->getAttribute('providerPullRequestAuthor', '') ?: $name;
            }

            $author = $authors[$name] ?? [
                'author' => $name,
                'pulls' => 0,
                'reviews' => 0,
                'approved' => 0,
                'approvedFirst' => 0,
                'findings' => 0,
                'critical' => 0,
                'major' => 0,
                'resolved' => 0,
                'dismissed' => 0,
                'tiers' => [],
                'lastReviewAt' => '',
            ];

            $author['pulls']++;
            $author['reviews'] += \count($pull);
            $author['approved'] += $latest->getAttribute('verdict') === 'approve' ? 1 : 0;
            $author['approvedFirst'] += $first->getAttribute('verdict') === 'approve' ? 1 : 0;

            $tier = \array_search($latest->getAttribute('tier', ''), self::TIERS, true);
            if ($tier !== false) {
                $author['tiers'][] = $tier;
            }

            foreach ($pull as $review) {
                foreach ($findings[$review->getSequence()] ?? [] as $metric => $count) {
                    $author[$metric] += $count;
                }
            }

            $author['lastReviewAt'] = \max($author['lastReviewAt'], $latest->getCreatedAt() ?? '');

            $authors[$name] = $author;
        }

        $documents = [];
        foreach ($authors as $author) {
            $tiers = $author['tiers'];
            unset($author['tiers']);
            $author['tier'] = empty($tiers) ? '' : self::TIERS[(int) \round(\array_sum($tiers) / \count($tiers))];
            $documents[] = new Document($author);
        }

        \usort($documents, fn (Document $a, Document $b) => [$b->getAttribute('pulls'), $b->getAttribute('reviews')] <=> [$a->getAttribute('pulls'), $a->getAttribute('reviews')]);

        $response->dynamic(new Document([
            'authors' => $documents,
            'total' => \count($documents),
        ]), Response::MODEL_REVIEW_AUTHOR_LIST);
    }

    /**
     * Completed reviews of the range, newest first.
     *
     * @param array<Query> $filters
     * @return array<Document>
     */
    private function listReviews(array $filters, Database $dbForProject): array
    {
        $reviews = [];
        $cursor = null;

        while (\count($reviews) < APP_LIMIT_REVIEW_AUTHORS) {
            $page = $dbForProject->find('reviews', [
                ...$filters,
                Query::select(['$id', '$sequence', '$createdAt', 'repositoryInternalId', 'providerPullRequestId', 'providerPullRequestAuthor', 'verdict', 'tier']),
                Query::orderDesc('$createdAt'),
                Query::limit(\min(self::PAGE, APP_LIMIT_REVIEW_AUTHORS - \count($reviews))),
                ...($cursor === null ? [] : [Query::cursorAfter($cursor)]),
            ]);

            \array_push($reviews, ...$page);

            if (\count($page) < self::PAGE) {
                break;
            }

            $cursor = $page[\count($page) - 1];
        }

        return $reviews;
    }

    /**
     * What each review found, by review sequence. Dropped findings were never posted, so they
     * do not count.
     *
     * @param array<Document> $reviews
     * @return array<string, array<string, int>>
     */
    private function countFindings(array $reviews, Database $dbForProject): array
    {
        $counts = [];

        foreach (\array_chunk($reviews, self::FINDINGS_CHUNK) as $chunk) {
            $findings = $dbForProject->find('reviewFindings', [
                Query::equal('reviewInternalId', \array_map(fn (Document $review) => $review->getSequence(), $chunk)),
                Query::notEqual('status', 'dropped'),
                Query::select(['reviewInternalId', 'severity', 'status']),
                Query::limit(self::FINDINGS_CHUNK * APP_LIMIT_ARRAY_PARAMS_SIZE),
            ]);

            foreach ($findings as $finding) {
                $count = &$counts[$finding->getAttribute('reviewInternalId')];
                $count ??= ['findings' => 0, 'critical' => 0, 'major' => 0, 'resolved' => 0, 'dismissed' => 0];

                $count['findings']++;
                $severity = $finding->getAttribute('severity');
                if ($severity === 'critical' || $severity === 'major') {
                    $count[$severity]++;
                }
                $status = $finding->getAttribute('status');
                if ($status === 'resolved' || $status === 'dismissed') {
                    $count[$status]++;
                }
                unset($count);
            }
        }

        return $counts;
    }
}
