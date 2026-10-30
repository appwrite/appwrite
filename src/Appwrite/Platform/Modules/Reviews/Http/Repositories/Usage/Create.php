<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Repositories\Usage;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Reviews\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Range;

/**
 * Model calls behind the answer to a pull request comment. A chat has no review, so its usage
 * is keyed on the repository and the comment the answer was queued for.
 */
class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createRepositoryUsage';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Base::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/reviews/repositories/:repositoryId/usage')
            ->desc('Create repository usage')
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
            ->param('pullNumber', 0, new Range(1, PHP_INT_MAX), 'Pull request number.')
            ->param('commentId', 0, new Range(1, PHP_INT_MAX), 'GitHub ID of the comment that was answered.')
            ->param('usage', [], new ArrayList(new Assoc(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Model calls, each with role, provider, model, inputTokens, outputTokens, durationMs and optional cachedInputTokens and reasoningTokens.')
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
        int $pullNumber,
        int $commentId,
        array $usage,
        Request $request,
        Response $response,
        Database $dbForPlatform,
        callable $getProjectDB,
        Authorization $authorization,
    ): void {
        $this->authenticate($request);

        $project = $this->getProject($projectId, $dbForPlatform, $authorization);
        $dbForProject = $getProjectDB($project);

        $chat = $authorization->skip(fn () => $dbForProject->getDocument('reviewChats', 'chat-' . $commentId));

        if (
            $chat->isEmpty()
            || $chat->getAttribute('repositoryId') !== $repositoryId
            || $chat->getAttribute('providerPullRequestId') !== (string) $pullNumber
        ) {
            throw new Exception(Exception::REVIEW_CHAT_NOT_FOUND);
        }

        $documents = $this->prepareUsage($usage, 'reviewChats', $chat);

        if (!empty($documents)) {
            $authorization->skip(fn () => $dbForProject->createDocuments('reviewUsage', $documents));
        }

        $response->noContent();
    }
}
