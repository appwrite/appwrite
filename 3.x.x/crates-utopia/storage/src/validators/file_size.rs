/// Validates that a file size does not exceed a maximum number of bytes.
#[derive(Debug, Clone, Copy)]
pub struct FileSize {
    max: u64,
}

impl FileSize {
    pub fn new(max: u64) -> Self {
        Self { max }
    }

    pub fn description(&self) -> String {
        format!("File size can't be bigger than {}", self.max)
    }

    pub fn is_valid(&self, file_size: u64) -> bool {
        file_size <= self.max
    }
}

#[cfg(feature = "validators")]
impl utopia_validators::Validator for FileSize {
    fn description(&self) -> String {
        self.description()
    }

    fn value_type(&self) -> utopia_validators::ValueType {
        utopia_validators::ValueType::Integer
    }

    fn is_valid(&self, value: &serde_json::Value) -> bool {
        value
            .as_u64()
            .or_else(|| value.as_i64().and_then(|v| u64::try_from(v).ok()))
            .is_some_and(|size| self.is_valid(size))
    }
}
