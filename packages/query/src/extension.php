<?php

// With the utopia extension's query library on (_APP_EXTENSION_LIBRARIES=query), Query's methods
// are the extension's NativeQuery, and Query is declared over it here, before the autoloader
// looks for Query.php. The second check is for a process that loads this file through more than
// one Composer autoloader.
if (\class_exists(\Utopia\Query\NativeQuery::class, false) && ! \class_exists(\Utopia\Query\Query::class, false)) {
    require __DIR__ . '/../extension/Query.php';
}
