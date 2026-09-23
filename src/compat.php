<?php

declare(strict_types=1);

// Names from before Client and the streaming interface moved into the
// Utopia\Client namespace; they go away in the next major release. The
// aliases are registered up front because PHP never autoloads a name while
// checking a declared type, so a consumer typed against an old name would
// otherwise reject the moved classes.
class_alias(\Utopia\Client\Client::class, \Utopia\Client::class);
class_alias(\Utopia\Client\Psr18\StreamingClientInterface::class, \Utopia\Psr18\StreamingClientInterface::class);
