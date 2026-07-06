<?php

namespace Appwrite\Platform\Modules\Console\Http\Init;

use Appwrite\Utopia\Request;
use Psr\Http\Message\ServerRequestInterface;
use Appwrite\Utopia\Response;
use Utopia\Platform\Action;

class Web extends Action
{
    public static function getName(): string
    {
        return 'consoleWeb';
    }

    public function __construct()
    {
        $this
            ->setType(Action::TYPE_INIT)
            ->groups(['web'])
            ->inject('request')
            ->inject('response')
            ->callback(function (ServerRequestInterface $request, Response $response) {
                $response
                    ->addHeader('X-Frame-Options', 'SAMEORIGIN') // Avoid console and homepage from showing in iframes
                    ->addHeader('X-XSS-Protection', '1; mode=block; report=/v1/xss?url=' . \urlencode($request->getRequestTarget()))
                    ->addHeader('X-UA-Compatible', 'IE=Edge') // Deny IE browsers from going into quirks mode
                ;
            });
    }
}
