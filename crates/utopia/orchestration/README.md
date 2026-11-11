# Utopia Orchestration

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/orchestration`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/orchestration) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Container orchestration on Docker, through the Docker CLI or the Engine API on its Unix socket: images, containers, executions, networks and usage statistics.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-orchestration = { git = "https://github.com/utopia-rs/orchestration" }
```

# Getting started

`utopia-orchestration` runs containers on Docker, through the `docker` command-line client (`DockerCli`) or the Engine API on Docker's Unix socket (`DockerApi`). Both offer the same operations behind the `Adapter` trait, and `Orchestration` fronts either one with the settings every container gets.

## Run a container with limits and labels

Set the label namespace and limits once, then run containers with `RunOptions`; every container gets `<namespace>-type` and `<namespace>-created` labels besides yours.

```rust
use utopia_orchestration::{DockerCli, Orchestration, Restart, RunOptions};

let mut orchestration = Orchestration::new(DockerCli::new());
orchestration.set_namespace("executor").set_cpus(0.5).set_memory(256);

let options = RunOptions {
    command: vec!["sh".into(), "-c".into(), "tail -f /dev/null".into()],
    workdir: "/usr/local/src".into(),
    vars: vec![("APP_ENV".into(), "production".into())],
    labels: vec![("function".into(), "thumbnails".into())],
    restart: Restart::UnlessStopped,
    ..RunOptions::default()
};
let id = orchestration.run("alpine:3.20", "thumbnails-1", &options).await?;
println!("started {id}");
```

## Execute a command and read its output

`execute` sets the output whether or not the command succeeds, and fails with `Error::Timeout` (API) or `Error::Orchestration` when it runs out of time or exits with an error.

```rust
use utopia_orchestration::{DockerApi, Error, Orchestration};

let orchestration = Orchestration::new(DockerApi::new());
let mut output = Vec::new();
match orchestration.execute("thumbnails-1", &["sh".into(), "-c".into(), "echo -n ready".into()], &mut output, &[], 10).await {
    Ok(_) => assert_eq!(output, b"ready"),
    Err(e) => eprintln!("{e}: {}", String::from_utf8_lossy(&output)),
}
```

## Split a command line

`parse_command` splits a command at spaces, keeping a `'`-quoted script whole so it can follow `sh -c`.

```rust
use utopia_orchestration::parse_command;

let args = parse_command(b"sh -c 'cp /tmp/code.tar.gz . && tar -zxf code.tar.gz'")?;
assert_eq!(args.len(), 3);
assert_eq!(args[2], b"'cp /tmp/code.tar.gz . && tar -zxf code.tar.gz'");
```

## Find containers and their usage

List containers by Docker filter, and read the usage of the ones they select.

```rust
use utopia_orchestration::{DockerCli, Orchestration};

let orchestration = Orchestration::new(DockerCli::new());
let filters = [("label".to_owned(), "function=thumbnails".to_owned())];
for container in orchestration.list(&filters).await? {
    println!("{} {}", container.name, container.status);
}
for stats in orchestration.stats(None, &filters).await? {
    println!("{}: {:.1}% CPU, {:.1}% memory", stats.container_name, stats.cpu_usage * 100.0, stats.memory_usage);
}
```

## Contributing

Development happens in [appwrite/appwrite](https://github.com/appwrite/appwrite), where this crate lives at [`crates/utopia/orchestration`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/orchestration). Run these commands from a checkout of it. The [contributing guide](https://utopia-docs.appwrite.network/contributing) covers each step.

### Test

```bash
cargo test -p utopia-orchestration
cargo clippy -p utopia-orchestration --all-targets -- -D warnings
cargo fmt --all --check
```

### Compare with PHP

Replay the results PHP recorded for every case against Rust, without PHP:

```bash
COMPAT_SERVICES=1 COMPAT_LIB=orchestration cargo test -p compat
```

Without `COMPAT_SERVICES=1` the cases that need a service are skipped.

Run every case on both runtimes, fuzz both with generated inputs, then run the whole conversion check. These need the dev stack (`docker compose up -d`):

```bash
tests/compat/orchestration/services.sh up
bin/compat run orchestration
bin/compat fuzz orchestration
bin/compat check orchestration
```

### Benchmark

```bash
bin/compat bench orchestration
```

This times every benchmark on PHP and on a release build of Rust, both in containers on the dev stack. It fails if Rust is slower. Add `--write` to record the run in [`tests/compat/orchestration/bench.json`](https://github.com/appwrite/appwrite/blob/main/tests/compat/orchestration/bench.json). Run it on a quiet machine, one library at a time.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
