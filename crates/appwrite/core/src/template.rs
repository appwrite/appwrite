//! Email and SMS templates and the links they carry
//! (`Appwrite\Template\Template`).

use php_std::encoding::{HtmlFlags, QueryEncoding};
use serde_json::{Map, Value};

/// A template and its `{{param}}` values, in the order they were set.
#[derive(Debug, Clone, Default)]
pub struct Template {
    content: String,
    params: Vec<(String, String)>,
}

impl Template {
    pub fn new(content: impl Into<String>) -> Self {
        Self { content: content.into(), params: Vec::new() }
    }

    /// `setParam($key, $value, $escapeHtml)`: escaped with
    /// `htmlspecialchars($value, ENT_QUOTES)` unless `escape` is false.
    pub fn param(mut self, key: &str, value: &str, escape: bool) -> Self {
        let value = if escape { escape_html(value) } else { value.to_owned() };
        match self.params.iter_mut().find(|(k, _)| k == key) {
            Some(p) => p.1 = value,
            None => self.params.push((key.to_owned(), value)),
        }
        self
    }

    /// `render()`: the params' values first have every param replaced in
    /// them, then each param is replaced in the template, in order (PHP's
    /// `str_replace` with arrays).
    pub fn render(&self) -> String {
        let values: Vec<String> = self.params.iter().map(|(_, v)| replace_all(v, &self.params)).collect();
        let params: Vec<(String, String)> = self.params.iter().zip(values).map(|((k, _), v)| (k.clone(), v)).collect();
        replace_all(&self.content, &params)
    }
}

fn replace_all(subject: &str, params: &[(String, String)]) -> String {
    let mut out = subject.to_owned();
    for (key, value) in params {
        if !key.is_empty() && out.contains(key.as_str()) {
            out = out.replace(key.as_str(), value);
        }
    }
    out
}

/// `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')`.
pub fn escape_html(value: &str) -> String {
    String::from_utf8_lossy(&php_std::encoding::htmlspecialchars(value.as_bytes(), HtmlFlags::from_php(3), true))
        .into_owned()
}

/// `Template::unParseURL(parse_url($url))` with `$extra` merged into its
/// query (`Template::mergeQuery`): the link an email or SMS carries.
pub fn with_query(url: &str, extra: &[(&str, &str)]) -> String {
    let parsed = php_std::url::parse_url(url.as_bytes());
    let part = |c: Option<std::borrow::Cow<'_, [u8]>>| c.map(|b| String::from_utf8_lossy(&b).into_owned());
    let (scheme, host, port, user, pass, path, query, fragment) = match &parsed {
        Some(u) => (
            part(u.scheme()),
            part(u.host()),
            u.port(),
            part(u.user()),
            part(u.pass()),
            part(u.path()),
            part(u.query()),
            part(u.fragment()),
        ),
        None => (None, None, None, None, None, None, None, None),
    };
    let merged = merge_query(query.as_deref().unwrap_or(""), extra);

    let mut out = String::new();
    if let Some(scheme) = scheme {
        out.push_str(&scheme);
        out.push_str("://");
    }
    let user = user.unwrap_or_default();
    let pass = pass.map(|p| format!(":{p}")).unwrap_or_default();
    out.push_str(&user);
    if !user.is_empty() || !pass.is_empty() {
        out.push_str(&pass);
        out.push('@');
    }
    out.push_str(&host.unwrap_or_default());
    if let Some(port) = port {
        out.push_str(&format!(":{port}"));
    }
    out.push_str(&path.unwrap_or_default());
    if !merged.is_empty() {
        out.push('?');
        out.push_str(&merged);
    }
    if let Some(fragment) = fragment {
        out.push('#');
        out.push_str(&fragment);
    }
    out
}

/// `Template::mergeQuery($query, $extra)`: `parse_str`, `array_merge`,
/// `http_build_query`.
fn merge_query(query: &str, extra: &[(&str, &str)]) -> String {
    let parsed = php_std::types::Value::Array(php_std::encoding::parse_str(query.as_bytes())).to_json();
    let mut map = match parsed {
        Value::Object(m) => m,
        Value::Array(items) => items.into_iter().enumerate().map(|(i, v)| (i.to_string(), v)).collect(),
        _ => Map::new(),
    };
    for (k, v) in extra {
        map.insert((*k).to_owned(), Value::String((*v).to_owned()));
    }
    String::from_utf8_lossy(&php_std::encoding::http_build_query(
        &Value::Object(map),
        b"",
        None,
        QueryEncoding::Rfc1738,
    ))
    .into_owned()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn renders_params_inside_params() {
        let body = Template::new("<p>{{hello}}</p><p>{{body}}</p>")
            .param("{{body}}", "{{b}}x{{/b}} & {{team}}", false)
            .param("{{hello}}", "Hi <{{user}}>", true)
            .render();
        assert_eq!(body, "<p>Hi &lt;{{user}}&gt;</p><p>{{b}}x{{/b}} & {{team}}</p>");
        let nested = Template::new("{{a}}").param("{{a}}", "[{{b}}]", false).param("{{b}}", "B", false).render();
        assert_eq!(nested, "[B]");
    }

    #[test]
    fn merges_the_query() {
        assert_eq!(
            with_query("http://localhost", &[("membershipId", "m 1"), ("teamName", "A&B")]),
            "http://localhost?membershipId=m+1&teamName=A%26B"
        );
        assert_eq!(with_query("https://x.io:8080/p?a=1#f", &[("a", "2"), ("b", "3")]), "https://x.io:8080/p?a=2&b=3#f");
    }
}
