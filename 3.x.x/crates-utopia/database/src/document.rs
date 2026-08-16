//! PHP `Utopia\Database\Document`.

use indexmap::IndexMap;
use serde_json::{Map, Value};

use crate::constants::{
    INTERNAL_ATTRIBUTES, PERMISSION_CREATE, PERMISSION_DELETE, PERMISSION_READ, PERMISSION_UPDATE,
};
use crate::error::{DatabaseError, Result};
use crate::value::AttrValue;

/// PHP `Document::SET_TYPE_*`.
pub const SET_TYPE_ASSIGN: &str = "assign";
pub const SET_TYPE_PREPEND: &str = "prepend";
pub const SET_TYPE_APPEND: &str = "append";

/// PHP `Utopia\Database\Document` (`ArrayObject`).
#[derive(Debug, Clone, PartialEq)]
pub struct Document {
    data: IndexMap<String, AttrValue>,
}

impl Default for Document {
    fn default() -> Self {
        Self::new()
    }
}

impl Document {
    #[must_use]
    pub fn new() -> Self {
        Self {
            data: IndexMap::new(),
        }
    }

    /// PHP `__construct(array $input = [])`.
    pub fn from_map(mut input: IndexMap<String, AttrValue>) -> Result<Self> {
        if let Some(id) = input.get("$id") {
            if !matches!(id, AttrValue::String(_)) && !id.is_null() {
                return Err(DatabaseError::structure("$id must be of type string"));
            }
        }
        if let Some(perms) = input.get("$permissions") {
            if !matches!(perms, AttrValue::Array(_) | AttrValue::Null) {
                return Err(DatabaseError::structure(
                    "$permissions must be of type array",
                ));
            }
        }

        wrap_nested_documents(&mut input);
        Ok(Self { data: input })
    }

    pub fn try_from_json(value: Value) -> Result<Self> {
        match value {
            Value::Object(obj) => Self::try_from_json_object(obj),
            Value::Null => Ok(Self::new()),
            _ => Err(DatabaseError::structure(
                "$permissions must be of type array",
            )),
        }
    }

    pub fn try_from_json_object(obj: Map<String, Value>) -> Result<Self> {
        let mut map = IndexMap::new();
        for (k, v) in obj {
            map.insert(k, AttrValue::from_json(v));
        }
        Self::from_map(map)
    }

    /// Convenience constructor from JSON-like pairs.
    pub fn from_pairs<I, K>(pairs: I) -> Result<Self>
    where
        I: IntoIterator<Item = (K, AttrValue)>,
        K: Into<String>,
    {
        let mut map = IndexMap::new();
        for (k, v) in pairs {
            map.insert(k.into(), v);
        }
        Self::from_map(map)
    }

    #[must_use]
    pub fn get_id(&self) -> String {
        match self.get_attribute("$id") {
            AttrValue::String(s) => s.clone(),
            _ => String::new(),
        }
    }

    #[must_use]
    pub fn get_sequence(&self) -> Option<String> {
        if !self.is_set("$sequence") {
            return None;
        }
        match self.get_attribute("$sequence") {
            AttrValue::String(s) => Some(s.clone()),
            AttrValue::Number(n) => Some(n.to_string()),
            AttrValue::Null => None,
            other => Some(other_to_string(other)),
        }
    }

    #[must_use]
    pub fn get_collection(&self) -> String {
        match self.get_attribute("$collection") {
            AttrValue::String(s) => s.clone(),
            _ => String::new(),
        }
    }

    #[must_use]
    pub fn get_permissions(&self) -> Vec<String> {
        let Some(arr) = self.get_attribute("$permissions").as_array() else {
            return Vec::new();
        };
        let mut out = Vec::new();
        for v in arr.values() {
            if let Some(s) = v.as_str() {
                if !out.iter().any(|x: &String| x == s) {
                    out.push(s.to_owned());
                }
            }
        }
        out
    }

    #[must_use]
    pub fn get_read(&self) -> Vec<String> {
        self.get_permissions_by_type(PERMISSION_READ)
    }

    #[must_use]
    pub fn get_create(&self) -> Vec<String> {
        self.get_permissions_by_type(PERMISSION_CREATE)
    }

    #[must_use]
    pub fn get_update(&self) -> Vec<String> {
        self.get_permissions_by_type(PERMISSION_UPDATE)
    }

    #[must_use]
    pub fn get_delete(&self) -> Vec<String> {
        self.get_permissions_by_type(PERMISSION_DELETE)
    }

    #[must_use]
    pub fn get_write(&self) -> Vec<String> {
        let create = self.get_create();
        let update = self.get_update();
        let delete = self.get_delete();
        let mut out = Vec::new();
        for role in &create {
            if update.contains(role) && delete.contains(role) && !out.contains(role) {
                out.push(role.clone());
            }
        }
        out
    }

    #[must_use]
    pub fn get_permissions_by_type(&self, perm_type: &str) -> Vec<String> {
        let prefix = format!("{perm_type}(");
        let mut out = Vec::new();
        for permission in self.get_permissions() {
            if !permission.starts_with(perm_type) {
                continue;
            }
            let cleaned = permission.replace(&prefix, "").replace([')', '"', ' '], "");
            if !out.contains(&cleaned) {
                out.push(cleaned);
            }
        }
        out
    }

    #[must_use]
    pub fn get_created_at(&self) -> Option<String> {
        match self.get_attribute("$createdAt") {
            AttrValue::Null => None,
            AttrValue::String(s) => Some(s.clone()),
            other => Some(other_to_string(other)),
        }
    }

    #[must_use]
    pub fn get_updated_at(&self) -> Option<String> {
        match self.get_attribute("$updatedAt") {
            AttrValue::Null => None,
            AttrValue::String(s) => Some(s.clone()),
            other => Some(other_to_string(other)),
        }
    }

    #[must_use]
    pub fn get_tenant(&self) -> Option<AttrValue> {
        let tenant = self.get_attribute("$tenant").clone();
        match &tenant {
            AttrValue::Null => None,
            AttrValue::Number(n) => Some(AttrValue::Number(n.clone())),
            AttrValue::String(s) if s.parse::<i64>().is_ok() || s.parse::<u64>().is_ok() => {
                if let Ok(i) = s.parse::<i64>() {
                    Some(AttrValue::from(i))
                } else {
                    Some(tenant)
                }
            }
            _ => Some(tenant),
        }
    }

    #[must_use]
    pub fn get_attributes(&self) -> IndexMap<String, AttrValue> {
        let internal: Vec<String> = INTERNAL_ATTRIBUTES
            .iter()
            .map(|a| (*a.get("$id").and_then(Value::as_str).unwrap_or("")).to_owned())
            .collect();
        let mut out = IndexMap::new();
        for (k, v) in &self.data {
            if internal.iter().any(|i| i == k) {
                continue;
            }
            out.insert(k.clone(), v.clone());
        }
        out
    }

    /// PHP `getAttribute`. Missing or null (`isset` false) yields `default`.
    #[must_use]
    pub fn get_attribute_or<'a>(&'a self, name: &str, default: &'a AttrValue) -> &'a AttrValue {
        match self.data.get(name) {
            Some(v) if v.is_set() => v,
            _ => default,
        }
    }

    #[must_use]
    pub fn get_attribute(&self, name: &str) -> &AttrValue {
        static NULL: AttrValue = AttrValue::Null;
        self.data.get(name).unwrap_or(&NULL)
    }

    pub fn get_attribute_mut(&mut self, name: &str) -> Option<&mut AttrValue> {
        self.data.get_mut(name)
    }

    pub fn set_attribute(
        &mut self,
        key: impl Into<String>,
        value: impl Into<AttrValue>,
    ) -> &mut Self {
        self.set_attribute_typed(key, value, SET_TYPE_ASSIGN)
    }

    pub fn set_attribute_typed(
        &mut self,
        key: impl Into<String>,
        value: impl Into<AttrValue>,
        set_type: &str,
    ) -> &mut Self {
        let key = key.into();
        let value = value.into();
        match set_type {
            SET_TYPE_APPEND => {
                let entry = self
                    .data
                    .entry(key)
                    .or_insert_with(|| AttrValue::Array(IndexMap::new()));
                if !matches!(entry, AttrValue::Array(_)) {
                    *entry = AttrValue::Array(IndexMap::new());
                }
                entry.push(value);
            }
            SET_TYPE_PREPEND => {
                let entry = self
                    .data
                    .entry(key)
                    .or_insert_with(|| AttrValue::Array(IndexMap::new()));
                if !matches!(entry, AttrValue::Array(_)) {
                    *entry = AttrValue::Array(IndexMap::new());
                }
                entry.prepend(value);
            }
            _ => {
                self.data.insert(key, value);
            }
        }
        self
    }

    pub fn set_attributes(&mut self, attributes: IndexMap<String, AttrValue>) -> &mut Self {
        for (key, value) in attributes {
            self.set_attribute(key, value);
        }
        self
    }

    pub fn remove_attribute(&mut self, key: &str) -> &mut Self {
        self.data.shift_remove(key);
        self
    }

    /// PHP `find`. Returns the matching value, or `Null` (PHP `false`).
    #[must_use]
    pub fn find(&self, key: &str, find: &AttrValue, subject: &str) -> AttrValue {
        let subject_val = if subject.is_empty() {
            None
        } else {
            self.data.get(subject)
        };
        let use_self = subject_val.is_none() || subject_val.is_some_and(AttrValue::is_php_empty);
        if use_self {
            if let Some(v) = self.data.get(key) {
                if v == find {
                    return AttrValue::Document(Box::new(self.clone()));
                }
            }
            return AttrValue::Null;
        }
        let Some(AttrValue::Array(items)) = subject_val else {
            if let Some(AttrValue::Document(doc)) = subject_val {
                if let Some(v) = doc.data.get(key) {
                    if v == find {
                        return AttrValue::Document(doc.clone());
                    }
                }
            }
            return AttrValue::Null;
        };
        for value in items.values() {
            match value {
                AttrValue::Document(doc) => {
                    if let Some(v) = doc.data.get(key) {
                        if v == find {
                            return AttrValue::Document(doc.clone());
                        }
                    }
                }
                AttrValue::Array(map) => {
                    if let Some(v) = map.get(key) {
                        if v == find {
                            return value.clone();
                        }
                    }
                }
                _ => {}
            }
        }
        AttrValue::Null
    }

    pub fn find_and_replace(
        &mut self,
        key: &str,
        find: &AttrValue,
        replace: AttrValue,
        subject: &str,
    ) -> bool {
        if subject.is_empty() {
            if let Some(v) = self.data.get(key) {
                if v == find {
                    self.data.insert(key.to_owned(), replace);
                    return true;
                }
            }
            return false;
        }
        let Some(slot) = self.data.get_mut(subject) else {
            return false;
        };
        if slot.is_php_empty() {
            return false;
        }
        match slot {
            AttrValue::Array(items) => {
                for value in items.values_mut() {
                    match value {
                        AttrValue::Document(doc) => {
                            if let Some(v) = doc.data.get(key) {
                                if v == find {
                                    *value = replace;
                                    return true;
                                }
                            }
                        }
                        AttrValue::Array(map) => {
                            if let Some(v) = map.get(key) {
                                if v == find {
                                    *value = replace;
                                    return true;
                                }
                            }
                        }
                        _ => {}
                    }
                }
                false
            }
            AttrValue::Document(doc) => {
                if let Some(v) = doc.data.get(key) {
                    if v == find {
                        doc.data.insert(key.to_owned(), replace);
                        return true;
                    }
                }
                false
            }
            other => {
                if let AttrValue::Array(map) = other {
                    if let Some(v) = map.get(key) {
                        if v == find {
                            map.insert(key.to_owned(), replace);
                            return true;
                        }
                    }
                }
                false
            }
        }
    }

    pub fn find_and_remove(&mut self, key: &str, find: &AttrValue, subject: &str) -> bool {
        if subject.is_empty() {
            if let Some(v) = self.data.get(key) {
                if v == find {
                    self.data.shift_remove(key);
                    return true;
                }
            }
            return false;
        }
        let Some(slot) = self.data.get_mut(subject) else {
            return false;
        };
        if slot.is_php_empty() {
            return false;
        }
        match slot {
            AttrValue::Array(items) => {
                let mut remove_key = None;
                for (i, value) in items.iter() {
                    let matched = match value {
                        AttrValue::Document(doc) => doc.data.get(key).is_some_and(|v| v == find),
                        AttrValue::Array(map) => map.get(key).is_some_and(|v| v == find),
                        _ => false,
                    };
                    if matched {
                        remove_key = Some(i.clone());
                        break;
                    }
                }
                if let Some(k) = remove_key {
                    items.shift_remove(&k);
                    return true;
                }
                false
            }
            AttrValue::Document(doc) => {
                if doc.data.get(key).is_some_and(|v| v == find) {
                    doc.data.shift_remove(key);
                    return true;
                }
                false
            }
            _ => false,
        }
    }

    #[must_use]
    pub fn is_empty(&self) -> bool {
        self.data.is_empty()
    }

    #[must_use]
    pub fn is_set(&self, key: &str) -> bool {
        self.data.get(key).is_some_and(AttrValue::is_set)
    }

    #[must_use]
    pub fn len(&self) -> usize {
        self.data.len()
    }

    #[must_use]
    pub fn iter(&self) -> indexmap::map::Iter<'_, String, AttrValue> {
        self.data.iter()
    }

    pub fn iter_mut(&mut self) -> indexmap::map::IterMut<'_, String, AttrValue> {
        self.data.iter_mut()
    }

    #[must_use]
    pub fn get_array_copy(&self, allow: &[&str], disallow: &[&str]) -> IndexMap<String, AttrValue> {
        let mut output = IndexMap::new();
        for (key, value) in &self.data {
            if !allow.is_empty() && !allow.contains(&key.as_str()) {
                continue;
            }
            if !disallow.is_empty() && disallow.contains(&key.as_str()) {
                continue;
            }
            output.insert(key.clone(), copy_value(value, allow, disallow));
        }
        output
    }

    #[must_use]
    pub fn get_array_copy_json(&self, allow: &[&str], disallow: &[&str]) -> Map<String, Value> {
        let mut output = Map::new();
        for (key, value) in self.get_array_copy(allow, disallow) {
            output.insert(key, value.to_json());
        }
        output
    }

    #[must_use]
    pub fn as_map(&self) -> &IndexMap<String, AttrValue> {
        &self.data
    }

    pub fn as_map_mut(&mut self) -> &mut IndexMap<String, AttrValue> {
        &mut self.data
    }

    pub fn insert(&mut self, key: impl Into<String>, value: impl Into<AttrValue>) {
        self.data.insert(key.into(), value.into());
    }
}

fn copy_value(value: &AttrValue, allow: &[&str], disallow: &[&str]) -> AttrValue {
    match value {
        AttrValue::Document(d) => AttrValue::Array(d.get_array_copy(allow, disallow)),
        AttrValue::Array(items) => {
            let mut out = IndexMap::new();
            for (k, child) in items {
                out.insert(k.clone(), copy_value(child, allow, disallow));
            }
            AttrValue::Array(out)
        }
        other => other.clone(),
    }
}

fn wrap_nested_documents(input: &mut IndexMap<String, AttrValue>) {
    for value in input.values_mut() {
        wrap_value(value);
    }
}

fn wrap_value(value: &mut AttrValue) {
    match value {
        AttrValue::Array(items) => {
            let looks_like_doc = items.contains_key("$id") || items.contains_key("$collection");
            if looks_like_doc {
                let mut map = IndexMap::new();
                std::mem::swap(items, &mut map);
                if let Ok(doc) = Document::from_map(map) {
                    *value = AttrValue::Document(Box::new(doc));
                    return;
                }
            }
            for child in items.values_mut() {
                wrap_value(child);
            }
        }
        AttrValue::Document(doc) => {
            wrap_nested_documents(&mut doc.data);
        }
        _ => {}
    }
}

fn other_to_string(value: &AttrValue) -> String {
    match value {
        AttrValue::String(s) => s.clone(),
        AttrValue::Number(n) => n.to_string(),
        AttrValue::Bool(true) => "1".into(),
        AttrValue::Bool(false) => String::new(),
        AttrValue::Null => String::new(),
        other => other.to_json().to_string(),
    }
}

impl From<IndexMap<String, AttrValue>> for Document {
    fn from(value: IndexMap<String, AttrValue>) -> Self {
        Self::from_map(value).unwrap_or_default()
    }
}
