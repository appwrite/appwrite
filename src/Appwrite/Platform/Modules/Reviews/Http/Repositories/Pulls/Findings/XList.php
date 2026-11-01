<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Repositories\Pulls\Findings;

use Appwrite\Platform\Modules\Reviews\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Range;

/**
 * The findings of a pull request's completed reviews, oldest first, for the review service:
 * it follows up on them in later reviews, answers in their threads and lists the open ones.
 */
class XList extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'listPullFindings';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Base::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/reviews/repositories/:repositoryId/pulls/:pullNumber/findings')
            ->desc('List pull request findings')
            ->groups(['api'])
            ->label('scope', 'public')
            // Keyed on the caller's address: the limit runs before the secret is checked, so any
            // request param would let an unauthenticated caller pick a fresh bucket. {url} is the
            // route pattern, so the review service gets one bucket per route for every project.
            ->label('abuse-key', 'url:{url},ip:{ip}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 10)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->param('repositoryId', '', new UID(), 'Repository ID.')
            ->param('pullNumber', 0, new Range(1, PHP_INT_MAX), 'Pull request number.')
            ->param('projectId', '', new UID(), 'Project ID.')
            ->param('limit', APP_LIMIT_REVIEW_FINDINGS, new Range(1, APP_LIMIT_REVIEW_FINDINGS), 'Most findings to return.', true)
            ->inject('request')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $repositoryId,
        int $pullNumber,
        string $projectId,
        int $limit,
        Request $request,
        Response $response,
        Database $dbForPlatform,
        callable $getProjectDB,
        Authorization $authorization,
    ): void {
        $this->authenticate($request);

        $project = $this->getProject($projectId, $dbForPlatform, $authorization);
        $repository = $this->getRepository($repositoryId, $projectId, $dbForPlatform, $authorization);
        $dbForProject = $getProjectDB($project);

        $reviews = $this->listPullReviews($repository, $pullNumber, [Query::equal('status', ['completed'])], $dbForProject, $authorization);

        $findings = empty($reviews) ? [] : $authorization->skip(fn () => $dbForProject->find('reviewFindings', [
            Query::equal('reviewInternalId', \array_map(fn (Document $review) => $review->getSequence(), $reviews)),
            Query::orderAsc('$sequence'),
            Query::limit($limit),
        ]));

        $response->json([
            'findings' => \array_map($this->toFindingRecord(...), $findings),
        ]);
    }
}
