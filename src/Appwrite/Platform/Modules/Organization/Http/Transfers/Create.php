<?php

namespace Appwrite\Platform\Modules\Organization\Http\Transfers;

use Appwrite\Auth\Key;
use Appwrite\Event\Context\Audit as AuditContext;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Auth\Proofs\Token;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Nullable;

class Create extends Action
{
    use HTTP;

    private const DEFAULT_EXPIRE = 24 * 60 * 60;
    private const MAX_EXPIRE = 30 * 24 * 60 * 60;
    // Clients compute "30 days from now" with their own clock, which can run ahead of ours
    private const CLOCK_SKEW = 5 * 60;

    public static function getName()
    {
        return 'createProjectTransfer';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/organization/transfers')
            ->desc('Create project transfer')
            ->groups(['api', 'organization'])
            ->label('scope', 'projects.write')
            ->label('audits.event', 'transfer.create')
            ->label('audits.resource', 'project/{request.projectId}')
            ->label('sdk', new Method(
                namespace: 'organization',
                group: 'transfers',
                name: 'createProjectTransfer',
                description: <<<EOT
                Create a transfer for a project in your organization. Share the returned transfer ID and secret with another organization so it can claim the project, even when you are not a member of it.

                The secret is only returned once. A transfer expires after 24 hours unless you set a different expiration date.
                EOT,
                auth: [AuthType::ADMIN, AuthType::ORGANIZATION],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_TRANSFER,
                    )
                ],
            ))
            ->param('projectId', '', new UID(), 'ID of the project to transfer.')
            ->param('recipientId', '', new UID(), 'ID of the only organization allowed to claim the project. Leave empty to allow any organization.', true)
            ->param('expire', null, new Nullable(new DatetimeValidator(requireDateInFuture: true)), 'Expiration time in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. Defaults to 24 hours from now. Maximum of 30 days from now.', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('team')
            ->inject('authorization')
            ->inject('apiKey')
            ->inject('proofForToken')
            ->inject('auditContext')
            ->callback($this->action(...));
    }

    public function action(
        string $projectId,
        string $recipientId,
        ?string $expire,
        Response $response,
        Database $dbForPlatform,
        Document $team,
        Authorization $authorization,
        ?Key $apiKey,
        Token $proofForToken,
        AuditContext $auditContext,
    ) {
        $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $projectId));

        if ($project->isEmpty() || $project->getId() === 'console' || $project->getAttribute('teamInternalId') !== $team->getSequence()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        // A key bound to one project must not give away a sibling project
        if ($apiKey !== null && !$apiKey->isProjectCheckDisabled() && $apiKey->getProjectId() !== '' && $apiKey->getProjectId() !== $project->getId()) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        $auditContext->project = $project;

        $recipient = new Document();
        if ($recipientId !== '') {
            $recipient = $authorization->skip(fn () => $dbForPlatform->getDocument('teams', $recipientId));

            if ($recipient->isEmpty()) {
                throw new Exception(Exception::TEAM_NOT_FOUND);
            }

            if ($recipient->getSequence() === $team->getSequence()) {
                throw new Exception(Exception::TRANSFER_ALREADY_OWNED);
            }
        }

        $now = new \DateTime();
        if ($expire === null) {
            $expire = DateTime::addSeconds($now, self::DEFAULT_EXPIRE);
        } elseif (new \DateTime($expire) > (clone $now)->modify('+' . (self::MAX_EXPIRE + self::CLOCK_SKEW) . ' seconds')) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Expiration time must be at most 30 days from now.');
        }

        $secret = $proofForToken->generate();

        $transfer = $authorization->skip(fn () => $dbForPlatform->createDocument('transfers', new Document([
            '$id' => Id::unique(),
            '$permissions' => [],
            'resourceType' => 'projects',
            'resourceId' => $project->getId(),
            'resourceInternalId' => $project->getSequence(),
            'senderType' => 'teams',
            'senderId' => $team->getId(),
            'senderInternalId' => $team->getSequence(),
            'recipientType' => 'teams',
            'recipientId' => $recipient->isEmpty() ? '' : $recipient->getId(),
            'recipientInternalId' => $recipient->isEmpty() ? '' : $recipient->getSequence(),
            'secret' => $proofForToken->hash($secret), // One way hash, so a leaked database can't claim projects
            'expire' => $expire,
        ])));

        $this->present($transfer, $project)->setAttribute('secret', $secret);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($transfer, Response::MODEL_TRANSFER);
    }
}
