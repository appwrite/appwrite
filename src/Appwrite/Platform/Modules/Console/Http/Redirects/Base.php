<?php

namespace Appwrite\Platform\Modules\Console\Http\Redirects;

use Appwrite\Extend\Exception;
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

    /**
     * Console route to land on, the request path unless the console names it differently.
     */
    protected function getTarget(string $path, array $params): string
    {
        return $path;
    }

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
            ->inject('platform')
            ->callback($this->action(...));
    }

    public function action(Request $request, Response $response, array $platform): void
    {
        $consoleUrl = $platform['consoleUrl'] ?? '';

        // On the console's own host the proxy serves these paths, so redirecting would loop
        if ($request->getHostname() === \parse_url($consoleUrl, PHP_URL_HOST)) {
            throw new Exception(Exception::GENERAL_ROUTE_NOT_FOUND);
        }

        $url = parse_url($request->getURI());
        $params = $request->getParams();
        $target = $consoleUrl . $this->getTarget($url['path'] ?? '', $params);
        if (!empty($params)) {
            $target .= "?" . \http_build_query($params);
        }
        if ($url['fragment'] ?? false) {
            $target .= "#{$url['fragment']}";
        }

        $response->redirect($target);
    }
}
