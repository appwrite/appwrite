//! Allow-list hostname matcher. Rust port of `Utopia\Validator\Hostname`
//! as used by PHP CORS (`new Hostname($allowedHosts)`), not the format-only
//! `utopia_validators::Hostname`.

/// Hostname allow list with exact, `*`, and `*.example.com` matches.
#[derive(Debug, Clone)]
pub struct HostnameList {
    allow: Vec<String>,
}

impl HostnameList {
    #[must_use]
    pub fn new(allow: impl IntoIterator<Item = impl Into<String>>) -> Self {
        Self {
            allow: allow.into_iter().map(Into::into).collect(),
        }
    }

    /// PHP `Hostname::isValid()`.
    #[must_use]
    pub fn is_valid(&self, value: &str) -> bool {
        if value.is_empty() || value == "0" {
            return false;
        }
        if value.chars().count() > 253 {
            return false;
        }
        if value.contains('/') || value.contains(':') {
            return false;
        }
        if self.allow.is_empty() {
            return true;
        }
        for allowed in &self.allow {
            if value == allowed || allowed == "*" {
                return true;
            }
            if let Some(suffix) = allowed.strip_prefix('*') {
                if value.ends_with(suffix) {
                    return true;
                }
            }
        }
        false
    }
}

#[cfg(test)]
mod tests {
    use super::HostnameList;

    #[test]
    fn allow_list_and_subdomain_wildcard() {
        let list = HostnameList::new(["myweb.vercel.app", "myweb.com", "*.myapp.com"]);
        assert!(list.is_valid("myweb.vercel.app"));
        assert!(list.is_valid("myweb.com"));
        assert!(list.is_valid("project1.myapp.com"));
        assert!(list.is_valid("commit.anything.myapp.com"));
        assert!(!list.is_valid("myapp.com"));
        assert!(!list.is_valid("myweb.vercel.com"));
        assert!(!list.is_valid("anything.myapp.eu"));
    }

    #[test]
    fn rejects_path_and_port() {
        let list = HostnameList::new(Vec::<String>::new());
        assert!(list.is_valid("myweb.com"));
        assert!(!list.is_valid("https://myweb.com"));
        assert!(!list.is_valid("myweb.com:3000"));
        assert!(!list.is_valid("myweb.com/blog"));
        assert!(!list.is_valid(""));
        assert!(!list.is_valid("0"));
    }

    #[test]
    fn star_allows_everything() {
        let list = HostnameList::new(["*"]);
        assert!(list.is_valid("anything.example.com"));
    }
}
