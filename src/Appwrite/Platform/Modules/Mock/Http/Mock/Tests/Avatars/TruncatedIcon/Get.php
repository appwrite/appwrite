<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Tests\Avatars\TruncatedIcon;

use Appwrite\Platform\Action;
use Appwrite\Utopia\Response;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getTruncatedIcon';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/mock/tests/avatars/truncated-icon.png')
            ->desc('Truncated PNG bytes for avatar favicon decode failure tests')
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
            ->setContentType('image/png')
            ->file("\x89PNG\r\n\x1A\n\x00\x00\x00\rIHDR");
    }
}
