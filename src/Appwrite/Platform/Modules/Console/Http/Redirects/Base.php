<?php

namespace Appwrite\Platform\Modules\Console\Http\Redirects;

use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

abstract class Base extends Action
{
    use HTTP;

    /**
     * HTTP platform trait doesn't support multiple `aliases`
     * like legacy controllers so we use independent redirects!
     *
     * This helps as a base and a small code logic for maintenance.
     *
     * @return string
     */
    abstract protected function getPath(): string;

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath($this->getPath())
            ->groups(['web'])
            ->label('permission', 'public')
            ->label('scope', 'home')
            ->inject('request')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(Request $request, Response $response): void
    {
        $url = parse_url($request->getURI());
        $target = "/console{$url['path']}";
        $params = $request->getParams();
        if (!empty($params)) {
            $target .= "?" . \http_build_query($params);
        }
        if ($url['fragment'] ?? false) {
            $target .= "#{$url['fragment']}";
        }

        $response->redirect($target);
    }
}
