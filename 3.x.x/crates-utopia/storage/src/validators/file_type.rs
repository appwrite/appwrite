use std::collections::HashMap;
use std::fs::File;
use std::io::Read;
use std::path::Path;

pub const FILE_TYPE_JPEG: &str = "jpeg";
pub const FILE_TYPE_GIF: &str = "gif";
pub const FILE_TYPE_PNG: &str = "png";
pub const FILE_TYPE_GZIP: &str = "gz";

fn signatures() -> HashMap<&'static str, &'static [u8]> {
    HashMap::from([
        (FILE_TYPE_JPEG, b"\xFF\xD8\xFF".as_slice()),
        (FILE_TYPE_GIF, b"GIF".as_slice()),
        (FILE_TYPE_PNG, b"\x89PNG\r\n".as_slice()),
        (FILE_TYPE_GZIP, b"\x1f\x8b".as_slice()),
    ])
}

/// Validates file contents against known binary signatures.
#[derive(Debug, Clone)]
pub struct FileType {
    allowed: Vec<String>,
}

impl FileType {
    pub const DESCRIPTION: &'static str = "File mime-type is not allowed";

    pub fn new(allowed: impl IntoIterator<Item = impl Into<String>>) -> Result<Self, String> {
        let signatures = signatures();
        let allowed = allowed.into_iter().map(Into::into).collect::<Vec<_>>();

        for key in &allowed {
            if !signatures.contains_key(key.as_str()) {
                return Err("unknown file mime type".to_string());
            }
        }

        Ok(Self { allowed })
    }

    pub fn description(&self) -> &'static str {
        Self::DESCRIPTION
    }

    pub fn is_valid_path(&self, path: &Path) -> bool {
        let Ok(mut file) = File::open(path) else {
            return false;
        };

        let mut bytes = [0_u8; 8];
        let Ok(read) = file.read(&mut bytes) else {
            return false;
        };

        self.is_valid_bytes(&bytes[..read])
    }

    pub fn is_valid_bytes(&self, bytes: &[u8]) -> bool {
        let signatures = signatures();
        self.allowed.iter().any(|key| {
            signatures
                .get(key.as_str())
                .is_some_and(|signature| bytes.starts_with(signature))
        })
    }
}

#[cfg(feature = "validators")]
impl utopia_validators::Validator for FileType {
    fn description(&self) -> String {
        Self::DESCRIPTION.to_string()
    }

    fn value_type(&self) -> utopia_validators::ValueType {
        utopia_validators::ValueType::String
    }

    fn is_valid(&self, value: &serde_json::Value) -> bool {
        value
            .as_str()
            .map(Path::new)
            .is_some_and(|path| self.is_valid_path(path))
    }
}
