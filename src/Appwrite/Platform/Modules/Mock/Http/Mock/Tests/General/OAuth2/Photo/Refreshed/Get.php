<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\Photo\Refreshed;

use Appwrite\Platform\Action;
use Appwrite\Utopia\Response;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getOAuth2PhotoRefreshed';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/mock/tests/general/oauth2/photo-refreshed')
            ->desc('OAuth2 Refreshed User Photo')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(Response $response): void
    {
        // Solid #FF0000 PNG, 64x64
        $photo = 'iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAYAAACqaXHeAAAAPUlEQVR42u3BAQEAAACCIP+vbkhAAQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAACEG12AAAH7Qv1cAAAAAElFTkSuQmCC';

        $response
            ->setContentType('image/png')
            ->file(\base64_decode($photo));
    }
}
