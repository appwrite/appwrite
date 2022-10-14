<?php

namespace FunctionsProxy\Adapter;

use FunctionsProxy\Adapter;

class UsageBased extends Adapter
{
    public function getNextExecutor($contaierId): array
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
        appwrite-functions-proxy  |     ["health"]=>
        appwrite-functions-proxy  |     array(3) {
        appwrite-functions-proxy  |       ["status"]=>
        appwrite-functions-proxy  |       string(4) "pass"
        appwrite-functions-proxy  |       ["hostUsage"]=>
        appwrite-functions-proxy  |       float(0.348125)
        appwrite-functions-proxy  |       ["functionsUsage"]=>
        appwrite-functions-proxy  |       array(0) {
        // container1: 0.5
        // container1: 0.2
        appwrite-functions-proxy  |       }
        appwrite-functions-proxy  |     }
        appwrite-functions-proxy  |   }
        appwrite-functions-proxy  | }
        */

        // low, mid, high

        // 10% on host, 90% on chontainer
        // 90% on host, 10% on chontainer

        // TODO: @Meldiron Proper functionality
        // TODO: @Meldiron Use this adapter and test it

        /*
        // If 2+ running, do this to sort:

        $hostUsage = 20;
        $containerUsage = 40;

        $hostWeight = 0.33;
        $contiainerWeight = 0.66;

        $totalUsage = $hostUsage * $hostWeight +  $containerUsage * $contiainerWeight;

        // If all existing are above 80%, we should start on new executor ideally
        if(host < 0.8 & container < 0.8) {

        }
        */

        $executor = $executors[\array_rand($executors)] ?? null;
        return $executor ?? null;
    }
}
