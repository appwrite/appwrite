//! `Utopia\Http\View`: templates with escaped params, filters, nesting and
//! HTML minification.
//!
//! PHP templates are `.phtml` files executed with the view as `$this`. A Rust
//! template is a [`Template`] function that writes the output; a file path
//! without one renders the file's content as is (a template without PHP code).

use std::sync::Arc;

use indexmap::IndexMap;
use php_std::encoding::HtmlFlags;
use serde_json::Value;

use crate::error::{Error, Result};

/// `View::FILTER_ESCAPE`.
pub const FILTER_ESCAPE: &str = "escape";
/// `View::FILTER_NL2P`.
pub const FILTER_NL2P: &str = "nl2p";

/// A filter: a string transformation registered by name.
pub type Filter = Arc<dyn Fn(&str) -> String + Send + Sync>;

/// A template: renders a view into `out`.
pub type Template = Arc<dyn Fn(&View, &mut String) -> Result<()> + Send + Sync>;

/// A view.
#[derive(Clone)]
pub struct View {
    parent: Option<Box<View>>,
    path: String,
    rendered: bool,
    params: IndexMap<String, Value>,
    filters: IndexMap<String, Filter>,
    template: Option<Template>,
}

impl std::fmt::Debug for View {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("View")
            .field("path", &self.path)
            .field("rendered", &self.rendered)
            .field("params", &self.params)
            .finish()
    }
}

/// `ENT_QUOTES` with UTF-8.
const QUOTES: i64 = 3;

impl View {
    /// `new View($path)`, with the `escape` and `nl2p` filters.
    pub fn new(path: &str) -> Self {
        let mut view = Self {
            parent: None,
            path: path.to_owned(),
            rendered: false,
            params: IndexMap::new(),
            filters: IndexMap::new(),
            template: None,
        };
        view.add_filter(
            FILTER_ESCAPE,
            Arc::new(|v: &str| {
                String::from_utf8_lossy(&php_std::encoding::htmlentities(
                    v.as_bytes(),
                    HtmlFlags::from_php(QUOTES),
                    true,
                ))
                .into_owned()
            }),
        );
        view.add_filter(FILTER_NL2P, Arc::new(nl2p));
        view
    }

    /// Renders with `template` instead of reading the path.
    pub fn with_template(mut self, template: Template) -> Self {
        self.template = Some(template);
        self
    }

    /// `setParam($key, $value, $escapeHtml)`: strings are HTML-escaped unless `escape` is false.
    pub fn set_param(&mut self, key: &str, value: Value, escape: bool) -> Result<&mut Self> {
        if key.contains('.') {
            return Err(Error::Generic("$key can't contain a dot \".\" character".into()));
        }
        let value = match value {
            Value::String(s) if escape => Value::String(
                String::from_utf8_lossy(&php_std::encoding::htmlspecialchars(
                    s.as_bytes(),
                    HtmlFlags::from_php(QUOTES),
                    true,
                ))
                .into_owned(),
            ),
            other => other,
        };
        self.params.insert(key.to_owned(), value);
        Ok(self)
    }

    /// `setParent($view)`.
    pub fn set_parent(&mut self, parent: View) -> &mut Self {
        self.parent = Some(Box::new(parent));
        self
    }

    /// `getParent()`.
    pub fn parent(&self) -> Option<&View> {
        self.parent.as_deref()
    }

    /// `getParam($path, $default)`: a dotted path into the params; `None`
    /// when a step is missing or `null`.
    pub fn param(&self, path: &str) -> Option<Value> {
        let mut current: Option<Value> = None;
        for (i, key) in path.split('.').enumerate() {
            let next = if i == 0 {
                self.params.get(key).cloned()
            } else {
                match current.as_ref()? {
                    Value::Object(m) => m.get(key).cloned(),
                    Value::Array(a) => php_std::zval::numeric_key(key.as_bytes())
                        .and_then(|i| usize::try_from(i).ok())
                        .and_then(|i| a.get(i).cloned()),
                    Value::String(s) => php_std::zval::numeric_key(key.as_bytes()).and_then(|i| {
                        let bytes = s.as_bytes();
                        let len = bytes.len() as i64;
                        let at = if i < 0 { len + i } else { i };
                        (0..len).contains(&at).then(|| {
                            Value::String(String::from_utf8_lossy(&bytes[at as usize..=at as usize]).into_owned())
                        })
                    }),
                    _ => None,
                }
            };
            match next {
                Some(v) if !v.is_null() => current = Some(v),
                _ => return None,
            }
        }
        current
    }

    /// `setPath($path)`.
    pub fn set_path(&mut self, path: &str) -> &mut Self {
        self.path = path.to_owned();
        self
    }

    /// The template path.
    pub fn path(&self) -> &str {
        &self.path
    }

    /// `setRendered($state)`.
    pub fn set_rendered(&mut self, state: bool) -> &mut Self {
        self.rendered = state;
        self
    }

    /// `isRendered()`.
    pub fn is_rendered(&self) -> bool {
        self.rendered
    }

    /// `addFilter($name, $callback)`.
    pub fn add_filter(&mut self, name: &str, filter: Filter) -> &mut Self {
        self.filters.insert(name.to_owned(), filter);
        self
    }

    /// `print($value, $filter)`: applies the named filters in order.
    pub fn print(&self, value: &str, filters: &[&str]) -> Result<String> {
        let mut value = value.to_owned();
        for name in filters {
            let filter = self
                .filters
                .get(*name)
                .ok_or_else(|| Error::Generic(format!("Filter \"{name}\" is not registered")))?;
            value = filter(&value);
        }
        Ok(value)
    }

    /// `render($minify)`.
    pub fn render(&self, minify: bool) -> Result<String> {
        if self.rendered {
            return Ok(String::new());
        }
        let mut html = String::new();
        match &self.template {
            Some(template) => template(self, &mut html)?,
            None => match std::fs::read(&self.path) {
                Ok(bytes) if !std::path::Path::new(&self.path).is_dir() => {
                    html = String::from_utf8_lossy(&bytes).into_owned()
                }
                _ => return Err(Error::Generic(format!("\"{}\" view template is not readable", self.path))),
            },
        }
        if minify {
            html = minify_html(&html);
        }
        Ok(html)
    }

    /// `exec($view)`: renders child views with this one as their parent.
    pub fn exec(&self, views: &mut [View]) -> Result<String> {
        let mut out = String::new();
        for view in views.iter_mut() {
            view.set_parent(self.clone());
            out.push_str(&view.render(true)?);
        }
        Ok(out)
    }
}

/// The `nl2p` filter: paragraphs for blank-line separated blocks, `<br />` for line breaks.
fn nl2p(value: &str) -> String {
    let mut paragraphs = String::new();
    for line in value.split("\n\n") {
        let trimmed = php_std::string::trim(line.as_bytes(), b" \t\n\r\0\x0B");
        if !trimmed.is_empty() && trimmed != b"0" {
            paragraphs.push_str("<p>");
            paragraphs.push_str(line);
            paragraphs.push_str("</p>");
        }
    }
    paragraphs.replace('\n', "<br />")
}

/// `View::render()`'s minifier: collapses whitespace around tags and runs of
/// whitespace, keeping `<textarea>` and `<pre>` blocks intact.
pub fn minify_html(html: &str) -> String {
    use php_std::pcre::{StrOrArray, Value as P};

    let matches = |pattern: &[u8], subject: &[u8]| -> Vec<Vec<u8>> {
        let Ok(result) = php_std::pcre::preg_match_all(pattern, subject, php_std::pcre::PREG_PATTERN_ORDER, 0) else {
            return Vec::new();
        };
        let Some(matches) = result.value.matches else { return Vec::new() };
        match matches.get(&php_std::pcre::Key::Int(0)) {
            Some(P::Array(all)) => all
                .entries()
                .iter()
                .filter_map(|(_, v)| if let P::Str(s) = v { Some(s.clone()) } else { None })
                .collect(),
            _ => Vec::new(),
        }
    };
    let mut out = html.as_bytes().to_vec();
    let textareas = matches(br"#\<textarea.*\>.*\<\/textarea\>#Uis", &out);
    let pres = matches(br"#\<pre.*\>.*\<\/pre\>#Uis", &out);
    let marks =
        |tag: &str, n: usize| -> Vec<Vec<u8>> { (0..n).map(|i| format!("<{tag}>{i}</{tag}>").into_bytes()).collect() };
    let swap = |subject: Vec<u8>, from: &[Vec<u8>], to: &[Vec<u8>]| -> Vec<u8> {
        let search: Vec<&[u8]> = from.iter().map(Vec::as_slice).collect();
        let replace: Vec<&[u8]> = to.iter().map(Vec::as_slice).collect();
        php_std::string::str_replace_array(&search, php_std::string::Replace::Each(&replace), &subject, false)
            .0
            .into_owned()
    };
    let text_marks = marks("textarea", textareas.len());
    let pre_marks = marks("pre", pres.len());
    out = swap(out, &textareas, &text_marks);
    out = swap(out, &pres, &pre_marks);

    let patterns: Vec<(php_std::pcre::Key, Vec<u8>)> = [&br"/\>[^\S ]+/s"[..], br"/[^\S ]+\</s", br"/(\s)+/s"]
        .iter()
        .enumerate()
        .map(|(i, p)| (php_std::pcre::Key::Int(i as i64), p.to_vec()))
        .collect();
    let replacements: Vec<(php_std::pcre::Key, Vec<u8>)> = [&b">"[..], b"<", br"\1"]
        .iter()
        .enumerate()
        .map(|(i, p)| (php_std::pcre::Key::Int(i as i64), p.to_vec()))
        .collect();
    if let Ok(result) = php_std::pcre::preg_replace(
        &StrOrArray::Array(patterns),
        &StrOrArray::Array(replacements),
        &StrOrArray::Str(&out),
        -1,
    ) && let P::Str(s) = result.value.result
    {
        out = s;
    }

    out = swap(out, &text_marks, &textareas);
    out = swap(out, &pre_marks, &pres);
    String::from_utf8_lossy(&out).into_owned()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn minifies() {
        assert_eq!(minify_html("<div>\n  <p> a  b </p>\n</div>"), "<div> <p> a b </p></div>");
        assert_eq!(nl2p("a\nb\n\nc"), "<p>a<br />b</p><p>c</p>");
    }
}
