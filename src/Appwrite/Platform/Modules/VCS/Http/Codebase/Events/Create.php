<?php

namespace Appwrite\Platform\Modules\VCS\Http\Codebase\Events;

use Appwrite\Platform\Action;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Platform\Scope\HTTP;
use Utopia\Span\Span;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createVCSCodebaseEvent';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/vcs/codebase/events')
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
        $payload = \json_decode($request->getRawPayload(), true) ?? [];

        $event = $request->getHeaderLine('x-codebase-event', '') ?: ($payload['event'] ?? '');
        Span::add('vcs.codebase.event.name', $event);

        // Codebase does not deliver push or pull request events yet, so events
        // are only acknowledged to prevent delivery retries.
        $response->json(['success' => true]);
    }
}
