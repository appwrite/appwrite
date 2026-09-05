//! Base SQL adapter. PHP `Utopia\Usage\Adapter\SQL`.

use crate::adapter::Adapter;
use crate::metric::Metric;

pub const COLLECTION: &str = "usage";

pub trait SqlAdapter: Adapter {
    fn get_collection_name(&self) -> &'static str {
        COLLECTION
    }

    fn get_event_attributes(&self) -> Vec<serde_json::Map<String, serde_json::Value>> {
        Metric::get_event_schema()
    }

    fn get_gauge_attributes(&self) -> Vec<serde_json::Map<String, serde_json::Value>> {
        Metric::get_gauge_schema()
    }

    fn get_attribute(
        &self,
        id: &str,
        type_: &str,
    ) -> Option<serde_json::Map<String, serde_json::Value>> {
        let attrs = if type_ == "gauge" {
            self.get_gauge_attributes()
        } else {
            self.get_event_attributes()
        };
        attrs
            .into_iter()
            .find(|a| a.get("$id").and_then(serde_json::Value::as_str) == Some(id))
    }
}
