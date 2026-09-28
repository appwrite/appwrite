<?php

namespace Utopia\Tests;

use Utopia\Platform\Action;

class TestActionRoot extends Action
{
    public function __construct()
    {
        $this->httpPath = '/';
        $this->groups(['test']);
        $this->setHttpMethod('GET');
        $this->inject('response');
        $this->callback(function ($response): void {
            $this->action($response);
        });
    }

    public function action($response): void
    {
        $response->send('Hello World!');
    }
}
