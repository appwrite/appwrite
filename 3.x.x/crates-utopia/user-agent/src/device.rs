use serde::Serialize;

/// Detected device metadata.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct Device {
    pub r#type: Option<String>,
    pub brand: Option<String>,
    pub model: Option<String>,
}

impl Device {
    /// Empty / unknown device.
    pub fn new() -> Self {
        Self {
            r#type: None,
            brand: None,
            model: None,
        }
    }

    /// Known device with optional brand and model.
    pub fn known(r#type: &str, brand: Option<&str>, model: Option<&str>) -> Self {
        Self {
            r#type: Some(r#type.to_string()),
            brand: brand.map(str::to_string),
            model: model.map(str::to_string),
        }
    }

    /// Whether any device field was detected.
    pub fn is_known(&self) -> bool {
        self.r#type.is_some() || self.brand.is_some() || self.model.is_some()
    }

    /// Serialize to a flat map (PHP `toArray` shape).
    pub fn to_array(&self) -> DeviceArray {
        DeviceArray {
            r#type: self.r#type.clone(),
            brand: self.brand.clone(),
            model: self.model.clone(),
        }
    }
}

impl Default for Device {
    fn default() -> Self {
        Self::new()
    }
}

/// `Device::to_array()` output.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct DeviceArray {
    pub r#type: Option<String>,
    pub brand: Option<String>,
    pub model: Option<String>,
}
