<?php

use Utopia\Config\Config;

$runtimes = Config::getParam('runtimes');

$mappedRuntimes = \array_reduce($runtimes, function ($acc, $runtime) {
    $acc[strtoupper($runtime['key'])][] = $runtime['key'] . '-' . $runtime['version'];
    return $acc;
}, []);

return $mappedRuntimes;
