# utopia-orchestration

Container orchestration for Utopia. Rust port of [utopia-php/orchestration](https://github.com/utopia-php/orchestration).

Talks to Docker via the Engine API (`DockerAPI`) or the Docker CLI (`DockerCLI`, using [`utopia-console`](../utopia-console)). Adapter HTTP is mocked with utopia-test-wiremock. Live Docker tests talk to the local daemon.

## Install

```toml
utopia-orchestration = { path = "../utopia-orchestration" }
```

## Usage

```rust
use utopia_orchestration::prelude::*;

let api = DockerAPI::new(None, None, None);
let orch = Orchestration::new(api);
let _ = orch.pull("ubuntu:latest");
```

`DockerCLI::new(None, None)` runs `docker` through `utopia_console::Console::execute`.

## API Reference

### `Orchestration<A: Adapter>` - PHP `Utopia\Orchestration\Orchestration`

Methods return `Result` instead of throwing (idiomatic Rust). PHP exception messages are preserved.

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` | `fn new(adapter: A) -> Self` | Wrap an adapter. |
| `parse_command_string` | `fn parse_command_string(command: &str) -> Result<Vec<String>, OrchestrationError>` | PHP `parseCommandString` (quoted-argument split). |
| `create_network` | `fn create_network(&self, name, internal) -> Result<bool, OrchestrationError>` | Create a Docker network. |
| `remove_network` | `fn remove_network(&self, name) -> Result<bool, OrchestrationError>` | Remove a network. |
| `list_networks` | `fn list_networks(&self) -> Result<Vec<Network>, OrchestrationError>` | List networks. |
| `network_exists` | `fn network_exists(&self, name) -> Result<bool, OrchestrationError>` | Existence check. |
| `network_connect` | `fn network_connect(&self, container, network) -> Result<bool, OrchestrationError>` | Attach a container. |
| `network_disconnect` | `fn network_disconnect(&self, container, network, force) -> Result<bool, OrchestrationError>` | Detach a container. |
| `pull` | `fn pull(&self, image) -> Result<bool, OrchestrationError>` | Pull an image. |
| `list` | `fn list(&self, filters) -> Result<Vec<Container>, OrchestrationError>` | List containers. |
| `run` | `fn run(&self, image, name, command, …) -> Result<String, OrchestrationError>` | Create and start; returns container id. |
| `execute` | `fn execute(&self, name, command, output, vars, timeout) -> Result<bool, OrchestrationError>` | Exec in a running container. |
| `remove` | `fn remove(&self, name, force) -> Result<bool, OrchestrationError>` | Remove a container. |
| `get_stats` | `fn get_stats(&self, container, filters) -> Result<Vec<Stats>, OrchestrationError>` | CPU/memory/IO stats. |
| `set_namespace` / `set_cpus` / `set_memory` / `set_swap` | fluent | Resource labels and quotas. |

### `Adapter` trait - PHP `Utopia\Orchestration\Adapter`

Implemented by `DockerAPI` and `DockerCLI`. Restart constants: `Adapter::RESTART_NO` / `RESTART_ALWAYS` / `RESTART_ON_FAILURE` / `RESTART_UNLESS_STOPPED` (also `restart::NO` / `ALWAYS` / `ON_FAILURE` / `UNLESS_STOPPED` and `DockerAPI::RESTART_*`).

### `DockerAPI`

PHP `Adapter\DockerAPI`. HTTP to `/var/run/docker.sock` by default.

| Method | Description |
|--------|-------------|
| `new(username, password, email)` | Optional registry auth (PHP constructor). |
| `with_base_url(url)` | Rust test helper: TCP HTTP instead of the unix socket (WireMock). |

### `DockerCLI`

PHP `Adapter\DockerCLI`. Builds `docker` argv via `utopia_console::Command`. `new(username, password)` returns `Result` because `docker login` can fail.

### `Container` - PHP `Utopia\Orchestration\Container`

| Method | Description |
|--------|-------------|
| `new` | `name`, `id`, `status`, `labels`. |
| `get_name` / `get_id` / `get_status` / `get_labels` | Accessors. |
| `set_name` / `set_id` / `set_status` / `set_labels` | Fluent setters. |

### `Network` - PHP `Utopia\Orchestration\Network`

| Method | Description |
|--------|-------------|
| `new` | `name`, `id`, `driver`, `scope`. |
| `get_name` / `get_id` / `get_driver` / `get_scope` | Accessors. |
| `set_name` / `set_id` / `set_driver` / `set_scope` | Fluent setters. |

### `Stats` - PHP `Utopia\Orchestration\Container\Stats`

| Method | Description |
|--------|-------------|
| `new` | `container_id`, `container_name`, `cpu_usage`, `memory_usage`, `disk_io`, `memory_io`, `network_io`. |
| `get_container_id` / `get_container_name` | Identifiers. |
| `get_cpu_usage` / `get_memory_usage` | Usage fractions / percents as PHP. |
| `get_disk_io` / `get_memory_io` / `get_network_io` | `in` / `out` maps. |

### Errors

| Variant | PHP |
|---------|-----|
| `Orchestration(String)` | `Utopia\Orchestration\Exception\Orchestration` |
| `Timeout(String)` | `Utopia\Orchestration\Exception\Timeout` (`Command timed out`) |

## Tests

```bash
cargo test --manifest-path crates-utopia/orchestration/Cargo.toml
```

Ports `testParseCLICommand` and adapter HTTP behavior. Live Docker from `tests/Orchestration/Base.php` always talks to the local daemon.

## Benchmarks

```bash
cargo bench --manifest-path crates-utopia/orchestration/Cargo.toml
```

PHP twin: [`benchmarks/orchestration/`](../../benchmarks/orchestration/).

## Code quality

- **rustfmt** - `cargo fmt --manifest-path crates-utopia/orchestration/Cargo.toml`
- **Clippy** - `cargo clippy --manifest-path crates-utopia/orchestration/Cargo.toml --all-targets -- -D warnings`
- Inherits workspace lint policy (`[lints] workspace = true`).

## License

MIT - see [LICENSE](LICENSE).
