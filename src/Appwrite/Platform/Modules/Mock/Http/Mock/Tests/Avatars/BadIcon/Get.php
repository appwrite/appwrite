<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Tests\Avatars\BadIcon;

use Appwrite\Platform\Action;
use Appwrite\Utopia\Response;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getBadIcon';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/mock/tests/avatars/bad-icon.png')
            ->desc('Non-image bytes served from a PNG favicon URL for avatar favicon tests')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(Response $response): void
    {
        $response
            ->setContentType('text/html')
            ->send('<html><body>error page</body></html>');
    }
}
