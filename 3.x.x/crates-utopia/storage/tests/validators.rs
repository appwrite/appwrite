use std::path::Path;

use tempfile::{NamedTempFile, TempDir};
use utopia_storage::{
    FileExt, FileName, FileSize, FileType, Upload, FILE_TYPE_JPEG, FILE_TYPE_PNG, JPEG, JPG, PNG,
};

#[test]
fn file_name_validator() {
    assert!(FileName::is_valid("image.png"));
    assert!(FileName::is_valid("my-file_01.txt"));
    assert!(!FileName::is_valid(""));
    assert!(!FileName::is_valid("bad name.txt"));
    assert!(!FileName::is_valid("bad/name.txt"));
}

#[test]
fn file_size_validator() {
    let validator = FileSize::new(100);
    assert!(validator.is_valid(100));
    assert!(validator.is_valid(50));
    assert!(!validator.is_valid(101));
}

#[test]
fn file_ext_validator() {
    let validator = FileExt::new([JPEG, JPG, PNG]);
    assert!(validator.is_valid("photo.jpeg"));
    assert!(validator.is_valid("photo.JPG"));
    assert!(!validator.is_valid("photo.gif"));
}

#[test]
fn file_type_validator_reads_signatures() {
    let validator = FileType::new([FILE_TYPE_JPEG, FILE_TYPE_PNG]).expect("validator");
    assert!(validator.is_valid_bytes(&[0xFF, 0xD8, 0xFF, 0x00]));
    assert!(validator.is_valid_bytes(b"\x89PNG\r\n\x1a\n"));
    assert!(!validator.is_valid_bytes(b"GIF89a"));

    let png = NamedTempFile::new().expect("tempfile");
    std::fs::write(png.path(), b"\x89PNG\r\n\x1a\n").expect("write");
    assert!(validator.is_valid_path(png.path()));

    let unknown = Path::new("not-a-real-type");
    let _ = unknown;
    assert!(FileType::new(["unknown"]).is_err());
}

#[test]
fn upload_validator_requires_file_under_allowed_root() {
    let allowed = TempDir::new().expect("allowed tempdir");
    let outside = TempDir::new().expect("outside tempdir");
    let inside_file = allowed.path().join("upload.bin");
    let outside_file = outside.path().join("upload.bin");
    std::fs::write(&inside_file, b"ok").expect("write inside");
    std::fs::write(&outside_file, b"no").expect("write outside");

    let validator = Upload::new([allowed.path()]);
    assert!(validator.is_valid(&inside_file));
    assert!(!validator.is_valid(&outside_file));
    assert!(!validator.is_valid(allowed.path().join("missing.bin")));
    assert!(!validator.is_valid(allowed.path()));
}
