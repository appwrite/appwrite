<?php

namespace Appwrite\Platform\Modules\Messaging\Http\WhatsApp\Events;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

/**
 * The handshake Meta performs once, when the webhook is first subscribed in the app dashboard.
 * It echoes a challenge back so Meta can confirm the endpoint is ours before it sends anything.
 */
class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getWhatsAppEvent';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/messaging/whatsapp/events')
            ->desc('Verify the WhatsApp webhook subscription')
            // Deliberately not in the messaging group: its init hook rejects a request that
            // carries no project header, and Meta has no project to name.
            ->groups(['api'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->inject('request')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(Request $request, Response $response): void
    {
        $token = System::getEnv('_APP_WHATSAPP_WEBHOOK_TOKEN', '');

        if (empty($token)) {
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN, 'WhatsApp webhooks are not configured on this instance');
        }

        // Meta names these hub.mode, hub.verify_token and hub.challenge, but the query parser
        // renames a dot to an underscore before the request reaches here.
        $mode = (string) $request->getParam('hub_mode', '');
        $verify = (string) $request->getParam('hub_verify_token', '');
        $challenge = (string) $request->getParam('hub_challenge', '');

        if ($mode !== 'subscribe' || !\hash_equals($token, $verify)) {
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN, 'Invalid verify token');
        }

        $response
            ->setContentType(Response::CONTENT_TYPE_TEXT)
            ->send($challenge);
    }
}
