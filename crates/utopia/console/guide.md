Command-line programs log progress, ask questions, and run other programs. This crate writes coloured log lines, builds command lines that cannot be injected into, and runs them with an input, a timeout and live output.

## Log lines

Plain, green and blue lines go to standard output, red and yellow ones to standard error. Each call returns the bytes it wrote.

```
use utopia_console::Console;

let mut console = Console::new(Vec::new(), Vec::new());
console.log("starting")?;
console.info("12 files to upload")?;
assert_eq!(console.out(), b"starting\n\x1b[34m12 files to upload\x1b[0m\n");

console.warning("disk almost full")?;
assert_eq!(console.err(), b"\x1b[1;33mdisk almost full\x1b[0m\n");
# Ok::<(), std::io::Error>(())
```

```php
use Utopia\Console\Console;

Console::log('starting');            // "starting\n" on STDOUT
Console::info('12 files to upload'); // blue, on STDOUT
// Console::warning('disk almost full') writes it in yellow on STDERR
```

## Build a command safely

Each flag, option and argument is one word, checked as it is added; a shell string quotes every word, so a value can never run as code.

```
use utopia_console::Command;

let command = Command::new("php")?.option("-r", "echo 'hello'; rm -rf /")?;
assert_eq!(command.to_shell()?, "'php' '-r' 'echo '\\''hello'\\''; rm -rf /'");

let error = Command::new("git")?.flag("verbose").unwrap_err();
assert_eq!(error.to_string(), "Invalid command flag: verbose");
# Ok::<(), utopia_console::Error>(())
```

```php
use Utopia\Console\Command;

$command = (new Command('php'))->option('-r', "echo 'hello'; rm -rf /");
echo $command->toString(), "\n"; // 'php' '-r' 'echo '\''hello'\''; rm -rf /'

try {
    (new Command('git'))->flag('verbose');
} catch (\InvalidArgumentException $e) {
    echo $e->getMessage(), "\n"; // Invalid command flag: verbose
}
```

## Run a command

`execute` passes the input, collects both outputs, reports progress, and kills a command that outlives its timeout (status 1).

```
# utopia_async::run(async {
use std::time::Duration;
use utopia_console::{execute, Command};

let pipeline = Command::pipe(vec![
    Command::new("printf")?.argument("alpha\nbeta\n")?,
    Command::new("grep")?.argument("beta")?,
])?;
let mut seen = Vec::new();
let mut progress = |chunk: &[u8]| seen.extend_from_slice(chunk);
let output = execute(&pipeline, b"", Some(Duration::from_secs(10)), Some(&mut progress)).await?;
assert_eq!(output.status, 0);
assert_eq!(output.stdout, b"beta\n");
assert_eq!(seen, b"beta\n");

let slow = execute("sleep 5", b"", Some(Duration::from_secs(1)), None).await?;
assert_eq!(slow.status, 1);
# Ok::<(), utopia_console::Error>(())
# }).unwrap();
```

```php
use Utopia\Console\Command;
use Utopia\Console\Console;

$pipeline = Command::pipe((new Command('printf'))->argument("alpha\nbeta\n"), (new Command('grep'))->argument('beta'));
$stdout = $stderr = '';
$seen = '';
$status = Console::execute($pipeline, '', $stdout, $stderr, 10, function (string $chunk) use (&$seen) { $seen .= $chunk; });
echo $status, ' ', json_encode($stdout), ' ', json_encode($seen), "\n"; // 0 "beta\n" "beta\n"

echo Console::execute('sleep 5', '', $stdout, $stderr, 1), "\n"; // 1
```
