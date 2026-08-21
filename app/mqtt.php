<?php

use Utopia\Mqtt\Broker;

require_once __DIR__ . '/../vendor/autoload.php';

$broker = new Broker(
    host: '0.0.0.0',
    port: 1883,
);

$broker->start();
