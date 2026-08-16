pub mod get;
pub mod update;

pub(crate) fn prefs_of(user: &utopia_database::Document) -> serde_json::Value {
    crate::state::document_to_json(user)
        .get("prefs")
        .cloned()
        .unwrap_or_else(|| serde_json::json!({}))
}
