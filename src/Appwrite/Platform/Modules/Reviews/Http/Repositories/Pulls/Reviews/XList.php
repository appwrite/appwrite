<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Repositories\Pulls\Reviews;

use Appwrite\Platform\Modules\Reviews\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Range;

/**
 * Every review of a pull request, newest first, for the review service: it skips a head that
 * was reviewed already, reviews only what changed since the last one, and keeps the summary
 * comment in step with them.
 */
class XList extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'listPullReviews';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Base::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/reviews/repositories/:repositoryId/pulls/:pullNumber/reviews')
            ->desc('List pull request reviews')
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
        Request $request,
        Response $response,
        Database $dbForPlatform,
        callable $getProjectDB,
        Authorization $authorization,
    ): void {
        $this->authenticate($request);

        $project = $this->getProject($projectId, $dbForPlatform, $authorization);
        $repository = $this->getRepository($repositoryId, $projectId, $dbForPlatform, $authorization);
        $reviews = $this->listPullReviews($repository, $pullNumber, [], $getProjectDB($project), $authorization);

        $response->json([
            'reviews' => \array_map($this->toReviewRecord(...), $reviews),
        ]);
    }
}
