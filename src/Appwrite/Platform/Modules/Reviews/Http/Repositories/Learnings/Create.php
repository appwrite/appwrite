<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Repositories\Learnings;

use Appwrite\Network\Validator\PublicURL;
use Appwrite\Platform\Modules\Reviews\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

/**
 * A team preference someone asked the reviewer to remember in a conversation. Later reviews and
 * answers of the repository follow it.
 */
class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createRepositoryLearning';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Base::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/reviews/repositories/:repositoryId/learnings')
            ->desc('Create repository learning')
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
            ->param('body', '', new Text(65535), 'What to remember, in one imperative sentence.')
            ->param('author', '', new Text(255), 'GitHub login of who asked for it.')
            ->param('sourceUrl', '', fn (PublicURL $publicURL) => $publicURL, 'The comment it was asked in.', true, ['publicURL'])
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
        string $body,
        string $author,
        string $sourceUrl,
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

        // hansi.codes keeps a learning whatever its link; one longer than the column is dropped
        // instead of failing the learning.
        if (\strlen($sourceUrl) > 2048) {
            $sourceUrl = '';
        }

        $authorization->skip(fn () => $dbForProject->createDocument('reviewLearnings', new Document([
            '$id' => Id::unique(),
            'repositoryId' => $repository->getId(),
            'repositoryInternalId' => $repository->getSequence(),
            'body' => $body,
            'author' => $author,
            'sourceUrl' => $sourceUrl,
        ])));

        $response->noContent();
    }
}
