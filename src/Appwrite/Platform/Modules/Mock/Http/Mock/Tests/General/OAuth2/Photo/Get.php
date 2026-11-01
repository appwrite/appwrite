<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\Photo;

use Appwrite\Platform\Action;
use Appwrite\Utopia\Response;
use Utopia\Platform\Scope\HTTP;

/**
 * Static profile picture for the mock OAuth2 user, served from the Appwrite
 * container itself so the avatars OAuth2 provider can actually fetch it.
 */
class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getOAuth2Photo';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/mock/tests/general/oauth2/photo')
            ->desc('OAuth2 User Photo')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(Response $response): void
    {
        // Solid #00FF00 PNG, 64x64
        $photo = 'iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAATUlEQVR42u3PQQ0AAAgEoNP+nbWBfzdoQGXyWicCAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICArcFUYYBf4Fjt4EAAAAASUVORK5CYII=';

        $response
            ->setContentType('image/png')
            ->file(\base64_decode($photo));
    }
}
