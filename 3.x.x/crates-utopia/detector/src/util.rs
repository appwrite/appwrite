use std::collections::HashSet;

use regex::Regex;

/// PHP `array_unique` for strings, preserving first-seen order.
#[must_use]
pub fn unique_preserve(items: Vec<String>) -> Vec<String> {
    let mut seen = HashSet::new();
    items
        .into_iter()
        .filter(|item| seen.insert(item.clone()))
        .collect()
}

/// PHP `pathinfo($file, PATHINFO_EXTENSION)` for ASCII paths used in tests.
#[must_use]
pub fn php_extension(path: &str) -> &str {
    match path.rfind('.') {
        Some(index) if index + 1 < path.len() && !path[index + 1..].contains(['/', '\\']) => {
            &path[index + 1..]
        }
        _ => "",
    }
}

/// PHP `preg_replace('/(?<!:)\/\/[^\n]*/', '', $configContent)`.
///
/// The `regex` crate has no lookbehind, so `://` is left intact.
#[must_use]
pub fn strip_js_line_comments(content: &str) -> String {
    let bytes = content.as_bytes();
    let mut out = Vec::with_capacity(bytes.len());
    let mut i = 0;
    while i < bytes.len() {
        if bytes[i] == b'/'
            && i + 1 < bytes.len()
            && bytes[i + 1] == b'/'
            && (i == 0 || bytes[i - 1] != b':')
        {
            i += 2;
            while i < bytes.len() && bytes[i] != b'\n' {
                i += 1;
            }
            continue;
        }
        out.push(bytes[i]);
        i += 1;
    }
    String::from_utf8_lossy(&out).into_owned()
}

/// JS packager install command (PHP `match ($this->packager)`).
#[must_use]
pub fn js_install_command(packager: &str) -> String {
    match packager {
        "yarn" => "yarn install".to_string(),
        "npm" => "npm install".to_string(),
        _ => "pnpm install".to_string(),
    }
}

/// JS packager build command (PHP `match ($this->packager)`).
#[must_use]
pub fn js_build_command(packager: &str) -> String {
    match packager {
        "yarn" => "yarn build".to_string(),
        "npm" => "npm run build".to_string(),
        _ => "pnpm run build".to_string(),
    }
}

/// Astro `output: 'server'|'hybrid'` adapter regex.
pub fn astro_ssr_output_re() -> &'static Regex {
    static RE: std::sync::OnceLock<Regex> = std::sync::OnceLock::new();
    RE.get_or_init(|| {
        Regex::new(r#"\boutput\s*:\s*['"`](?:server|hybrid)['"`]"#).expect("astro adapter regex")
    })
}

/// `TanStack` Start `prerender` presence.
pub fn tanstack_prerender_re() -> &'static Regex {
    static RE: std::sync::OnceLock<Regex> = std::sync::OnceLock::new();
    RE.get_or_init(|| Regex::new(r"\bprerender\b").expect("tanstack prerender regex"))
}

/// `TanStack` Start `prerender: false`.
pub fn tanstack_prerender_false_re() -> &'static Regex {
    static RE: std::sync::OnceLock<Regex> = std::sync::OnceLock::new();
    RE.get_or_init(|| {
        Regex::new(r#"\bprerender['"]?\s*:\s*false\b"#).expect("tanstack prerender false regex")
    })
}
