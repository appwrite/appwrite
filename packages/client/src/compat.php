<?php

declare(strict_types=1);

// Names from before Client and the streaming interface moved into the
// Utopia\Client namespace. They resolve lazily, so nothing loads until code
// uses an old name, and they go away in the next major release.
spl_autoload_register(static function (string $class): void {
    $renamed = match ($class) {
        'Utopia\Client' => \Utopia\Client\Client::class,
        'Utopia\Psr18\StreamingClientInterface' => \Utopia\Client\Psr18\StreamingClientInterface::class,
        default => null,
    };

    if ($renamed !== null) {
        class_alias($renamed, $class);
    }
});
