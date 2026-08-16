//! `Utopia\View\View` - template rendering, params, filters, and child views.

use std::collections::HashMap;
use std::fmt;
use std::sync::Arc;

use parking_lot::Mutex;
use serde_json::{Map, Value};

use crate::error::ViewError;
use crate::escape::{htmlentities, htmlspecialchars, minify, nl2p};
use crate::template::render_template;

/// Named output filter, matching PHP `string|array $filter`.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub enum PrintFilter {
    /// Empty string / empty array - PHP `empty($filter)` is true, so no filter is applied.
    #[default]
    None,
    /// A single registered filter name.
    Name(String),
    /// Filters applied left-to-right.
    Chain(Vec<String>),
}

impl From<&str> for PrintFilter {
    fn from(name: &str) -> Self {
        if php_empty_string(name) {
            Self::None
        } else {
            Self::Name(name.to_owned())
        }
    }
}

impl From<String> for PrintFilter {
    fn from(name: String) -> Self {
        Self::from(name.as_str())
    }
}

impl From<Vec<String>> for PrintFilter {
    fn from(names: Vec<String>) -> Self {
        if names.is_empty() {
            Self::None
        } else {
            Self::Chain(names)
        }
    }
}

impl From<Vec<&str>> for PrintFilter {
    fn from(names: Vec<&str>) -> Self {
        if names.is_empty() {
            Self::None
        } else {
            Self::Chain(names.into_iter().map(str::to_owned).collect())
        }
    }
}

impl From<&[&str]> for PrintFilter {
    fn from(names: &[&str]) -> Self {
        if names.is_empty() {
            Self::None
        } else {
            Self::Chain(names.iter().map(|s| (*s).to_owned()).collect())
        }
    }
}

impl<const N: usize> From<[&str; N]> for PrintFilter {
    fn from(names: [&str; N]) -> Self {
        Self::from(names.as_slice())
    }
}

fn php_empty_string(s: &str) -> bool {
    s.is_empty() || s == "0"
}

/// Argument to [`View::exec`], matching PHP `array|self $view`.
#[derive(Debug, Clone, Copy)]
pub enum ExecArg<'a> {
    /// Neither a `View` nor an array of views - PHP returns `''`.
    None,
    /// A single child view.
    One(&'a View),
    /// An array of child views.
    Many(&'a [View]),
}

impl<'a> From<&'a View> for ExecArg<'a> {
    fn from(view: &'a View) -> Self {
        Self::One(view)
    }
}

impl<'a> From<&'a [View]> for ExecArg<'a> {
    fn from(views: &'a [View]) -> Self {
        Self::Many(views)
    }
}

impl<'a> From<&'a Vec<View>> for ExecArg<'a> {
    fn from(views: &'a Vec<View>) -> Self {
        Self::Many(views.as_slice())
    }
}

type FilterFn = Arc<dyn Fn(Value) -> Value + Send + Sync>;

struct ViewInner {
    parent: Option<View>,
    path: String,
    rendered: bool,
    params: Map<String, Value>,
    filters: HashMap<String, FilterFn>,
}

/// PHP `Utopia\View\View`.
#[derive(Clone)]
pub struct View {
    inner: Arc<Mutex<ViewInner>>,
}

impl fmt::Debug for View {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        let inner = self.inner.lock();
        f.debug_struct("View")
            .field("path", &inner.path)
            .field("rendered", &inner.rendered)
            .field("params", &inner.params)
            .finish_non_exhaustive()
    }
}

impl Default for View {
    fn default() -> Self {
        Self::new("")
    }
}

impl View {
    /// PHP `FILTER_ESCAPE = 'escape'`.
    pub const FILTER_ESCAPE: &'static str = "escape";

    /// PHP `FILTER_NL2P = 'nl2p'`.
    pub const FILTER_NL2P: &'static str = "nl2p";

    /// PHP `__construct(string $path = '')`.
    #[must_use]
    pub fn new(path: impl Into<String>) -> Self {
        let view = Self {
            inner: Arc::new(Mutex::new(ViewInner {
                parent: None,
                path: String::new(),
                rendered: false,
                params: Map::new(),
                filters: HashMap::new(),
            })),
        };
        view.set_path(path);
        view.add_filter(Self::FILTER_ESCAPE, |value| {
            Value::String(htmlentities(&value_to_string(&value)))
        });
        view.add_filter(Self::FILTER_NL2P, |value| {
            Value::String(nl2p(&value_to_string(&value)))
        });
        view
    }

    /// PHP `setParam(string $key, mixed $value, bool $escape = true): static`.
    ///
    /// `$key` cannot contain `.`. String values are `htmlspecialchars`'d when `escape` is true.
    pub fn set_param(
        &self,
        key: impl Into<String>,
        value: impl Into<Value>,
        escape: bool,
    ) -> Result<&Self, ViewError> {
        let key = key.into();
        if key.contains('.') {
            return Err(ViewError::DottedKey);
        }
        let mut value = value.into();
        if escape {
            if let Value::String(raw) = value {
                value = Value::String(htmlspecialchars(&raw));
            }
        }
        self.inner.lock().params.insert(key, value);
        Ok(self)
    }

    /// PHP `setParent(self $view): static`.
    pub fn set_parent(&self, view: View) -> &Self {
        self.inner.lock().parent = Some(view);
        self
    }

    /// PHP `getParent(): ?self`.
    #[must_use]
    pub fn get_parent(&self) -> Option<View> {
        self.inner.lock().parent.clone()
    }

    /// PHP `getParam(string $path, mixed $default = null): mixed`.
    ///
    /// Dotted paths walk nested arrays/objects. `isset`-style: JSON `null` yields `$default`.
    #[must_use]
    pub fn get_param(&self, path: &str, default: impl Into<Value>) -> Value {
        let default = default.into();
        let inner = self.inner.lock();
        lookup_param(&inner.params, path).unwrap_or(default)
    }

    /// PHP `setPath(string $path): static`.
    pub fn set_path(&self, path: impl Into<String>) -> &Self {
        self.inner.lock().path = path.into();
        self
    }

    /// Template path last passed to [`Self::new`] or [`Self::set_path`].
    #[must_use]
    pub fn path(&self) -> String {
        self.inner.lock().path.clone()
    }

    /// PHP `setRendered(bool $state = true): static`.
    pub fn set_rendered(&self, state: bool) -> &Self {
        self.inner.lock().rendered = state;
        self
    }

    /// PHP `isRendered(): bool`.
    #[must_use]
    pub fn is_rendered(&self) -> bool {
        self.inner.lock().rendered
    }

    /// PHP `addFilter(string $name, callable $callback): static`.
    pub fn add_filter<F>(&self, name: impl Into<String>, callback: F) -> &Self
    where
        F: Fn(Value) -> Value + Send + Sync + 'static,
    {
        self.inner
            .lock()
            .filters
            .insert(name.into(), Arc::new(callback));
        self
    }

    /// PHP `print(mixed $value, string|array $filter = ''): mixed`.
    pub fn print(
        &self,
        value: impl Into<Value>,
        filter: impl Into<PrintFilter>,
    ) -> Result<Value, ViewError> {
        let mut value = value.into();
        let names = match filter.into() {
            PrintFilter::None => return Ok(value),
            PrintFilter::Name(name) => vec![name],
            PrintFilter::Chain(names) => names,
        };
        let callbacks = {
            let inner = self.inner.lock();
            let mut callbacks = Vec::with_capacity(names.len());
            for name in names {
                let callback = inner
                    .filters
                    .get(&name)
                    .cloned()
                    .ok_or_else(|| ViewError::FilterNotRegistered { name: name.clone() })?;
                callbacks.push(callback);
            }
            callbacks
        };
        for callback in callbacks {
            value = callback(value);
        }
        Ok(value)
    }

    /// PHP `render(bool $minify = true): string`.
    pub fn render(&self, minify_html: bool) -> Result<String, ViewError> {
        let (rendered, path) = {
            let inner = self.inner.lock();
            (inner.rendered, inner.path.clone())
        };
        if rendered {
            return Ok(String::new());
        }

        let source = read_template(&path)?;
        let html = render_template(&source, self)?;
        if minify_html {
            Ok(minify(&html))
        } else {
            Ok(html)
        }
    }

    /// PHP `exec($view): string` - render child [`View`] instances after `setParent($this)`.
    pub fn exec<'a>(&self, view: impl Into<ExecArg<'a>>) -> Result<String, ViewError> {
        match view.into() {
            ExecArg::None => Ok(String::new()),
            ExecArg::One(child) => {
                child.set_parent(self.clone());
                child.render(true)
            }
            ExecArg::Many(children) => {
                let mut output = String::new();
                for child in children {
                    child.set_parent(self.clone());
                    output.push_str(&child.render(true)?);
                }
                Ok(output)
            }
        }
    }
}

fn read_template(path: &str) -> Result<String, ViewError> {
    if path.is_empty() {
        return Err(ViewError::TemplateNotReadable {
            path: path.to_owned(),
        });
    }
    match std::fs::read_to_string(path) {
        Ok(contents) => Ok(contents),
        Err(_) => Err(ViewError::TemplateNotReadable {
            path: path.to_owned(),
        }),
    }
}

fn lookup_param(params: &Map<String, Value>, path: &str) -> Option<Value> {
    let mut parts = path.split('.');
    let first = parts.next()?;
    let mut current = match params.get(first) {
        Some(value) if !value.is_null() => value,
        _ => return None,
    };
    for key in parts {
        current = match current {
            Value::Object(map) => match map.get(key) {
                Some(value) if !value.is_null() => value,
                _ => return None,
            },
            Value::Array(arr) => {
                let idx = array_index(key)?;
                match arr.get(idx) {
                    Some(value) if !value.is_null() => value,
                    _ => return None,
                }
            }
            _ => return None,
        };
    }
    Some(current.clone())
}

fn array_index(key: &str) -> Option<usize> {
    if key == "0" {
        Some(0)
    } else if key.is_empty() || key.starts_with('0') {
        None
    } else {
        key.parse().ok()
    }
}

/// PHP string cast used by echo / default filters.
pub(crate) fn value_to_string(value: &Value) -> String {
    match value {
        Value::Null | Value::Bool(false) => String::new(),
        Value::Bool(true) => "1".to_owned(),
        Value::Number(n) => n.to_string(),
        Value::String(s) => s.clone(),
        Value::Array(_) | Value::Object(_) => "Array".to_owned(),
    }
}

/// PHP boolean conversion for `if ($this->getParam(...))`.
pub(crate) fn php_truthy(value: &Value) -> bool {
    match value {
        Value::Null => false,
        Value::Bool(v) => *v,
        Value::Number(n) => {
            if let Some(i) = n.as_i64() {
                i != 0
            } else if let Some(u) = n.as_u64() {
                u != 0
            } else {
                n.as_f64().is_some_and(|f| f != 0.0 && !f.is_nan())
            }
        }
        Value::String(s) => !s.is_empty() && s != "0",
        Value::Array(a) => !a.is_empty(),
        Value::Object(o) => !o.is_empty(),
    }
}
