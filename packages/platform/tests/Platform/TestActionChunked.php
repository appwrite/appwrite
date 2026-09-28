<?php

namespace Utopia\Tests;

use Utopia\Platform\Action;

class TestActionChunked extends Action
{
    public function __construct()
    {
        $this->httpPath = '/chunked';
        $this->setHttpMethod('GET');
        $this->inject('response');
        $this->callback(function ($response): void {
            $this->action($response);
        });
    }

    public function action($response): void
    {
        foreach (['Hello ', 'World!'] as $key => $word) {
            $response->chunk($word, $key === 1);
        }
    }
}
