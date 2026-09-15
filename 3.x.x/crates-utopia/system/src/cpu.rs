use std::fs;
use std::path::Path;

pub(crate) fn detect_cpu_cores() -> usize {
    #[cfg(target_os = "linux")]
    {
        if let Some(limit) = linux_cgroup_cpu_limit() {
            return cores_from_limit(limit);
        }
    }

    std::thread::available_parallelism()
        .map(|count| count.get())
        .unwrap_or(1)
        .max(1)
}

#[cfg(target_os = "linux")]
fn linux_cgroup_cpu_limit() -> Option<f64> {
    cgroup_v2_cpu_limit().or_else(cgroup_v1_cpu_limit)
}

#[cfg(target_os = "linux")]
fn cgroup_v2_cpu_limit() -> Option<f64> {
    let cgroup_dir = cgroup_v2_dir()?;
    parse_cpu_max(&fs::read_to_string(cgroup_dir.join("cpu.max")).ok()?)
}

#[cfg(target_os = "linux")]
fn cgroup_v1_cpu_limit() -> Option<f64> {
    let cgroup_dir = cgroup_v1_cpu_dir()?;
    let quota = fs::read_to_string(cgroup_dir.join("cpu.cfs_quota_us"))
        .ok()?
        .trim()
        .parse::<i64>()
        .ok()?;
    let period = fs::read_to_string(cgroup_dir.join("cpu.cfs_period_us"))
        .ok()?
        .trim()
        .parse::<u64>()
        .ok()?;

    if quota <= 0 || period == 0 {
        return None;
    }

    Some(quota as f64 / period as f64)
}

#[cfg(target_os = "linux")]
fn cgroup_v2_dir() -> Option<std::path::PathBuf> {
    let contents = fs::read_to_string("/proc/self/cgroup").ok()?;
    for line in contents.lines() {
        let mut parts = line.splitn(3, ':');
        let hierarchy = parts.next()?;
        let controllers = parts.next()?;
        let path = parts.next()?;

        if hierarchy == "0" && controllers.is_empty() {
            let base = Path::new("/sys/fs/cgroup");
            return Some(if path == "/" {
                base.to_path_buf()
            } else {
                base.join(path.trim_start_matches('/'))
            });
        }
    }

    let fallback = Path::new("/sys/fs/cgroup/cpu.max");
    if fallback.is_file() {
        return Some(Path::new("/sys/fs/cgroup").to_path_buf());
    }

    None
}

#[cfg(target_os = "linux")]
fn cgroup_v1_cpu_dir() -> Option<std::path::PathBuf> {
    let contents = fs::read_to_string("/proc/self/cgroup").ok()?;
    for line in contents.lines() {
        let mut parts = line.splitn(3, ':');
        let _hierarchy = parts.next()?;
        let controllers = parts.next()?;
        let path = parts.next()?;

        if controllers.split(',').any(|controller| controller == "cpu") {
            return Some(Path::new("/sys/fs/cgroup/cpu").join(path.trim_start_matches('/')));
        }
    }

    None
}

#[cfg(target_os = "linux")]
fn parse_cpu_max(contents: &str) -> Option<f64> {
    let mut parts = contents.split_whitespace();
    let quota = parts.next()?;
    let period = parts.next()?.parse::<f64>().ok()?;

    if period <= 0.0 || quota == "max" {
        return None;
    }

    let quota = quota.parse::<f64>().ok()?;
    if quota <= 0.0 {
        return None;
    }

    Some(quota / period)
}

fn cores_from_limit(limit: f64) -> usize {
    if limit <= 0.0 {
        return 1;
    }

    limit.ceil().max(1.0) as usize
}
