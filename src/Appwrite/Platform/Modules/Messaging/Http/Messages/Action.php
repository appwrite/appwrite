<?php

namespace Appwrite\Platform\Modules\Messaging\Http\Messages;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Request;
use Utopia\Database\Document;
use Utopia\Platform\Action as UtopiaAction;

class Action extends UtopiaAction
{
    /**
     * A push image URL is a bearer capability for the file: its endpoint is public so that devices can
     * fetch it, and it checks nothing but the token. A caller that cannot read files must not receive it.
     */
    protected function redactImageUrl(Document $message, Request $request): Document
    {
        $data = $message->getAttribute('data', []);

        if (!isset($data['image']['url']) || \in_array('files.read', $request->getScopes(), true)) {
            return $message;
        }

        unset($data['image']['url']);

        return $message->setAttribute('data', $data);
    }

    /**
     * A typed route may only reach a message of its own provider type. Delivery authority is the stored
     * document alone: the worker reads the provider type from it, so a message updated through another
     * type's route is still delivered as what it is, past every check its own route performs.
     */
    protected function assertProviderType(Document $message, string $providerType): void
    {
        if ($message->getAttribute('providerType') !== $providerType) {
            throw new Exception(Exception::MESSAGE_NOT_FOUND);
        }
    }

    /**
     * The worker reads and delivers stored attachments with authorization disabled, so any update that
     * leaves a message carrying attachments needs files.read. Keyed on the attachments the update
     * resolves to rather than on one route's params, and run before any mutation so a refused request
     * leaves no schedule or message a worker could act on.
     */
    protected function requireAttachmentsScope(Document $message, Request $request, ?array $attachments = null): void
    {
        $resolved = $attachments ?? $message->getAttribute('data', [])['attachments'] ?? [];

        if (empty($resolved) || \in_array('files.read', $request->getScopes(), true)) {
            return;
        }

        throw new Exception(Exception::GENERAL_UNAUTHORIZED_SCOPE, 'Attachments require the files.read scope.');
    }
}
