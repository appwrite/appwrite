use std::collections::HashMap;
use std::sync::OnceLock;

/// Public Suffix List section a rule belongs to.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum SuffixKind {
    /// ICANN-managed suffix.
    Icann,
    /// Private / registry-reserved suffix.
    Private,
}

impl SuffixKind {
    /// PHP list value `type` string (`ICANN` / `PRIVATE`).
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Icann => "ICANN",
            Self::Private => "PRIVATE",
        }
    }
}

/// Embedded Public Suffix List (suffix / exception / wildcard → section).
///
/// Loaded once from `data/psl.json` (converted from PHP `data/data.php`).
pub fn psl_list() -> &'static HashMap<String, SuffixKind> {
    static PSL: OnceLock<HashMap<String, SuffixKind>> = OnceLock::new();
    PSL.get_or_init(|| {
        let raw = include_str!("../data/psl.json");
        let parsed: HashMap<String, String> =
            serde_json::from_str(raw).expect("embedded data/psl.json must be valid JSON");
        parsed
            .into_iter()
            .filter_map(|(suffix, kind)| {
                let kind = match kind.as_str() {
                    "ICANN" => SuffixKind::Icann,
                    "PRIVATE" => SuffixKind::Private,
                    _ => return None,
                };
                Some((suffix, kind))
            })
            .collect()
    })
}
