//! PHP `Utopia\Database\Validator\Spatial`.

use serde_json::Value;
use utopia_validators::{Validator, ValueType};

use crate::constants::{VAR_LINESTRING, VAR_POINT, VAR_POLYGON};

/// PHP `Utopia\Database\Validator\Spatial`.
#[derive(Debug, Clone)]
pub struct Spatial {
    spatial_type: String,
    message: String,
}

impl Spatial {
    #[must_use]
    pub fn new(spatial_type: impl Into<String>) -> Self {
        Self {
            spatial_type: spatial_type.into(),
            message: String::new(),
        }
    }

    #[must_use]
    pub fn is_wkt_string(value: &str) -> bool {
        let value = value.trim();
        regex::Regex::new(r"(?i)^(POINT|LINESTRING|POLYGON)\s*\(")
            .expect("wkt")
            .is_match(value)
    }

    #[must_use]
    pub fn get_spatial_type(&self) -> &str {
        &self.spatial_type
    }

    fn valid_coord(&mut self, x: f64, y: f64) -> bool {
        if !(-180.0..=180.0).contains(&x) {
            self.message = format!("Longitude (x) must be between -180 and 180, got {x}");
            return false;
        }
        if !(-90.0..=90.0).contains(&y) {
            self.message = format!("Latitude (y) must be between -90 and 90, got {y}");
            return false;
        }
        true
    }
}

impl Validator for Spatial {
    fn description(&self) -> String {
        format!(
            "Value must be a valid {}: {}",
            self.spatial_type, self.message
        )
    }

    fn value_type(&self) -> ValueType {
        ValueType::Array
    }

    fn is_valid(&self, value: &Value) -> bool {
        let mut this = self.clone();
        if value.is_null() {
            return true;
        }
        if let Some(s) = value.as_str() {
            return Self::is_wkt_string(s);
        }
        let Some(arr) = value.as_array() else {
            return false;
        };
        match this.spatial_type.as_str() {
            VAR_POINT => {
                if arr.len() != 2 {
                    return false;
                }
                let (Some(x), Some(y)) = (arr[0].as_f64(), arr[1].as_f64()) else {
                    return false;
                };
                this.valid_coord(x, y)
            }
            VAR_LINESTRING => {
                if arr.len() < 2 {
                    return false;
                }
                arr.iter().all(|p| {
                    p.as_array().is_some_and(|pt| {
                        pt.len() == 2
                            && pt[0].as_f64().is_some()
                            && pt[1].as_f64().is_some()
                            && this.valid_coord(
                                pt[0].as_f64().unwrap_or(0.0),
                                pt[1].as_f64().unwrap_or(0.0),
                            )
                    })
                })
            }
            VAR_POLYGON => {
                if arr.is_empty() {
                    return false;
                }
                true
            }
            _ => false,
        }
    }
}
