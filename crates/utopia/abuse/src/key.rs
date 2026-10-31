//! [`Key`]: the name a limit counts under, a pattern with placeholders.

use std::sync::Arc;

/// The name a limit counts under: a pattern such as `"ip:{ip}"` whose
/// placeholders are replaced by parameters.
///
/// Replacement follows PHP's `strtr()`: at each position the longest
/// placeholder that matches wins, and replaced text is never scanned again.
/// A placeholder without a parameter stays as written. The key is built once,
/// when the parameters are set, so reading it is free.
///
/// ```
/// use utopia_abuse::Key;
///
/// let key = Key::new("{ip}:{url}").with_param("{ip}", "127.0.0.1").with_param("{url}", "/v1/account");
/// assert_eq!(key.as_str(), "127.0.0.1:/v1/account");
///
/// // A placeholder without a parameter stays literal.
/// assert_eq!(Key::new("{ip}:{url}").with_param("{ip}", "10.0.0.1").as_str(), "10.0.0.1:{url}");
/// ```
///
/// ```php
/// use Utopia\Abuse\Adapter\TimeLimit\None;
///
/// $adapter = new None('{ip}:{url}', 1, 60)->withParams(['{ip}' => '127.0.0.1'])->withParam('{url}', '/v1/account');
/// echo $adapter->key(), "\n"; // 127.0.0.1:/v1/account
///
/// echo new None('{ip}:{url}', 1, 60)->withParam('{ip}', '10.0.0.1')->key(), "\n"; // 10.0.0.1:{url}
/// ```
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Key {
    pattern: Arc<str>,
    params: Vec<(String, String)>,
    rendered: String,
}

impl Key {
    /// A key from a pattern, without parameters.
    pub fn new(pattern: impl Into<String>) -> Self {
        let rendered: String = pattern.into();
        Key { pattern: Arc::from(rendered.as_str()), rendered, params: Vec::new() }
    }

    /// A copy with `params` merged over the current ones: a parameter set
    /// again keeps its place and takes the new value, a new one goes last.
    ///
    /// PHP: `withParams()`, which merges with `[...$this->params, ...$params]`.
    /// Placeholders that are canonical integers (`"0"`, `"42"`) are integer
    /// keys of a PHP array, which the spread renumbers from 0 (in order) on
    /// every merge, so `"7"` is then replaced as `"0"`; the merge keeps that.
    pub fn with_params<K, V>(&self, params: impl IntoIterator<Item = (K, V)>) -> Self
    where
        K: Into<String>,
        V: Into<String>,
    {
        let params = params.into_iter();
        let mut merged: Vec<(String, String)> = Vec::with_capacity(self.params.len() + params.size_hint().0);
        let mut next = 0i64;
        let mut put = |name: String, value: String, merged: &mut Vec<(String, String)>| {
            if php_std::types::numeric_key(name.as_bytes()).is_some() {
                merged.push((next.to_string(), value));
                next += 1;
            } else if let Some(slot) = merged.iter_mut().find(|(n, _)| *n == name) {
                slot.1 = value;
            } else {
                merged.push((name, value));
            }
        };
        for (name, value) in &self.params {
            put(name.clone(), value.clone(), &mut merged);
        }
        for (name, value) in params {
            put(name.into(), value.into(), &mut merged);
        }
        let rendered = render(&self.pattern, &merged);
        Key { pattern: self.pattern.clone(), params: merged, rendered }
    }

    /// A copy with one parameter set.
    ///
    /// PHP: `withParam()`.
    pub fn with_param(&self, name: impl Into<String>, value: impl Into<String>) -> Self {
        self.with_params([(name.into(), value.into())])
    }

    /// The pattern, as given.
    pub fn pattern(&self) -> &str {
        &self.pattern
    }

    /// The parameters, in the order a PHP array holds them.
    pub fn params(&self) -> &[(String, String)] {
        &self.params
    }

    /// The key: the pattern with its placeholders replaced.
    ///
    /// PHP: `key()`.
    pub fn as_str(&self) -> &str {
        &self.rendered
    }
}

impl std::fmt::Display for Key {
    /// Writes the key.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(&self.rendered)
    }
}

/// `strtr($pattern, $params)`.
fn render(pattern: &str, params: &[(String, String)]) -> String {
    const STACK: usize = 8;
    let out = if params.len() <= STACK {
        let mut pairs: [(&[u8], &[u8]); STACK] = [(&[], &[]); STACK];
        for (slot, (k, v)) in pairs.iter_mut().zip(params) {
            *slot = (k.as_bytes(), v.as_bytes());
        }
        php_std::string::strtr_array(pattern.as_bytes(), &pairs[..params.len()])
    } else {
        let pairs: Vec<(&[u8], &[u8])> = params.iter().map(|(k, v)| (k.as_bytes(), v.as_bytes())).collect();
        php_std::string::strtr_array(pattern.as_bytes(), &pairs)
    };
    // Whole keys are replaced by whole values, so UTF-8 stays UTF-8.
    String::from_utf8(out.into_owned()).unwrap_or_else(|e| String::from_utf8_lossy(e.as_bytes()).into_owned())
}
