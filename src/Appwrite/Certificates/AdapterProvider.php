<?php

namespace Appwrite\Certificates;

class AdapterProvider
{
    private $decisionMaker;

    public function __construct(callable $decisionMaker)
    {
        $this->decisionMaker = $decisionMaker;
    }

    public function get(string $domain): Adapter
    {
        return call_user_func($this->decisionMaker, $domain);
    }

}
