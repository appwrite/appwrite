use std::collections::HashMap;
use std::fs;
use std::path::{Path, PathBuf};

#[derive(Debug, Default)]
pub struct Files {
    files: HashMap<String, (Vec<u8>, String)>,
}

impl Files {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn is_empty(&self) -> bool {
        self.files.is_empty()
    }

    pub fn load(&mut self, directory: impl AsRef<Path>, root: Option<&str>) -> std::io::Result<()> {
        let directory = directory.as_ref();
        let root = root.unwrap_or("");
        self.walk(directory, root, directory)?;
        Ok(())
    }

    fn walk(&mut self, base: &Path, url_root: &str, dir: &Path) -> std::io::Result<()> {
        if !dir.exists() {
            return Ok(());
        }
        for entry in fs::read_dir(dir)? {
            let entry = entry?;
            let path = entry.path();
            if path.is_dir() {
                self.walk(base, url_root, &path)?;
            } else if let Ok(rel) = path.strip_prefix(base) {
                let mut uri = PathBuf::from(url_root);
                uri.push(rel);
                let uri = format!("/{}", uri.to_string_lossy()).replace('\\', "/");
                let uri = uri.replace("//", "/");
                let bytes = fs::read(&path)?;
                let mime = mime_guess::from_path(&path)
                    .first_or_octet_stream()
                    .essence_str()
                    .to_string();
                self.files.insert(uri, (bytes, mime));
            }
        }
        Ok(())
    }

    pub fn is_loaded(&self, uri: &str) -> bool {
        let path = uri.split('?').next().unwrap_or(uri);
        self.files.contains_key(path)
    }

    pub fn get(&self, uri: &str) -> Option<&(Vec<u8>, String)> {
        let path = uri.split('?').next().unwrap_or(uri);
        self.files.get(path)
    }
}
