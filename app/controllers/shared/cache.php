<?php

use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

App::shutdown(function (Database $dbForProject, string $cacheKey, string $cachePath) {

    if (!empty($cacheKey) && !empty($cachePath)) {
        $cacheLog = $dbForProject->getDocument('cache', $cacheKey);
        if ($cacheLog->isEmpty()) {
                Authorization::skip(fn () => $dbForProject->createDocument('cache', new Document([
                    '$id' => $cacheKey,
                    'accessedAt' => time(),
                    'path' => $cachePath
                ])));
        } else {
            $cacheLog->setAttribute('accessedAt', time());
            Authorization::skip(fn () => $dbForProject->updateDocument('cache', $cacheLog->getId(), $cacheLog));
        }
    }
}, ['dbForProject', 'cacheKey', 'cachePath'], 'cache');
