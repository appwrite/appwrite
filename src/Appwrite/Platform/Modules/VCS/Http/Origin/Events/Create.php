<?php

namespace Appwrite\Platform\Modules\VCS\Http\Origin\Events;

use Appwrite\Platform\Action;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Console;
use Utopia\Platform\Scope\HTTP;
use Utopia\Span\Span;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createVCSOriginEvent';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/vcs/origin/events')
            ->desc('Create event')
            ->groups(['api', 'vcs'])
            ->label('scope', 'public')
            ->inject('request')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(
        Request $request,
        Response $response
    ) {
        // TODO: Temporary debug logging while the Origin integration is verified -- remove afterwards.
        Console::log('[ORIGIN DEBUG] Event received');
        Console::log('[ORIGIN DEBUG] Event headers: ' . \json_encode($request->getHeaders()));
        Console::log('[ORIGIN DEBUG] Event query params: ' . \json_encode($request->getParams()));
        Console::log('[ORIGIN DEBUG] Event raw payload: ' . $request->getRawPayload());

        $payload = \json_decode($request->getRawPayload(), true) ?? [];

        // The delivery header is not confirmed yet -- accept both the Origin
        // name and the pre-rename Codebase one.
        $event = $request->getHeaderLine('x-origin-event', '')
            ?: $request->getHeaderLine('x-codebase-event', '')
            ?: ($payload['event'] ?? '');
        Span::add('vcs.origin.event.name', $event);
        Console::log('[ORIGIN DEBUG] Event name resolved to: "' . $event . '"');

        // Origin does not deliver push or pull request events yet, so events
        // are only acknowledged to prevent delivery retries.
        $response->json(['success' => true]);
    }
}
