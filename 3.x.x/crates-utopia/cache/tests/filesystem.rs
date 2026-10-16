mod common;

use std::fs;
use tempfile::TempDir;
use utopia_cache::adapter::Filesystem;
use utopia_cache::{Adapter, Cache, LoadResult};

fn folds_filename_case(path: &str) -> bool {
    let probe = format!("{path}/case-probe");
    let _ = fs::write(&probe, b"");
    let folds = fs::metadata(format!("{path}/CASE-PROBE")).is_ok();
    let _ = fs::remove_file(&probe);
    folds
}

#[test]
fn filesystem_base_suite() {
    let dir = TempDir::new().unwrap();
    let mut cache = Cache::new(Filesystem::new(dir.path().to_str().unwrap()));
    common::assert_base_suite(&mut cache);
    common::assert_touch(&cache);
    common::assert_case_insensitivity(&cache);
    if !folds_filename_case(dir.path().to_str().unwrap()) {
        common::assert_case_sensitivity(&mut cache);
    }
    assert!(cache.ping());
    // Recreate files after flush deletes the directory in PHP.
    let _ = fs::create_dir_all(dir.path());
    cache.save("x", "x", "x").unwrap();
    cache.save("y", "y", "y").unwrap();
    assert!(cache.flush().unwrap());
}

#[test]
fn filesystem_get_size() {
    let dir = TempDir::new().unwrap();
    let cache = Cache::new(Filesystem::new(dir.path().to_str().unwrap()));
    cache.save("test", "test", "").unwrap();
    assert_eq!(cache.get_size().unwrap(), 4);
}

#[test]
fn filesystem_streaming_load_returns_string() {
    let dir = TempDir::new().unwrap();
    let cache = Cache::new(Filesystem::with_streaming(
        dir.path().to_str().unwrap(),
        true,
    ));
    cache.save("stream-test", "stream data", "").unwrap();
    match cache.load("stream-test", 60, "").unwrap() {
        LoadResult::Hit(v) => assert_eq!(v.as_str(), Some("stream data")),
        LoadResult::Miss => panic!("expected hit"),
    }
}

#[test]
fn filesystem_streaming_missing_key() {
    let dir = TempDir::new().unwrap();
    let cache = Cache::new(Filesystem::with_streaming(
        dir.path().to_str().unwrap(),
        true,
    ));
    assert!(cache.load("missing-stream-test", 60, "").unwrap().is_miss());
}

#[test]
fn filesystem_get_path_concatenates_slash() {
    let fs = Filesystem::new("/tmp/cache");
    let path = fs.get_path("a/b/c");
    assert!(path.ends_with("/a/b/c") || path.ends_with("\\a/b/c"));
}

#[test]
fn filesystem_name() {
    assert_eq!(Filesystem::new("/tmp").get_name(None), "filesystem");
}
