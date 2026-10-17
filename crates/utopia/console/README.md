# Utopia Console

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/console`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/console) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Console helpers: coloured log lines, prompts, process titles, shell-safe commands, and running commands with input, output, progress and a timeout.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-console = { git = "https://github.com/utopia-rs/console" }
```

Command-line programs log progress, ask questions, and run other programs. This crate writes coloured log lines, builds command lines that cannot be injected into, and runs them with an input, a timeout and live output.

## Log lines

Plain, green and blue lines go to standard output, red and yellow ones to standard error. Each call returns the bytes it wrote.

```rust
use utopia_console::Console;

let mut console = Console::new(Vec::new(), Vec::new());
console.log("starting")?;
console.info("12 files to upload")?;
assert_eq!(console.out(), b"starting\n\x1b[34m12 files to upload\x1b[0m\n");

console.warning("disk almost full")?;
assert_eq!(console.err(), b"\x1b[1;33mdisk almost full\x1b[0m\n");
```

## Build a command safely

Each flag, option and argument is one word, checked as it is added; a shell string quotes every word, so a value can never run as code.

```rust
use utopia_console::Command;

let command = Command::new("php")?.option("-r", "echo 'hello'; rm -rf /")?;
assert_eq!(command.to_shell()?, "'php' '-r' 'echo '\\''hello'\\''; rm -rf /'");

let error = Command::new("git")?.flag("verbose").unwrap_err();
assert_eq!(error.to_string(), "Invalid command flag: verbose");
```

## Run a command

`execute` passes the input, collects both outputs, reports progress, and kills a command that outlives its timeout (status 1).

```rust
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
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
