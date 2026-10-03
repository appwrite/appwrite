# utopia-system

CPU, OS, and runtime helpers for Utopia. Rust port of [utopia-php/system](https://github.com/utopia-php/system).

## Install

```toml
utopia-system = { path = "../utopia-system" } # workspace
```

## Usage

```rust
use utopia_system::{get_arch, get_cpu_cores, get_env, get_hostname, get_os};

let cores = get_cpu_cores();
let os = get_os();
let arch = get_arch();
let hostname = get_hostname();
let home = get_env("HOME", "/tmp");

println!("{hostname}: {os}/{arch} with {cores} CPU cores, HOME={home}");
```

## API Reference

All items are free functions at the crate root. There are no public types or error enums - helpers degrade to safe defaults.

| Function | Signature | Description |
|----------|-----------|-------------|
| `get_cpu_cores` | `fn get_cpu_cores() -> usize` | Cores available to this process. Linux: prefer cgroup v2 `cpu.max`, else cgroup v1 `cpu.cfs_quota_us` / `cpu.cfs_period_us`; else `std::thread::available_parallelism`, minimum **1**. Fractional limits use `ceil`. **Cached** after the first call (`OnceCell`) for the process lifetime. |
| `get_os` | `fn get_os() -> &'static str` | OS name with PHP-ish labels: `linux` → `"Linux"`, `macos` → `"Darwin"`, `windows` → `"Windows"`; other `std::env::consts::OS` values returned as-is. |
| `get_arch` | `fn get_arch() -> &'static str` | `std::env::consts::ARCH` (e.g. `x86_64`, `aarch64`). |
| `get_hostname` | `fn get_hostname() -> String` | Linux: non-empty trimmed `/etc/hostname`, else `HOSTNAME` env, else `"localhost"`. Windows also checks `COMPUTERNAME`. Empty env values are skipped. |
| `get_env` | `fn get_env(key: &str, default: &str) -> String` | Env var value, or `default` if unset **or empty** (empty ≡ missing; PHP-friendly). |

**Notes**

- `get_cpu_cores` never returns below 1; unlimited cgroup (`max` / non-positive quota) falls through to available parallelism.
- The CPU-core cache means later cgroup/CPU changes in-process are not observed.

## Tests

```bash
cargo test -p utopia-system
```

## Benchmarks

```bash
cargo bench -p utopia-system
```

## Code quality

This crate inherits workspace linting:

- **rustfmt** - `cargo fmt -p <crate>` (config: repo-root `rustfmt.toml`)
- **Clippy + rustc lints** - `cargo clippy -p <crate> --all-targets -- -D warnings` (config: `clippy.toml`, `[workspace.lints]`)
- **Docs** - `cargo doc -p <crate> --no-deps` (`RUSTDOCFLAGS=-Dwarnings` in CI)
- **Supply chain** - `cargo deny check` (config: `deny.toml`)
