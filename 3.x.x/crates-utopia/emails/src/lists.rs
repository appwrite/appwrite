use std::collections::HashSet;
use std::sync::OnceLock;

fn parse(raw: &str, what: &str) -> HashSet<String> {
    serde_json::from_str::<Vec<String>>(raw)
        .unwrap_or_else(|err| panic!("{what} data file must return an array: {err}"))
        .into_iter()
        .collect()
}

/// Combined disposable-domain list (PHP `data/disposable-domains.php`).
pub fn disposable_domains() -> &'static HashSet<String> {
    static SET: OnceLock<HashSet<String>> = OnceLock::new();
    SET.get_or_init(|| {
        parse(
            include_str!("../data/disposable-domains.json"),
            "Disposable domains",
        )
    })
}

/// Combined free-domain list (PHP `data/free-domains.php`).
pub fn free_domains() -> &'static HashSet<String> {
    static SET: OnceLock<HashSet<String>> = OnceLock::new();
    SET.get_or_init(|| parse(include_str!("../data/free-domains.json"), "Free domains"))
}

/// Manual disposable-domain overlay (PHP `data/disposable-domains-manual.php`).
pub fn disposable_domains_manual() -> &'static HashSet<String> {
    static SET: OnceLock<HashSet<String>> = OnceLock::new();
    SET.get_or_init(|| {
        parse(
            include_str!("../data/disposable-domains-manual.json"),
            "Manual disposable domains",
        )
    })
}

/// Manual free-domain overlay (PHP `data/free-domains-manual.php`).
pub fn free_domains_manual() -> &'static HashSet<String> {
    static SET: OnceLock<HashSet<String>> = OnceLock::new();
    SET.get_or_init(|| {
        parse(
            include_str!("../data/free-domains-manual.json"),
            "Manual free domains",
        )
    })
}
