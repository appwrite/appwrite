<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Repositories\Pulls\Findings\Status;

use Appwrite\Platform\Modules\Reviews\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

/**
 * Settles findings of a pull request: fixed by a later commit, or dismissed in a conversation or
 * by a maintainer resolving the thread. With `from`, only findings still in that status move, so
 * one settled meanwhile is left as it is. Findings of other pull requests are ignored.
 */
class Update extends Base
{
    use HTTP;

    private const array STATUSES = ['posted', 'dropped', 'resolved', 'dismissed'];

    public static function getName()
    {
        return 'updatePullFindingsStatus';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Base::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/reviews/repositories/:repositoryId/pulls/:pullNumber/findings/status')
            ->desc('Update pull request findings status')
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
            ->param('findingIds', [], new ArrayList(new UID(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Finding IDs.')
            ->param('status', '', new WhiteList(self::STATUSES, true), 'Finding status.')
            ->param('reason', '', new Text(2048, 0), 'Why the findings moved.', true)
            ->param('from', '', new WhiteList(['', ...self::STATUSES], true), 'Only move findings still in this status.', true)
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
        array $findingIds,
        string $status,
        string $reason,
        string $from,
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

        $reviews = $this->listPullReviews($repository, $pullNumber, [], $dbForProject, $authorization);

        $findings = empty($reviews) || empty($findingIds) ? [] : $authorization->skip(fn () => $dbForProject->find('reviewFindings', [
            Query::equal('$id', \array_values(\array_unique($findingIds))),
            Query::equal('reviewInternalId', \array_map(fn (Document $review) => $review->getSequence(), $reviews)),
            Query::limit(APP_LIMIT_ARRAY_PARAMS_SIZE),
        ]));

        foreach ($findings as $finding) {
            if ($from !== '' && $finding->getAttribute('status', 'posted') !== $from) {
                continue;
            }

            $authorization->skip(fn () => $dbForProject->updateDocument('reviewFindings', $finding->getId(), new Document([
                'status' => $status,
                'dropReason' => $reason,
            ])));
        }

        $response->noContent();
    }
}
