//! Host system helpers for Utopia.
//!
//! Rust port of [`utopia-php/system`](https://github.com/utopia-php/system).

mod cpu;

use once_cell::sync::OnceCell;

static CPU_CORES: OnceCell<usize> = OnceCell::new();

/// Returns the number of CPU cores available to the current process.
///
/// On Linux, cgroup v2 (`cpu.max`) and cgroup v1 (`cpu.cfs_quota_us` /
/// `cpu.cfs_period_us`) limits are preferred when present. Otherwise falls back
/// to [`std::thread::available_parallelism`].
///
/// The result is cached after the first call.
pub fn get_cpu_cores() -> usize {
    *CPU_CORES.get_or_init(cpu::detect_cpu_cores)
}

/// Returns the operating system name (for example `Linux`, `Darwin`, or `Windows`).
pub fn get_os() -> &'static str {
    match std::env::consts::OS {
        "linux" => "Linux",
        "macos" => "Darwin",
        "windows" => "Windows",
        other => other,
    }
}

/// Returns the CPU architecture (for example `x86_64` or `aarch64`).
pub fn get_arch() -> &'static str {
    std::env::consts::ARCH
}

/// Returns the system hostname.
pub fn get_hostname() -> String {
    #[cfg(target_os = "linux")]
    {
        if let Ok(hostname) = std::fs::read_to_string("/etc/hostname") {
            let hostname = hostname.trim();
            if !hostname.is_empty() {
                return hostname.to_string();
            }
        }
    }

    if let Ok(hostname) = std::env::var("HOSTNAME") {
        if !hostname.is_empty() {
            return hostname;
        }
    }

    #[cfg(target_os = "windows")]
    {
        if let Ok(hostname) = std::env::var("COMPUTERNAME") {
            if !hostname.is_empty() {
                return hostname;
            }
        }
    }

    String::from("localhost")
}

/// Returns the value of an environment variable, or `default` when unset or empty.
pub fn get_env(key: &str, default: &str) -> String {
    match std::env::var(key) {
        Ok(value) if !value.is_empty() => value,
        _ => default.to_string(),
    }
}
