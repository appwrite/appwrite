use std::path::{Path, PathBuf};

/// Validates that a path points to a regular file accepted as an upload.
///
/// PHP uses `is_uploaded_file`, which is runtime-specific. The Rust port checks
/// that the file exists, is regular, and is under one of the configured upload
/// roots when roots are supplied.
#[derive(Debug, Clone, Default)]
pub struct Upload {
    allowed_roots: Vec<PathBuf>,
}

impl Upload {
    pub const DESCRIPTION: &'static str = "Not a valid upload file";

    pub fn new(allowed_roots: impl IntoIterator<Item = impl Into<PathBuf>>) -> Self {
        Self {
            allowed_roots: allowed_roots.into_iter().map(Into::into).collect(),
        }
    }

    pub fn description(&self) -> &'static str {
        Self::DESCRIPTION
    }

    pub fn is_valid_path(&self, path: &Path) -> bool {
        let Ok(canonical_path) = path.canonicalize() else {
            return false;
        };

        if !canonical_path.is_file() {
            return false;
        }

        if self.allowed_roots.is_empty() {
            return true;
        }

        self.allowed_roots.iter().any(|root| {
            root.canonicalize()
                .is_ok_and(|canonical_root| canonical_path.starts_with(canonical_root))
        })
    }

    pub fn is_valid(&self, path: impl AsRef<Path>) -> bool {
        self.is_valid_path(path.as_ref())
    }
}

#[cfg(feature = "validators")]
impl utopia_validators::Validator for Upload {
    fn description(&self) -> String {
        Self::DESCRIPTION.to_string()
    }

    fn value_type(&self) -> utopia_validators::ValueType {
        utopia_validators::ValueType::String
    }

    fn is_valid(&self, value: &serde_json::Value) -> bool {
        value
            .as_str()
            .is_some_and(|path| self.is_valid_path(Path::new(path)))
    }
}
