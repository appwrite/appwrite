<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Tests\Avatars\FaviconPage;

use Appwrite\Platform\Action;
use Appwrite\Utopia\Response;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getFaviconPage';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/mock/tests/avatars/favicon-page')
            ->desc('HTML page with a PNG favicon link for avatar favicon tests')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(Response $response): void
    {
        $html = <<<HTML
            <!DOCTYPE html>
            <html>
            <head>
            <link rel="icon" href="/v1/mock/tests/avatars/bad-icon.png" sizes="64x64">
            </head>
            <body>favicon test page</body>
            </html>
            HTML;

        $response
            ->setContentType('text/html')
            ->send($html);
    }
}
