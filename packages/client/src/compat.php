<?php

declare(strict_types=1);

// Names from before Client and the streaming interface moved into the
// Utopia\Client namespace; they go away in the next major release. The
// aliases are registered up front because PHP never autoloads a name while
// checking a declared type, so a consumer typed against an old name would
// otherwise reject the moved classes. Each alias is guarded because a
// process can load this file through more than one Composer autoloader (a
// monorepo's root and the package's own).
if (!class_exists(\Utopia\Client::class, false)) {
    class_alias(\Utopia\Client\Client::class, \Utopia\Client::class);
}

if (!interface_exists(\Utopia\Psr18\StreamingClientInterface::class, false)) {
    class_alias(\Utopia\Client\Psr18\StreamingClientInterface::class, \Utopia\Psr18\StreamingClientInterface::class);
}
