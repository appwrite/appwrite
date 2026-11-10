<?php

namespace Appwrite\Platform\Modules\Messaging\Http\Messages;

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
}
