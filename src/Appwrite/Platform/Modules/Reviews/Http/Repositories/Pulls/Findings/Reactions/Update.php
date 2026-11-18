<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Repositories\Pulls\Findings\Reactions;

use Appwrite\Abuse\Limit;
use Appwrite\Extend\Exception;
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
use Utopia\Validator\Assoc;
use Utopia\Validator\Range;

/**
 * Stores the 👍 and 👎 on the comments findings of a pull request were posted as, as the review
 * service read them. The counts replace what was stored. Findings of other pull requests are
 * ignored.
 */
class Update extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'updatePullFindingsReactions';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Base::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/reviews/repositories/:repositoryId/pulls/:pullNumber/findings/reactions')
            ->desc('Update pull request findings reactions')
            ->groups(['api'])
            ->label('scope', 'public')
            // Keyed on the caller's address, as the other review service routes: the limit runs
            // before the secret is checked.
            ->label('abuse', [Limit::attempts(['url', 'ip'], APP_LIMIT_WRITE_RATE_DEFAULT * 10, seconds: APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)])
            ->param('repositoryId', '', new UID(), 'Repository ID.')
            ->param('pullNumber', 0, new Range(1, PHP_INT_MAX), 'Pull request number.')
            ->param('projectId', '', new UID(), 'Project ID.')
            ->param('reactions', [], new ArrayList(new Assoc(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Reactions, each with findingId, upvotes and downvotes.')
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
        array $reactions,
        Request $request,
        Response $response,
        Database $dbForPlatform,
        callable $getProjectDB,
        Authorization $authorization,
    ): void {
        $this->authenticate($request);

        $counts = [];
        $uid = new UID();
        foreach ($reactions as $index => $reaction) {
            $findingId = $reaction['findingId'] ?? null;
            $upvotes = $reaction['upvotes'] ?? null;
            $downvotes = $reaction['downvotes'] ?? null;
            if (!$uid->isValid($findingId) || !\is_int($upvotes) || $upvotes < 0 || !\is_int($downvotes) || $downvotes < 0) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "Reaction {$index} is invalid: it needs findingId, and upvotes and downvotes as non-negative integers.");
            }
            $counts[$findingId] = ['upvotes' => $upvotes, 'downvotes' => $downvotes];
        }

        $project = $this->getProject($projectId, $dbForPlatform, $authorization);
        $repository = $this->getRepository($repositoryId, $projectId, $dbForPlatform, $authorization);
        $dbForProject = $getProjectDB($project);

        $reviews = $this->listPullReviews($repository, $pullNumber, [], $dbForProject, $authorization);

        $findings = empty($reviews) || empty($counts) ? [] : $authorization->skip(fn () => $dbForProject->find('reviewFindings', [
            Query::equal('$id', \array_map(strval(...), \array_keys($counts))),
            Query::equal('reviewInternalId', \array_map(fn (Document $review) => $review->getSequence(), $reviews)),
            Query::select(['$id']),
            Query::limit(APP_LIMIT_ARRAY_PARAMS_SIZE),
        ]));

        foreach ($findings as $finding) {
            $authorization->skip(fn () => $dbForProject->updateDocument('reviewFindings', $finding->getId(), new Document($counts[$finding->getId()])));
        }

        $response->noContent();
    }
}
