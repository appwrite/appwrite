<?php

namespace Appwrite\Platform\Modules\Presence\HTTP;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;

class Get extends Action
{
    public static function getName(): string
    {
        return 'getPresence';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            // TODO: create a separate scope
            ->groups(['api', 'presence'])
            ->label('scope', 'documents.read')
            ->setHttpPath('/v1/presence:/:presenceId')
            ->param('presenceId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Presence ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->inject('user')
            ->callback($this->action(...));
    }
    // Since POC so not adding queries or advanced filtering now
    // just for getting group based presence list based on permissions
    public function action(string $presenceId, UtopiaResponse $response, Database $dbForProject, Authorization $authorization, Document $user): void
    {
        try {
            // `presenceLogs` are permissioned per-document (via `$permissions`),
            // but DB-level auth for this metadata collection may be missing.
            // So we fetch without auth checks, then enforce read permission using the document itself.
            $document = $authorization->skip(fn () => $dbForProject->getDocument('presenceLogs', $presenceId));
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order column '{$e->getAttribute()}' had a null value. Cursor pagination requires all rows order column values are non-null.");
        } catch (QueryException) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID);
        }

        Console::info(
            \sprintf(
                '[Presence][Get] presenceId=%s requesterUserId=%s requesterUserInternalId=%s requesterRoles=%s',
                $presenceId,
                $user->getId(),
                (string) $user->getSequence(),
                \json_encode($authorization->getRoles(), JSON_UNESCAPED_SLASHES)
            )
        );

        if ($document->isEmpty()) {
            throw new Exception(Exception::DOCUMENT_NOT_FOUND, 'Presence not found.');
        }

        // Document permission context
        $documentUserId = $document->getAttribute('userId');
        $documentReadRoles = $document->getRead();
        Console::info(
            \sprintf(
                '[Presence][Get] presenceId=%s documentUserId=%s documentAllowedReadRoles=%s documentAllPermissions=%s',
                $presenceId,
                (string) $documentUserId,
                \json_encode($documentReadRoles, JSON_UNESCAPED_SLASHES),
                \json_encode($document->getPermissions(), JSON_UNESCAPED_SLASHES)
            )
        );

        if (
            !($authorization->isValid(
                new Input(Database::PERMISSION_READ, $document->getRead())
            ))
        ) {
            Console::info(
                \sprintf(
                    '[Presence][Get][DENY] presenceId=%s requesterUserId=%s allowedReadRoles=%s requestedReadRoles=%s validatorDescription=%s',
                    $presenceId,
                    $user->getId(),
                    \json_encode($documentReadRoles, JSON_UNESCAPED_SLASHES),
                    \json_encode($authorization->getRoles(), JSON_UNESCAPED_SLASHES),
                    $authorization->getDescription()
                )
            );
            // Don't leak existence for unauthorized callers.
            throw new Exception(Exception::DOCUMENT_NOT_FOUND, 'Presence not found.');
        }

        $response->dynamic($document, UtopiaResponse::MODEL_PRESENCE);
    }
}
