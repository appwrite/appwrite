//! Hooks: callbacks with declared params, injections, groups and labels.
//!
//! PHP: `Utopia\Servers\Hook`.

mod action;
mod callback;
mod into_result;
mod param;
mod scope;

use indexmap::IndexMap;
use serde_json::Value;

pub use action::{Action, BoxFuture, action};
pub use callback::Callback;
pub use into_result::IntoResult;
pub use param::{Check, Default, DefaultFactory, Param, ValidatorFactory};
pub use scope::Scope;

/// A callback with its declared params, injections, groups and labels.
///
/// PHP: `Utopia\Servers\Hook`.
#[derive(Clone, Default)]
pub struct Hook {
    desc: String,
    groups: Vec<String>,
    labels: IndexMap<String, Value>,
    params: IndexMap<String, Param>,
    injections: Vec<String>,
    action: Option<Action>,
}

impl Hook {
    /// An empty hook: no groups, params, injections or action.
    pub fn new() -> Self {
        Self::default()
    }

    pub(crate) fn with_groups(groups: &[&str]) -> Self {
        Self { groups: groups.iter().map(|g| (*g).to_owned()).collect(), ..Self::default() }
    }

    /// Sets the description.
    ///
    /// PHP: `desc($desc)`.
    pub fn desc(&mut self, desc: &str) -> &mut Self {
        self.desc = desc.to_owned();
        self
    }

    /// The description.
    ///
    /// PHP: `getDesc()`.
    pub fn description(&self) -> &str {
        &self.desc
    }

    /// Sets the groups, replacing the previous ones. Hooks run for routes
    /// that share one of their groups; `*` matches every route.
    ///
    /// PHP: `groups($groups)`.
    pub fn groups(&mut self, groups: &[&str]) -> &mut Self {
        self.groups = groups.iter().map(|g| (*g).to_owned()).collect();
        self
    }

    /// The groups.
    ///
    /// PHP: `getGroups()`.
    pub fn get_groups(&self) -> &[String] {
        &self.groups
    }

    /// Sets a label: free-form metadata under `key`.
    ///
    /// PHP: `label($key, $value)`.
    pub fn label(&mut self, key: &str, value: Value) -> &mut Self {
        self.labels.insert(key.to_owned(), value);
        self
    }

    /// The label under `key`; `None` when it is missing or `null`.
    ///
    /// PHP: `getLabel($key, $default)`.
    pub fn get_label(&self, key: &str) -> Option<&Value> {
        self.labels.get(key).filter(|v| !v.is_null())
    }

    /// Declares a param under `key`, replacing one of the same key.
    ///
    /// PHP: `param($key, ...)`.
    pub fn param(&mut self, key: &str, param: Param) -> &mut Self {
        self.params.insert(key.to_owned(), param);
        self
    }

    /// The declared params, in declaration order.
    ///
    /// PHP: `getParams()`.
    pub fn params(&self) -> &IndexMap<String, Param> {
        &self.params
    }

    /// Declares a resource to inject by name; a name already injected is kept
    /// once.
    ///
    /// PHP: `inject($name)`.
    pub fn inject(&mut self, name: &str) -> &mut Self {
        if !self.injections.iter().any(|i| i == name) {
            self.injections.push(name.to_owned());
        }
        self
    }

    /// The names of the declared injections, in order.
    ///
    /// PHP: `getInjections()`.
    pub fn injections(&self) -> &[String] {
        &self.injections
    }

    /// Sets the callback, an async closure that receives the call's
    /// [`Scope`].
    ///
    /// PHP: `action($callback)`.
    pub fn action<F, R>(&mut self, f: F) -> &mut Self
    where
        F: for<'a> Callback<'a> + AsyncFn(Scope<'_>) -> R + Send + Sync + 'static,
        R: IntoResult,
    {
        self.action = Some(action(f));
        self
    }

    /// Sets an already wrapped [`Action`].
    pub fn set_action(&mut self, action: Action) -> &mut Self {
        self.action = Some(action);
        self
    }

    /// The callback, if one is set.
    ///
    /// PHP: `getAction()`.
    pub fn get_action(&self) -> Option<&Action> {
        self.action.as_ref()
    }

    pub(crate) fn in_group(&self, group: &str) -> bool {
        self.groups.iter().any(|g| g == group)
    }
}
