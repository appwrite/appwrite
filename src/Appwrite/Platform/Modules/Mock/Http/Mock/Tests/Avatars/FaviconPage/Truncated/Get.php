<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Tests\Avatars\FaviconPage\Truncated;

use Appwrite\Platform\Action;
use Appwrite\Utopia\Response;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getFaviconPageTruncated';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/mock/tests/avatars/favicon-page-truncated')
            ->desc('HTML page with a PNG favicon link to truncated bytes for avatar favicon tests')
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
            <link rel="icon" href="/v1/mock/tests/avatars/truncated-icon.png" sizes="64x64">
            </head>
            <body>truncated favicon test page</body>
            </html>
            HTML;

        $response
            ->setContentType('text/html')
            ->send($html);
    }
}
