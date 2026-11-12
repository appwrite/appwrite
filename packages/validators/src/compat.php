<?php

declare(strict_types=1);

// Names from before the base class and the PHPStan extension moved into the
// Utopia\Validator namespace; they go away in the next major release.
//
// The base class is aliased up front because PHP never autoloads a name while
// checking a declared type, so a consumer typed against Utopia\Validator would
// otherwise reject the moved class. The alias is guarded because a process can
// load this file through more than one Composer autoloader (a monorepo's root
// and the package's own).
if (!class_exists(\Utopia\Validator::class, false)) {
    class_alias(\Utopia\Validator\Validator::class, \Utopia\Validator::class);
}

// The PHPStan extension implements PHPStan's interfaces, which a production
// install does not have, so it is aliased only when PHPStan asks for the old
// name. PHPStan resolves its configured classes by name, which autoloads.
spl_autoload_register(static function (string $class): void {
    if ($class === 'Utopia\PHPStan\DisallowAssertEqualsExtension') {
        class_alias(\Utopia\Validator\PHPStan\DisallowAssertEqualsExtension::class, $class);
    }
});
