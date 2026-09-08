use tempfile::TempDir;
use utopia_storage::{Device, Local, NotFound, StorageError, UploadError};

fn device() -> (TempDir, Local) {
    let temp = TempDir::new().expect("tempdir");
    let local = Local::new(temp.path());
    (temp, local)
}

#[test]
fn write_read_delete_exists() {
    let (_temp, device) = device();
    let path = device.get_path("text.txt");

    device
        .write(&path, b"Hello World", "text/plain")
        .expect("write");
    assert!(device.exists(&path));
    assert_eq!(device.read(&path, 0, None).expect("read"), b"Hello World");

    assert!(device.delete(&path, false).expect("delete"));
    assert!(!device.exists(&path));
}

#[test]
fn read_missing_file_returns_not_found() {
    let (_temp, device) = device();
    let path = device.get_path("missing.txt");

    let error = device.read(&path, 0, None).unwrap_err();
    assert!(matches!(error, StorageError::NotFound(_)));
}

#[test]
fn copy_between_devices() {
    let temp_a = TempDir::new().expect("tempdir a");
    let temp_b = TempDir::new().expect("tempdir b");
    let source_device = Local::new(temp_a.path());
    let target_device = Local::new(temp_b.path());

    let source = source_device.get_path("hello.txt");
    source_device
        .write(&source, b"Hello World", "text/plain")
        .expect("write source");

    let target = target_device.get_path("hello.txt");
    source_device
        .copy(&source, &target, Some(&target_device), 10_000_000)
        .expect("copy");

    assert!(target_device.exists(&target));
    assert_eq!(
        target_device.read(&target, 0, None).expect("read"),
        b"Hello World"
    );
}

#[test]
fn list_files_paginates() {
    let (_temp, device) = device();
    let directory = device.get_path("list-files");
    device
        .write(&directory.join("a.txt"), b"aa", "text/plain")
        .expect("write a");
    device
        .write(&directory.join("nested").join("b.txt"), b"bb", "text/plain")
        .expect("write b");
    device
        .write(&directory.join(".hidden"), b"hh", "text/plain")
        .expect("write hidden");

    let first = device
        .list_files(&directory, 2, None)
        .expect("list first page");
    assert_eq!(first.files.len(), 2);
    assert!(first.cursor.is_some());

    let second = device
        .list_files(&directory, 10, first.cursor.as_deref())
        .expect("list second page");
    assert_eq!(second.files.len(), 1);
    assert!(second.cursor.is_none());

    let paths = first
        .files
        .iter()
        .chain(second.files.iter())
        .map(|file| file.path.clone())
        .collect::<Vec<_>>();
    assert!(paths.contains(&directory.join("a.txt")));
    assert!(paths.contains(&directory.join("nested").join("b.txt")));
    assert!(paths.contains(&directory.join(".hidden")));
}

#[test]
fn chunked_upload_out_of_order() {
    let (_temp, device) = device();
    let path = device.get_path("chunked.txt");
    let mut metadata = utopia_storage::UploadMetadata::default();

    device
        .upload(b"bbb", &path, "text/plain", 2, 3, &mut metadata)
        .expect("chunk 2");
    assert!(!device.exists(&path));

    device
        .upload(b"aaa", &path, "text/plain", 1, 3, &mut metadata)
        .expect("chunk 1");
    assert!(!device.exists(&path));

    device
        .upload(b"ccc", &path, "text/plain", 3, 3, &mut metadata)
        .expect("chunk 3");
    assert_eq!(device.read(&path, 0, None).expect("read"), b"aaabbbccc");
}

#[test]
fn finalize_requires_all_chunks() {
    let (_temp, device) = device();
    let path = device.get_path("missing-chunk.txt");
    let mut metadata = utopia_storage::UploadMetadata::default();

    device
        .upload(b"aaa", &path, "text/plain", 1, 2, &mut metadata)
        .expect("chunk 1");

    let error = device.finalize(&path, 2, &mut metadata).unwrap_err();
    assert!(matches!(error, StorageError::Upload(UploadError(_))));

    device.abort(&path, "").expect("abort");
}

#[test]
fn move_renames_file() {
    let (_temp, device) = device();
    let source = device.get_path("move-source.txt");
    let target = device.get_path("move-target.txt");

    device
        .write(&source, b"moved", "text/plain")
        .expect("write");
    assert!(device.r#move(&source, &target).expect("move"));
    assert!(!device.exists(&source));
    assert_eq!(device.read(&target, 0, None).expect("read"), b"moved");
}

#[test]
fn create_directory_and_get_file_size() {
    let (_temp, device) = device();
    let path = device.get_path("nested/dir/file.bin");
    device
        .write(&path, &[1, 2, 3, 4], "application/octet-stream")
        .expect("write");

    assert_eq!(device.get_file_size(&path).expect("size"), 4);
}

#[test]
fn get_file_size_missing_returns_not_found() {
    let (_temp, device) = device();
    let path = device.get_path("missing-size.txt");
    let error = device.get_file_size(&path).unwrap_err();
    assert!(matches!(error, StorageError::NotFound(NotFound(_))));
}
