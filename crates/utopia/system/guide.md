The system library reads environment variables and facts about the machine a process runs on: the OS, the CPU architecture, the CPUs and memory it may use, and free disk space. Services use it to read configuration and to size themselves to the host or container. This guide covers the common tasks.

## Read an environment variable with a default

`env` returns the variable, or `None` when it is missing. `env_or` falls back to a default, which suits settings with a sane local value.

```
use utopia_system::{env, env_or};

let region = env_or("APP_GUIDE_REGION", "default");
assert_eq!(region, "default");
assert_eq!(env("APP_GUIDE_REGION"), None);

let path = env("PATH");
assert!(path.is_some());
println!("PATH has {} entries", path.unwrap_or_default().split(':').count());
```

```php
use Utopia\System\System;

echo System::getEnv('APP_GUIDE_REGION', 'default'), "\n";  // default
var_dump(System::getEnv('APP_GUIDE_REGION'));               // NULL
echo count(explode(':', System::getEnv('PATH'))), "\n";     // e.g. 6
```

## Treat empty and "0" values as unset

`env` treats an empty value or `"0"` as no value, so the default applies. Use `env_raw` when you need the variable exactly as it is set. `env_value` applies the same rule to a value you already have.

```
use utopia_system::{env_raw, env_value};

assert_eq!(env_value(Some(String::new())), None);
assert_eq!(env_value(Some("0".to_owned())), None);
assert_eq!(env_value(Some("eu".to_owned())), Some("eu".to_owned()));

assert_eq!(env_raw("APP_GUIDE_UNSET"), None);
```

```php
use Utopia\System\System;

putenv('APP_GUIDE_EMPTY=');
putenv('APP_GUIDE_ZERO=0');
putenv('APP_GUIDE_REGION=eu');

var_dump(System::getEnv('APP_GUIDE_EMPTY'));  // NULL
var_dump(System::getEnv('APP_GUIDE_ZERO'));   // NULL
echo System::getEnv('APP_GUIDE_REGION'), "\n"; // eu

var_dump(getenv('APP_GUIDE_UNSET'));          // bool(false)
```

## Identify the host

Read the OS name, the machine name and the hostname, for example to tag logs and metrics.

```
use utopia_system::System;

let system = System::new();

let os = system.os();
assert!(["Linux", "Darwin", "Windows"].contains(&os.as_str()));
assert!(!system.arch().is_empty());
assert!(!system.hostname().is_empty());

println!("{} on {} ({})", system.hostname(), os, system.arch());
```

```php
use Utopia\System\System;

echo System::getOS(), "\n";       // e.g. Linux
echo System::getArch(), "\n";     // e.g. x86_64 or aarch64
echo System::getHostname(), "\n"; // e.g. 3f2a9c1b7d4e
```

## Check the CPU architecture

`arch_enum` maps the machine name to a family, such as `x86` for `x86_64` and `arm64` for `aarch64`. Use it, or the `is_*` checks, to pick a binary or an image built for the host.

```
use utopia_system::{Arch, System};

let system = System::new();

let arch = system.arch_enum()?;
assert!(Arch::ALL.contains(&arch));
assert!(system.is_arch(arch.name())?);
assert!(system.is_x86() || system.is_arm64() || system.is_ppc() || system.is_armv7() || system.is_armv8());

let image = if system.is_arm64() { "runtime-arm64" } else { "runtime-amd64" };
println!("{} host, pulling {image}", arch.name());
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\System\System;

$arch = System::getArchEnum();
echo $arch, "\n";                           // e.g. x86 or arm64
var_dump(System::isArch($arch));            // bool(true)

$image = System::isArm64() ? 'runtime-arm64' : 'runtime-amd64';
echo $image, "\n";                          // e.g. runtime-amd64
```

## Size a worker pool from the CPU count

`cpu` returns the CPUs this process may use. On Linux it honours cgroup quotas and cpusets, so a container limited to two CPUs gets 2 even on a larger host. The value can be fractional, so round it and keep at least one worker.

```
use utopia_system::System;

let cpus = System::new().cpu()?;
assert!(cpus > 0.0);

let workers = (cpus.ceil() as usize * 2).max(1);
assert!(workers >= 2);
println!("{cpus} CPUs, starting {workers} workers");
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\System\System;

$cpus = System::getCPU();
$workers = max(1, (int) ceil($cpus) * 2);

echo $cpus, "\n";    // e.g. 8
echo $workers, "\n"; // e.g. 16
```

## Measure memory

All memory values are in MiB. `memory` is what this process may use: on Linux it is the cgroup limit when there is one, capped at the host's RAM. `memory_total` is the host's RAM and `memory_free` its free RAM.

```
use utopia_system::System;

let system = System::new();

let total = system.memory_total()?;
let usable = system.memory()?;
let free = system.memory_free()?;

assert!(total > 0);
assert!(usable > 0 && usable <= total);
assert!(free >= 0 && free <= total);
println!("{usable} of {total} MiB usable, {free} MiB free");
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\System\System;

echo System::getMemoryTotal(), "\n"; // e.g. 7941
echo System::getMemory(), "\n";      // e.g. 7941, or the container limit
echo System::getMemoryFree(), "\n";  // e.g. 2310
```

## Check free disk space

Pass a directory to measure the file system that holds it. Values are in MiB, so you can refuse an upload or a build before the disk fills up.

```
use utopia_system::System;

let system = System::new();

let total = system.disk_total("/")?;
let free = system.disk_free("/")?;
assert!(total > 0);
assert!(free >= 0 && free <= total);

let needed = 100;
println!("{free} of {total} MiB free, room for a {needed} MiB build: {}", free >= needed);
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\System\System;

$total = System::getDiskTotal('/');
$free = System::getDiskFree('/');

echo $total, "\n";                   // e.g. 59819
echo $free, "\n";                    // e.g. 41022
var_dump($free >= 100);              // e.g. bool(true)
```

## Handle unsupported values and platforms

Some calls fail instead of guessing. An unknown architecture name is an error, and some measurements exist only on Linux, such as `memory_available`.

```
use utopia_system::{Error, System};

let system = System::new();

match system.is_arch("sparc") {
    Err(Error::Exception(message)) => assert_eq!(message, "'sparc' not found."),
    other => panic!("expected an error, got {other:?}"),
}

match system.memory_available() {
    Ok(mib) => println!("{mib} MiB available"),
    Err(error) => {
        assert_eq!(error.php_class(), "Exception");
        println!("not measured here: {error}");
    }
}
```

```php
use Utopia\System\System;

try {
    System::isArch('sparc');
} catch (\Exception $e) {
    echo $e->getMessage(), "\n"; // 'sparc' not found.
}

try {
    echo System::getMemoryAvailable(), " MiB available\n"; // e.g. 5120 MiB available
} catch (\Exception $e) {
    echo 'not measured here: ', $e->getMessage(), "\n";
}
```
