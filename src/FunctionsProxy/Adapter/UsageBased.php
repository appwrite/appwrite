<?php

namespace FunctionsProxy\Adapter;

use FunctionsProxy\Adapter;

class UsageBased extends Adapter
{
    public function getNextExecutor(): array
    {
        $executors = $this->getExecutors();

        /*
        appwrite-functions-proxy  | array(3) {
        appwrite-functions-proxy  |   ["id"]=>
        appwrite-functions-proxy  |   string(4) "exc2"
        appwrite-functions-proxy  |   ["hostname"]=>
        appwrite-functions-proxy  |   string(18) "appwrite-executor2"
        appwrite-functions-proxy  |   ["state"]=>
        appwrite-functions-proxy  |   array(2) {
        appwrite-functions-proxy  |     ["status"]=>
        appwrite-functions-proxy  |     string(6) "online"
        appwrite-functions-proxy  |     ["stats"]=>
        appwrite-functions-proxy  |     array(3) {
        appwrite-functions-proxy  |       ["status"]=>
        appwrite-functions-proxy  |       string(4) "pass"
        appwrite-functions-proxy  |       ["hostUsage"]=>
        appwrite-functions-proxy  |       float(0.348125)
        appwrite-functions-proxy  |       ["functionsUsage"]=>
        appwrite-functions-proxy  |       array(0) {
        appwrite-functions-proxy  |       }
        appwrite-functions-proxy  |     }
        appwrite-functions-proxy  |   }
        appwrite-functions-proxy  | }
        */

        // TODO: @Meldiron Proper functionality
        // TODO: @Meldiron Use this adapter and test it

        $executor = $executors[\array_rand($executors)] ?? null;
        return $executor ?? null;
    }
}
