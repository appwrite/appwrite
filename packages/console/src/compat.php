<?php

declare(strict_types=1);

// Names from before Console and Command moved into the Utopia\Console
// namespace; they go away in the next major release. The aliases are
// registered up front because PHP never autoloads a name while checking a
// declared type, so a consumer typed against an old name would otherwise
// reject the moved classes. Each alias is guarded because a process can load
// this file through more than one Composer autoloader (a monorepo's root and
// the package's own).
if (!class_exists(\Utopia\Console::class, false)) {
    class_alias(\Utopia\Console\Console::class, \Utopia\Console::class);
}

if (!class_exists(\Utopia\Command::class, false)) {
    class_alias(\Utopia\Console\Command::class, \Utopia\Command::class);
}
