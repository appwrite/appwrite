<?php
namespace Appwrite\Extend\SDK\Language;

use Appwrite\SDK\Language\Dart as DartBase;
use Twig\TwigFilter;
use Appwrite\Extend\SDK\RouteParser;
use Exception;
use Utopia\App;

class Dart extends DartBase {

    private App $app;

    

    public function permissionHelperExample(array $param) {
        return '[Permission.read(Role.users()), Permission.update(Role.any())]';
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('dartComment', function ($value) {
                $value = explode("\n", $value);
                foreach ($value as $key => $line) {
                    $value[$key] = "    /// " . wordwrap($value[$key], 75, "\n    /// ");
                }
                return implode("\n", $value);
            }, ['is_safe' => ['html']]),
            new TwigFilter('exampleMethod', function($service, $method) {
                $route = (new RouteParser($this->app))->getRouteForMethod($service, $method['name']);
                if($route == null) {
                    throw new Exception('Unable to find ' . $service .' and ' . $method['name']);
                }

                $params = $route->getParams();
                $filteredParams = $route->getLabel('sdk.example.filters',[]);

                $out = '' . $service . '.' . $method['name'];
                foreach ($params as $key => $param) {
                    // if(!$param['optional']) {
                        $filter = $filteredParams[$key] ?? null;
                        if(!is_null($filter)) {
                            $out .= $key . ':' . call_user_func([$this, $filter], $param);
                        } else {
                            $out .= $key . ':' . parent::getParamExample($param);
                        }
                        $out .= ',';
                    // }
                }
                $out .= ');';
                return $out;

            }, ['is_safe' => ['html']])
        ];
    }

    /**
     * Set the value of app
     */
    public function setApp(App $app): self
    {
        $this->app = $app;

        return $this;
    }
}