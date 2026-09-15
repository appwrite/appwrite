use chrono::{DateTime, Utc};

/// [`Utopia\Migration\Destinations\SchemaAction`](https://github.com/utopia-php/migration/blob/7e371c8f59bf/src/Migration/Destinations/OnDuplicate.php).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum SchemaAction {
    Create,
    Skip,
    Overwrite,
}

/// [`Utopia\Migration\Destinations\OnDuplicate`](https://github.com/utopia-php/migration/blob/7e371c8f59bf/src/Migration/Destinations/OnDuplicate.php).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub enum OnDuplicate {
    #[default]
    Fail,
    Skip,
    Overwrite,
}

impl OnDuplicate {
    pub const FAIL: &'static str = "fail";
    pub const SKIP: &'static str = "skip";
    pub const OVERWRITE: &'static str = "overwrite";

    #[must_use]
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Fail => Self::FAIL,
            Self::Skip => Self::SKIP,
            Self::Overwrite => Self::OVERWRITE,
        }
    }

    /// PHP `OnDuplicate::values()` - declaration order.
    #[must_use]
    pub fn values() -> [&'static str; 3] {
        [Self::FAIL, Self::SKIP, Self::OVERWRITE]
    }

    #[must_use]
    pub fn parse(value: &str) -> Option<Self> {
        match value {
            Self::FAIL => Some(Self::Fail),
            Self::SKIP => Some(Self::Skip),
            Self::OVERWRITE => Some(Self::Overwrite),
            _ => None,
        }
    }

    /// PHP `resolveSchemaAction(bool $exists, ?string $sourceUpdatedAt, ?string $destUpdatedAt)`.
    #[must_use]
    pub fn resolve_schema_action(
        self,
        exists: bool,
        source_updated_at: Option<&str>,
        dest_updated_at: Option<&str>,
    ) -> SchemaAction {
        if !exists {
            return SchemaAction::Create;
        }
        match self {
            Self::Fail => SchemaAction::Create,
            Self::Skip => SchemaAction::Skip,
            Self::Overwrite => {
                if self.source_is_newer(source_updated_at, dest_updated_at) {
                    SchemaAction::Overwrite
                } else {
                    SchemaAction::Skip
                }
            }
        }
    }

    fn source_is_newer(self, source: Option<&str>, dest: Option<&str>) -> bool {
        match (parse_timestamp(source), parse_timestamp(dest)) {
            (Some(src), Some(dst)) => src > dst,
            _ => false,
        }
    }
}

/// PHP `strtotime` plus rejection of non-positive epochs (`0000-00-00`).
fn parse_timestamp(value: Option<&str>) -> Option<i64> {
    let value = value?;
    if value.is_empty() {
        return None;
    }
    if let Ok(dt) = DateTime::parse_from_rfc3339(value) {
        let epoch = dt.timestamp();
        return (epoch > 0).then_some(epoch);
    }
    const FORMATS: &[&str] = &[
        "%Y-%m-%d %H:%M:%S%.f",
        "%Y-%m-%d %H:%M:%S",
        "%Y-%m-%dT%H:%M:%S%.f",
        "%Y-%m-%dT%H:%M:%S",
        "%Y-%m-%d",
    ];
    for fmt in FORMATS {
        if let Ok(naive) = chrono::NaiveDateTime::parse_from_str(value, fmt) {
            let epoch = naive.and_utc().timestamp();
            return (epoch > 0).then_some(epoch);
        }
        if *fmt == "%Y-%m-%d" {
            if let Ok(d) = chrono::NaiveDate::parse_from_str(value, fmt) {
                let epoch = d
                    .and_hms_opt(0, 0, 0)
                    .map_or(0, |n| n.and_utc().timestamp());
                return (epoch > 0).then_some(epoch);
            }
        }
    }
    let _ = Utc;
    None
}
