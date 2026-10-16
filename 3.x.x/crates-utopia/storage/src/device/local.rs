use std::fs::{self, File, OpenOptions};
use std::io::{Read, Seek, SeekFrom, Write as IoWrite};
use std::path::{Path, PathBuf};

use mime_guess::from_path;

use super::{
    absolute_path, copy_reader_to_writer, Device, PartValue, ReadSeek, UploadMetadata,
    COPY_CHUNK_SIZE, PIPE_CHUNK_SIZE,
};
use crate::device_type::DeviceType;
use crate::error::{NotFound, StorageError, UploadError};
use crate::file_info::{FileInfo, FileList};

/// Local filesystem storage device.
#[derive(Debug, Clone)]
pub struct Local {
    root: PathBuf,
}

impl Local {
    pub fn new(root: impl Into<PathBuf>) -> Self {
        Self { root: root.into() }
    }

    fn write_file(path: &Path, data: &[u8]) -> Result<(), StorageError> {
        let mut file = OpenOptions::new()
            .write(true)
            .create(true)
            .truncate(true)
            .open(path)?;
        let mut offset = 0;
        while offset < data.len() {
            let written = file.write(&data[offset..])?;
            if written == 0 {
                return Err(StorageError::message(format!(
                    "can't write file {}",
                    path.display()
                )));
            }
            offset += written;
        }
        Ok(())
    }

    fn write_file_from(path: &Path, reader: &mut dyn Read) -> Result<(), StorageError> {
        let mut file = OpenOptions::new()
            .write(true)
            .create(true)
            .truncate(true)
            .open(path)?;
        copy_reader_to_writer(reader, &mut file)?;
        Ok(())
    }

    fn chunk_tmp_dir(path: &Path) -> PathBuf {
        path.parent()
            .unwrap_or_else(|| Path::new("."))
            .join(format!(
                "tmp_{}",
                path.file_name().unwrap_or_default().to_string_lossy()
            ))
    }

    fn chunk_file_path(path: &Path, chunk: u32) -> PathBuf {
        let stem = path
            .file_stem()
            .map(|value| value.to_string_lossy().into_owned())
            .unwrap_or_default();
        Self::chunk_tmp_dir(path).join(format!("{stem}.part.{chunk}"))
    }

    fn count_chunks(tmp: &Path, path: &Path) -> u32 {
        let stem = path
            .file_stem()
            .map(|value| value.to_string_lossy().into_owned())
            .unwrap_or_default();
        let pattern = format!("{stem}.part.");
        let Ok(entries) = fs::read_dir(tmp) else {
            return 0;
        };

        entries
            .filter_map(Result::ok)
            .filter(|entry| entry.file_name().to_string_lossy().starts_with(&pattern))
            .count() as u32
    }

    fn join_chunks(path: &Path, chunks: u32) -> Result<(), StorageError> {
        if path.exists() {
            return Ok(());
        }

        let tmp = Self::chunk_tmp_dir(path);
        let parent = path.parent().unwrap_or_else(|| Path::new("."));
        let tmp_assemble = unique_temp_path(
            parent,
            &format!(
                "tmp_assemble_{}_",
                path.file_name().unwrap_or_default().to_string_lossy()
            ),
        );

        let mut parts_to_unlink = Vec::new();
        {
            let mut dest = File::create(&tmp_assemble)?;
            for index in 1..=chunks {
                let part = Self::chunk_file_path(path, index);
                let mut src = File::open(&part).map_err(|error| {
                    StorageError::message(format!(
                        "failed to open chunk {}: {error}",
                        part.display()
                    ))
                })?;
                std::io::copy(&mut src, &mut dest).map_err(|error| {
                    StorageError::message(format!(
                        "failed to copy chunk {}: {error}",
                        part.display()
                    ))
                })?;
                parts_to_unlink.push(part);
            }
        }

        match fs::rename(&tmp_assemble, path) {
            Ok(()) => {}
            Err(error) => {
                let _ = fs::remove_file(&tmp_assemble);
                if path.exists() {
                    return Ok(());
                }
                return Err(StorageError::from(error));
            }
        }

        for part in parts_to_unlink {
            let _ = fs::remove_file(part);
        }
        let _ = fs::remove_dir(tmp);

        Ok(())
    }

    fn scan_directory(dir: &Path) -> Result<Vec<PathBuf>, StorageError> {
        let mut entries = Vec::new();
        if !dir.exists() {
            return Ok(entries);
        }

        for entry in fs::read_dir(dir)? {
            let entry = entry?;
            let file_name = entry.file_name().to_string_lossy().into_owned();
            if file_name == "." || file_name == ".." {
                continue;
            }
            entries.push(entry.path());
        }
        Ok(entries)
    }

    fn collect_files(prefix: &Path) -> Result<Vec<PathBuf>, StorageError> {
        let mut paths = Vec::new();
        let mut pending = vec![prefix.to_path_buf()];

        while let Some(directory) = pending.pop() {
            for entry in Self::scan_directory(&directory)? {
                if entry.is_dir() {
                    pending.push(entry);
                } else {
                    paths.push(entry);
                }
            }
        }

        paths.sort();
        Ok(paths)
    }

    fn stat_error(path: &Path, exists: bool, action: &str) -> StorageError {
        if exists {
            StorageError::message(format!("failed to {action} file {}", path.display()))
        } else {
            NotFound(format!("file not found: {}", path.display())).into()
        }
    }

    /// Returns the total size in bytes of all files under `path`, or `-1` on error.
    pub fn get_directory_size(path: &Path) -> i64 {
        if path.as_os_str().is_empty() {
            return -1;
        }

        let path = path
            .to_string_lossy()
            .trim_end_matches(std::path::MAIN_SEPARATOR)
            .to_string();
        let path = format!("{path}{}", std::path::MAIN_SEPARATOR);

        let Ok(entries) = fs::read_dir(&path) else {
            return -1;
        };

        let mut size = 0_i64;
        for entry in entries.flatten() {
            let file_name = entry.file_name();
            let name = file_name.to_string_lossy();
            if name.starts_with('.') {
                continue;
            }
            let entry_path = entry.path();
            if entry_path.is_dir() {
                size += Self::get_directory_size(&entry_path);
            } else if let Ok(metadata) = entry.metadata() {
                size += i64::try_from(metadata.len()).unwrap_or(0);
            }
        }
        size
    }

    /// Available space on the filesystem containing the device root (best-effort; `0` when unknown).
    pub fn get_partition_free_space(&self) -> u64 {
        fs2::available_space(&self.root).unwrap_or(0)
    }

    /// Total space on the filesystem containing the device root (best-effort; `0` when unknown).
    pub fn get_partition_total_space(&self) -> u64 {
        fs2::total_space(&self.root).unwrap_or(0)
    }
}

impl Device for Local {
    fn get_type(&self) -> DeviceType {
        DeviceType::Local
    }

    fn get_root(&self) -> &Path {
        &self.root
    }

    fn get_path(&self, filename: &str) -> PathBuf {
        let root = self.root.to_string_lossy();
        let combined = format!(
            "{}/{}",
            root.trim_end_matches('/'),
            filename.trim_start_matches('/')
        );
        absolute_path(&combined)
    }

    fn prepare(
        &self,
        path: &Path,
        _content_type: &str,
        _chunks: u32,
        _metadata: &mut UploadMetadata,
    ) -> Result<(), StorageError> {
        if let Some(parent) = path.parent() {
            self.create_directory(parent)?;
        }
        Ok(())
    }

    fn upload_chunk(
        &self,
        data: &[u8],
        path: &Path,
        chunk: u32,
        chunks: u32,
        metadata: &mut UploadMetadata,
    ) -> Result<u32, StorageError> {
        if let Some(parent) = path.parent() {
            self.create_directory(parent)?;
        }

        if chunks == 1 {
            Self::write_file(path, data)?;
            metadata.parts.insert(chunk, PartValue::Done);
            metadata.chunks = 1;
            return Ok(1);
        }

        let tmp = Self::chunk_tmp_dir(path);
        self.create_directory(&tmp)?;

        let chunk_file = Self::chunk_file_path(path, chunk);
        if !chunk_file.exists() {
            Self::write_file(&chunk_file, data)?;
        }

        let chunks_received = Self::count_chunks(&tmp, path);
        metadata.parts.insert(chunk, PartValue::Done);
        metadata.chunks = chunks_received;
        Ok(chunks_received)
    }

    fn finalize(
        &self,
        path: &Path,
        chunks: u32,
        _metadata: &mut UploadMetadata,
    ) -> Result<bool, StorageError> {
        if path.exists() {
            return Ok(true);
        }

        if chunks == 1 {
            return Ok(false);
        }

        for index in 1..=chunks {
            let part = Self::chunk_file_path(path, index);
            if !part.exists() {
                return Err(UploadError(format!("missing chunk {index}")).into());
            }
        }

        Self::join_chunks(path, chunks)?;
        Ok(true)
    }

    fn abort(&self, path: &Path, _upload_id: &str) -> Result<bool, StorageError> {
        if path.exists() {
            fs::remove_file(path)?;
        }

        let tmp = Self::chunk_tmp_dir(path);
        let parent = path
            .parent()
            .ok_or_else(|| NotFound(format!("file doesn't exist: {}", path.display())))?;

        if !parent.exists() {
            return Err(NotFound(format!("file doesn't exist: {}", parent.display())).into());
        }

        if tmp.exists() {
            for entry in Self::scan_directory(&tmp)? {
                self.delete(&entry, true)?;
            }
            return Ok(fs::remove_dir(&tmp).is_ok());
        }

        Ok(true)
    }

    fn read(&self, path: &Path, offset: u64, length: Option<u64>) -> Result<Vec<u8>, StorageError> {
        let mut buffer = Vec::new();
        self.read_into(path, &mut buffer, offset, length)?;
        Ok(buffer)
    }

    fn read_into(
        &self,
        path: &Path,
        writer: &mut dyn IoWrite,
        offset: u64,
        length: Option<u64>,
    ) -> Result<u64, StorageError> {
        if !self.exists(path) {
            return Err(NotFound("file not found".to_string()).into());
        }

        let mut file = File::open(path)?;
        if offset > 0 {
            file.seek(SeekFrom::Start(offset))?;
        }

        match length {
            None => copy_reader_to_writer(&mut file, writer),
            Some(0) => Ok(0),
            Some(len) => {
                let mut remaining = len;
                let mut pipe = vec![0_u8; PIPE_CHUNK_SIZE];
                let mut total = 0_u64;
                while remaining > 0 {
                    let want = remaining.min(pipe.len() as u64) as usize;
                    let read = file.read(&mut pipe[..want])?;
                    if read == 0 {
                        break;
                    }
                    writer.write_all(&pipe[..read]).map_err(|error| {
                        StorageError::message(format!(
                            "failed to stream read for {}: {error}",
                            path.display()
                        ))
                    })?;
                    total += read as u64;
                    remaining -= read as u64;
                }
                Ok(total)
            }
        }
    }

    fn write(&self, path: &Path, data: &[u8], _content_type: &str) -> Result<(), StorageError> {
        if let Some(parent) = path.parent() {
            self.create_directory(parent)?;
        }
        Self::write_file(path, data)
    }

    fn write_from(
        &self,
        path: &Path,
        reader: &mut dyn ReadSeek,
        _content_type: &str,
    ) -> Result<(), StorageError> {
        if let Some(parent) = path.parent() {
            self.create_directory(parent)?;
        }
        Self::write_file_from(path, reader)
    }

    fn upload_from(
        &self,
        reader: &mut dyn Read,
        path: &Path,
        content_type: &str,
        chunk: u32,
        chunks: u32,
        metadata: &mut UploadMetadata,
    ) -> Result<u32, StorageError> {
        self.prepare(path, content_type, chunks, metadata)?;

        if let Some(parent) = path.parent() {
            self.create_directory(parent)?;
        }

        let chunks_received = if chunks == 1 {
            Self::write_file_from(path, reader)?;
            metadata.parts.insert(chunk, PartValue::Done);
            metadata.chunks = 1;
            1
        } else {
            let tmp = Self::chunk_tmp_dir(path);
            self.create_directory(&tmp)?;
            let chunk_file = Self::chunk_file_path(path, chunk);
            if !chunk_file.exists() {
                Self::write_file_from(&chunk_file, reader)?;
            }
            let chunks_received = Self::count_chunks(&tmp, path);
            metadata.parts.insert(chunk, PartValue::Done);
            metadata.chunks = chunks_received;
            chunks_received
        };

        if chunks > 1 && chunks == chunks_received && !self.finalize(path, chunks, metadata)? {
            return Err(
                UploadError(format!("failed to finalize upload {}", path.display())).into(),
            );
        }
        Ok(chunks_received)
    }

    fn r#move(&self, source: &Path, target: &Path) -> Result<bool, StorageError> {
        if source == target {
            return Ok(false);
        }

        if let Some(parent) = target.parent() {
            self.create_directory(parent)?;
        }

        fs::rename(source, target)?;
        Ok(true)
    }

    fn delete(&self, path: &Path, recursive: bool) -> Result<bool, StorageError> {
        if path.is_dir() {
            if recursive {
                for entry in fs::read_dir(path)? {
                    let entry = entry?;
                    if !self.delete(&entry.path(), true)? {
                        return Ok(false);
                    }
                }
                return Ok(fs::remove_dir(path).is_ok());
            }
            return Ok(false);
        }

        if path.is_file() || path.is_symlink() {
            return Ok(fs::remove_file(path).is_ok());
        }

        Ok(false)
    }

    fn delete_path(&self, path: &str) -> Result<bool, StorageError> {
        let absolute = self.get_path(path);
        if !absolute.is_dir() {
            return Ok(false);
        }

        let root_prefix = format!("{}/", self.root.to_string_lossy().trim_end_matches('/'));
        for entry in Self::scan_directory(&absolute)? {
            if entry.is_dir() {
                let relative = entry
                    .to_string_lossy()
                    .trim_start_matches(&root_prefix)
                    .to_string();
                self.delete_path(&relative)?;
            } else {
                self.delete(&entry, true)?;
            }
        }

        Ok(fs::remove_dir(absolute).is_ok())
    }

    fn exists(&self, path: &Path) -> bool {
        path.exists()
    }

    fn list_files(
        &self,
        prefix: &Path,
        max: usize,
        cursor: Option<&str>,
    ) -> Result<FileList, StorageError> {
        let paths = Self::collect_files(prefix)?;
        let offset = cursor
            .and_then(|value| value.parse::<usize>().ok())
            .unwrap_or(0);
        let page = paths
            .iter()
            .skip(offset)
            .take(max)
            .cloned()
            .collect::<Vec<_>>();

        let files = page
            .iter()
            .map(|path| {
                let metadata = fs::metadata(path)?;
                Ok(FileInfo::new(
                    path.clone(),
                    metadata.len(),
                    metadata.modified().ok(),
                    None,
                ))
            })
            .collect::<Result<Vec<_>, StorageError>>()?;

        let next_cursor = if offset + page.len() < paths.len() {
            Some((offset + page.len()).to_string())
        } else {
            None
        };

        Ok(FileList::new(files, next_cursor))
    }

    fn get_file_size(&self, path: &Path) -> Result<u64, StorageError> {
        let exists = self.exists(path);
        match fs::metadata(path) {
            Ok(metadata) => Ok(metadata.len()),
            Err(_) => Err(Self::stat_error(path, exists, "get size of")),
        }
    }

    fn get_file_mime_type(&self, path: &Path) -> Result<String, StorageError> {
        let exists = self.exists(path);
        if !exists {
            return Err(NotFound(format!("file not found: {}", path.display())).into());
        }

        Ok(from_path(path).first().map_or_else(
            || "application/octet-stream".to_string(),
            |mime| mime.essence_str().to_string(),
        ))
    }

    fn get_file_hash(&self, path: &Path) -> Result<String, StorageError> {
        let exists = self.exists(path);
        let mut file = File::open(path).map_err(|_| Self::stat_error(path, exists, "hash"))?;
        use md5::{Digest, Md5};

        let mut context = Md5::new();
        let mut buffer = vec![0_u8; PIPE_CHUNK_SIZE];
        loop {
            let read = file.read(&mut buffer)?;
            if read == 0 {
                break;
            }
            context.update(&buffer[..read]);
        }
        Ok(hex_lower(&context.finalize()))
    }

    fn create_directory(&self, path: &Path) -> Result<bool, StorageError> {
        if path.exists() {
            return Ok(true);
        }
        fs::create_dir_all(path)?;
        Ok(true)
    }
}

fn hex_lower(bytes: &[u8]) -> String {
    const HEX: &[u8; 16] = b"0123456789abcdef";
    let mut out = String::with_capacity(bytes.len() * 2);
    for byte in bytes {
        out.push(HEX[(byte >> 4) as usize] as char);
        out.push(HEX[(byte & 0x0f) as usize] as char);
    }
    out
}

fn unique_temp_path(parent: &Path, prefix: &str) -> PathBuf {
    for counter in 0..10_000_u32 {
        let candidate = parent.join(format!("{prefix}{counter}"));
        if !candidate.exists() {
            return candidate;
        }
    }
    parent.join(format!(
        "{prefix}{}",
        std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .map_or(0, |duration| duration.as_nanos())
    ))
}

#[allow(dead_code)]
const _: () = {
    let _ = COPY_CHUNK_SIZE;
};
