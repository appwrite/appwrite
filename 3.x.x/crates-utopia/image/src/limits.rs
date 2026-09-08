//! Imagick-compatible resource limit knobs.

use std::collections::HashMap;
use std::sync::atomic::{AtomicU64, Ordering};

use parking_lot::Mutex;

use crate::error::{ImageError, Result};

static LIMITS: Mutex<Option<HashMap<&'static str, u64>>> = Mutex::new(None);
static AREA_LIMIT: AtomicU64 = AtomicU64::new(u64::MAX);
static MEMORY_LIMIT: AtomicU64 = AtomicU64::new(u64::MAX);

/// Set a resource limit by Imagick type name (`area`, `disk`, `file`, `map`, `memory`, `thread`).
///
/// Unknown types are ignored (PHP parity). `area` and `memory` are enforced on load/process.
pub fn set_resource_limit(limit_type: &str, value: i64) {
    let value = value.max(0) as u64;
    let key = match limit_type {
        "area" => {
            AREA_LIMIT.store(value, Ordering::Relaxed);
            "area"
        }
        "memory" => {
            MEMORY_LIMIT.store(value, Ordering::Relaxed);
            "memory"
        }
        "disk" => "disk",
        "file" => "file",
        "map" => "map",
        "thread" => "thread",
        _ => return,
    };

    let mut guard = LIMITS.lock();
    let map = guard.get_or_insert_with(HashMap::new);
    map.insert(key, value);
}

/// Check width×height against the `area` limit.
pub fn check_area(width: u32, height: u32) -> Result<()> {
    let area = u64::from(width) * u64::from(height);
    let limit = AREA_LIMIT.load(Ordering::Relaxed);
    if area > limit {
        return Err(ImageError::ResourceLimit("area"));
    }
    Ok(())
}

/// Check approximate RGBA buffer bytes against the `memory` limit.
pub fn check_memory(bytes: u64) -> Result<()> {
    let limit = MEMORY_LIMIT.load(Ordering::Relaxed);
    if bytes > limit {
        return Err(ImageError::ResourceLimit("memory"));
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn unknown_type_is_ignored() {
        set_resource_limit("nope", 1);
    }

    #[test]
    fn area_and_memory_checks_pass_with_defaults() {
        assert!(check_area(1920, 1080).is_ok());
        assert!(check_memory(1024 * 1024).is_ok());
    }
}
