<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Installations;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Reviews\Strength;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\WhiteList;

/**
 * Sets whether repositories added to a GitHub installation later are reviewed: each one that
 * joins it, picked in its settings or created in an account it covers in full, gets code review
 * at the chosen strength. Repositories already in the installation are left as they are.
 */
class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateReviewInstallation';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/reviews/installations/:installationId')
            ->desc('Update installation')
            ->groups(['api', 'reviews'])
            ->label('scope', 'reviews.write')
            ->label('audits.event', 'installation.update')
            ->label('audits.resource', 'installation/{request.installationId}')
            ->label('sdk', new Method(
                namespace: 'reviews',
                group: 'installations',
                name: 'updateInstallation',
                description: <<<EOT
                Choose whether code review is turned on for repositories added to a GitHub installation later, and how hard their pull requests are reviewed. A repository counts as added when it is picked in the installation's settings, or created in an account the installation covers in full. Repositories already in the installation keep their current setting. Reviews are only charged when they run.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_INSTALLATION,
                    )
                ],
                // Hidden until code review is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('installationId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getMaxUidLength()), 'Installation ID.', false, ['dbForPlatform'])
            ->param('newRepositories', false, new Boolean(true), 'Turn on code review for repositories added to the installation later.')
            ->param('strength', Strength::Normal->value, new WhiteList(Strength::values(), true), 'How hard pull requests of repositories added later are reviewed. Possible values: basic, normal, expert.', true, enum: new Enum(name: 'ReviewStrength'))
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $installationId,
        bool $newRepositories,
        string $strength,
        Response $response,
        Document $project,
        Database $dbForPlatform,
        Authorization $authorization,
    ): void {
        if (System::getEnv('_APP_REVIEWS_SECRET', '') === '') {
            throw new Exception(Exception::GENERAL_SERVICE_DISABLED, 'Reviews are disabled on this instance. Set _APP_REVIEWS_SECRET and run the review service to enable them.');
        }

        $installation = $authorization->skip(fn () => $dbForPlatform->getDocument('installations', $installationId));

        if ($installation->isEmpty() || $installation->getAttribute('projectInternalId') !== $project->getSequence()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        if ($installation->getAttribute('provider') !== 'github') {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Code review supports GitHub repositories only.');
        }

        $installation = $authorization->skip(fn () => $dbForPlatform->updateDocument('installations', $installation->getId(), new Document([
            'reviewNewRepositories' => $newRepositories,
            'reviewStrength' => $strength,
        ])));

        $response->dynamic($installation, Response::MODEL_INSTALLATION);
    }
}
