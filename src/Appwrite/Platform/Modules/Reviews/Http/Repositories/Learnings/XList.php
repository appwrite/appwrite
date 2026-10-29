<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Repositories\Learnings;

use Appwrite\Platform\Modules\Reviews\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

/**
 * The team preferences a repository's reviews follow, oldest first: what people asked the
 * reviewer to remember in a conversation.
 */
class XList extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'listRepositoryLearnings';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Base::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/reviews/repositories/:repositoryId/learnings')
            ->desc('List repository learnings')
            ->groups(['api'])
            ->label('scope', 'public')
            // Keyed on the caller's address: the limit runs before the secret is checked, so any
            // request param would let an unauthenticated caller pick a fresh bucket. {url} is the
            // route pattern, so the review service gets one bucket per route for every project.
            ->label('abuse-key', 'url:{url},ip:{ip}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 10)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->param('repositoryId', '', new UID(), 'Repository ID.')
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
        $dbForProject = $getProjectDB($project);

        $learnings = $authorization->skip(fn () => $dbForProject->find('reviewLearnings', [
            Query::equal('repositoryInternalId', [$repository->getSequence()]),
            Query::orderAsc('$sequence'),
            Query::limit(APP_LIMIT_REVIEW_LEARNINGS),
        ]));

        $response->json([
            'learnings' => \array_map(fn (Document $learning) => $learning->getAttribute('body'), $learnings),
        ]);
    }
}
