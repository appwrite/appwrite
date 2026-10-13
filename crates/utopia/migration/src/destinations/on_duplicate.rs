//! What a destination does with a resource it already has.

use php_std::datetime::{TzInfo, strtotime, time};

/// What to do with a schema resource (database, table, column, index):
/// create it, leave the existing one, or update it.
///
/// PHP: `Utopia\Migration\Destinations\SchemaAction`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum SchemaAction {
    /// Create it (the destination's create path raises a duplicate).
    Create,
    /// Leave the existing one.
    Skip,
    /// Update the existing one.
    Overwrite,
}

/// How a destination treats resources that already exist on it.
///
/// PHP: `Utopia\Migration\Destinations\OnDuplicate` (a backed enum).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub enum OnDuplicate {
    /// Fail on duplicates.
    #[default]
    Fail,
    /// Keep the destination's resource.
    Skip,
    /// Replace it when the source's is newer.
    Overwrite,
}

impl OnDuplicate {
    /// Every mode, in declaration order.
    pub const ALL: [OnDuplicate; 3] = [OnDuplicate::Fail, OnDuplicate::Skip, OnDuplicate::Overwrite];

    /// The backing value (`fail`, `skip`, `overwrite`).
    pub fn value(self) -> &'static str {
        match self {
            OnDuplicate::Fail => "fail",
            OnDuplicate::Skip => "skip",
            OnDuplicate::Overwrite => "overwrite",
        }
    }

    /// The mode of backing value `value` (PHP: `OnDuplicate::from()`).
    pub fn from_value(value: &str) -> Option<Self> {
        Self::ALL.into_iter().find(|m| m.value() == value)
    }

    /// The backing values, in declaration order.
    ///
    /// PHP: `OnDuplicate::values()`.
    pub fn values() -> Vec<&'static str> {
        Self::ALL.iter().map(|m| m.value()).collect()
    }

    /// The action for a resource that `exists` (or not) on the destination,
    /// given when each side last updated it: a missing one is created, `Fail`
    /// creates anyway (so the create raises the duplicate), `Skip` skips,
    /// `Overwrite` overwrites only when the source's time is strictly newer
    /// (an absent or unparseable time skips).
    ///
    /// PHP: `resolveSchemaAction($exists, $sourceUpdatedAt, $destUpdatedAt)`.
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
            OnDuplicate::Fail => SchemaAction::Create,
            OnDuplicate::Skip => SchemaAction::Skip,
            OnDuplicate::Overwrite => {
                if source_is_newer(source_updated_at, dest_updated_at) {
                    SchemaAction::Overwrite
                } else {
                    SchemaAction::Skip
                }
            }
        }
    }
}

fn source_is_newer(source: Option<&str>, dest: Option<&str>) -> bool {
    matches!((parse_timestamp(source), parse_timestamp(dest)), (Some(s), Some(d)) if s > d)
}

fn parse_timestamp(value: Option<&str>) -> Option<i64> {
    let value = value.filter(|v| !v.is_empty())?;
    let epoch = strtotime(value.as_bytes(), time(), &TzInfo::utc())?;
    (epoch > 0).then_some(epoch)
}
