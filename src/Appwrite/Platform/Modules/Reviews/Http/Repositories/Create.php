<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Repositories;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Permission;
use Appwrite\Reviews\Provider;
use Appwrite\Reviews\Strength;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Id;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

/**
 * Turns code review on for a repository: from now on its pull requests are queued for review.
 */
class Create extends Action
{
    use HTTP;
    use Permission;

    public static function getName()
    {
        return 'createReviewRepository';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/reviews/repositories')
            ->desc('Create repository')
            ->groups(['api', 'reviews'])
            ->label('scope', 'reviews.write')
            ->label('audits.event', 'repository.create')
            ->label('audits.resource', 'repository/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'reviews',
                group: 'repositories',
                name: 'createRepository',
                description: <<<EOT
                Turn on code review for a GitHub or Origin repository connected through one of the project's installations. From then on, its pull requests are reviewed when they are opened or updated. Each project turns on review for a repository once; several projects may review the same repository. The strength sets how hard its pull requests are reviewed, and what each review costs.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_REVIEW_REPOSITORY,
                    )
                ],
                // Hidden until code review is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('installationId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getMaxUidLength()), 'Appwrite installation ID the repository is connected through.', false, ['dbForPlatform'])
            ->param('providerRepositoryId', '', new Text(64), 'Repository ID on the installation\'s provider.')
            ->param('strength', Strength::Normal->value, new WhiteList(Strength::values(), true), 'How hard pull requests are reviewed. basic reviews with cheaper models and reads the code only, normal is the default, expert reviews with stronger models and subagents.', true, enum: new Enum(name: 'ReviewStrength'))
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('vcsFactory')
            ->callback($this->action(...));
    }

    public function action(
        string $installationId,
        string $providerRepositoryId,
        string $strength,
        Response $response,
        Document $project,
        Database $dbForPlatform,
        Authorization $authorization,
        VcsFactory $vcsFactory,
    ): void {
        // Reviews are optional on self-hosted: without the secret no review service is running.
        if (System::getEnv('_APP_REVIEWS_SECRET', '') === '') {
            throw new Exception(Exception::GENERAL_SERVICE_DISABLED, 'Reviews are disabled on this instance. Set _APP_REVIEWS_SECRET and run the review service to enable them.');
        }

        $installation = $authorization->skip(fn () => $dbForPlatform->getDocument('installations', $installationId));

        if ($installation->isEmpty() || $installation->getAttribute('projectId') !== $project->getId()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $provider = Provider::tryFrom($installation->getAttribute('provider', ''));
        if ($provider === null) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Code review supports GitHub and Origin repositories only.');
        }

        // GitHub repository ids are numeric.
        if ($provider === Provider::GitHub && !\ctype_digit($providerRepositoryId)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Param "providerRepositoryId" must be a numeric GitHub repository ID.');
        }

        // Several projects may review the same repository, each once.
        $existing = $authorization->skip(fn () => $dbForPlatform->findOne('repositories', [
            Query::equal('projectInternalId', [$project->getSequence()]),
            Query::equal('providerRepositoryId', [$providerRepositoryId]),
            Query::equal('resourceType', [RESOURCE_TYPE_REVIEWS]),
        ]));
        if (!$existing->isEmpty()) {
            throw new Exception(Exception::REVIEW_REPOSITORY_ALREADY_EXISTS);
        }

        // The installation has to reach the repository: a repository id alone would let a
        // project claim the reviews of another organization's repository.
        $vcs = $vcsFactory->fromInstallation($installation);

        try {
            $vcs->getRepositoryName($providerRepositoryId);
        } catch (\Throwable) {
            throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
        }

        // The id is derived from the project and the repository, so two concurrent creates in one
        // project collide on it instead of both passing the check above.
        try {
            $repository = $authorization->skip(fn () => $dbForPlatform->createDocument('repositories', new Document([
                '$id' => Id::custom($this->getRepositoryId($project, $provider, $providerRepositoryId)),
                '$permissions' => $this->getPermissions($project->getAttribute('teamId', ''), $project->getId()),
                'installationId' => $installation->getId(),
                'installationInternalId' => $installation->getSequence(),
                'projectId' => $project->getId(),
                'projectInternalId' => $project->getSequence(),
                'providerRepositoryId' => $providerRepositoryId,
                // Reviews belong to the project rather than to one of its resources.
                'resourceId' => $project->getId(),
                'resourceInternalId' => $project->getSequence(),
                'resourceType' => RESOURCE_TYPE_REVIEWS,
                'providerPullRequestIds' => [],
                'strength' => $strength,
            ])));
        } catch (DuplicateException) {
            throw new Exception(Exception::REVIEW_REPOSITORY_ALREADY_EXISTS);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($repository, Response::MODEL_REVIEW_REPOSITORY);
    }

    /**
     * The row's id: reviews-<project>-<repository>. GitHub's numeric repository ids fit as they
     * are; any other provider's, such as Origin's repo_..., is hashed to fit the id's length.
     */
    private function getRepositoryId(Document $project, Provider $provider, string $providerRepositoryId): string
    {
        $prefix = 'reviews-' . $project->getSequence() . '-';
        if ($provider === Provider::GitHub) {
            return $prefix . $providerRepositoryId;
        }

        return $prefix . \substr(\hash('sha256', $provider->value . ':' . $providerRepositoryId), 0, 36 - \strlen($prefix));
    }
}
