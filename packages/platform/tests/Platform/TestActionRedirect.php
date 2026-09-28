<?php

namespace Utopia\Tests;

use Utopia\Platform\Action;

class TestActionRedirect extends Action
{
    public function __construct()
    {
        $this->httpPath = '/redirect';
        $this->setHttpMethod('GET');
        $this->inject('response');
        $this->callback(function ($response): void {
            $this->action($response);
        });
    }

    public function action($response): void
    {
        $response->redirect('/');
    }
}
