//! PHP `htmlspecialchars` / `htmlentities` (`ENT_QUOTES`, UTF-8) and view minify.

use std::sync::OnceLock;

use regex::Regex;

/// PHP `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')` for the `ENT_QUOTES` character set:
/// `&` `"` `'` `<` `>`.
pub(crate) fn htmlspecialchars(value: &str) -> String {
    encode_ent_quotes(value)
}

/// PHP `htmlentities($value, ENT_QUOTES, 'UTF-8')` for the characters the View tests cover:
/// `&` `"` `'` `<` `>` (same encoding as `htmlspecialchars` with `ENT_QUOTES`).
pub(crate) fn htmlentities(value: &str) -> String {
    encode_ent_quotes(value)
}

fn encode_ent_quotes(value: &str) -> String {
    let mut out = String::with_capacity(value.len());
    for c in value.chars() {
        match c {
            '&' => out.push_str("&amp;"),
            '"' => out.push_str("&quot;"),
            '\'' => out.push_str("&#039;"),
            '<' => out.push_str("&lt;"),
            '>' => out.push_str("&gt;"),
            _ => out.push(c),
        }
    }
    out
}

/// PHP `nl2p` default filter: explode on `\n\n`, wrap non-empty trimmed chunks in `<p>`,
/// then replace remaining `\n` with `<br />`.
pub(crate) fn nl2p(value: &str) -> String {
    let mut paragraphs = String::new();
    for line in value.split("\n\n") {
        if !php_trim(line).is_empty() {
            paragraphs.push_str("<p>");
            paragraphs.push_str(line);
            paragraphs.push_str("</p>");
        }
    }
    paragraphs.replace('\n', "<br />")
}

/// PHP `trim()` default character mask.
fn php_trim(s: &str) -> &str {
    s.trim_matches(|c: char| matches!(c, ' ' | '\t' | '\n' | '\r' | '\0' | '\x0B'))
}

fn compile(pattern: &'static str) -> Regex {
    Regex::new(pattern).expect("static PHP-compatible minify regex")
}

fn textarea_re() -> &'static Regex {
    static RE: OnceLock<Regex> = OnceLock::new();
    RE.get_or_init(|| compile(r"(?is)<textarea.*?>.*?</textarea>"))
}

fn pre_re() -> &'static Regex {
    static RE: OnceLock<Regex> = OnceLock::new();
    RE.get_or_init(|| compile(r"(?is)<pre.*?>.*?</pre>"))
}

/// PHP: `/>[^\S ]+/s`
fn after_tag_re() -> &'static Regex {
    static RE: OnceLock<Regex> = OnceLock::new();
    RE.get_or_init(|| compile(r">[\t\n\r\x0C\x0B]+"))
}

/// PHP: `/[^\S ]+</s`
fn before_tag_re() -> &'static Regex {
    static RE: OnceLock<Regex> = OnceLock::new();
    RE.get_or_init(|| compile(r"[\t\n\r\x0C\x0B]+<"))
}

/// PHP: `/(\s)+/s` with ASCII `\s` (no `/u` flag).
fn collapse_ws_re() -> &'static Regex {
    static RE: OnceLock<Regex> = OnceLock::new();
    RE.get_or_init(|| compile(r"([ \t\n\r\x0C\x0B])+"))
}

/// Minify HTML using the same placeholder algorithm and regexes as `Utopia\View\View::render`.
pub(crate) fn minify(html: &str) -> String {
    let found_txt: Vec<String> = textarea_re()
        .find_iter(html)
        .map(|m| m.as_str().to_owned())
        .collect();
    let found_pre: Vec<String> = pre_re()
        .find_iter(html)
        .map(|m| m.as_str().to_owned())
        .collect();

    let mut html = html.to_owned();
    for (index, original) in found_txt.iter().enumerate() {
        html = html.replace(original, &format!("<textarea>{index}</textarea>"));
    }
    for (index, original) in found_pre.iter().enumerate() {
        html = html.replace(original, &format!("<pre>{index}</pre>"));
    }

    html = after_tag_re().replace_all(&html, ">").into_owned();
    html = before_tag_re().replace_all(&html, "<").into_owned();
    html = collapse_ws_re().replace_all(&html, "$1").into_owned();

    for (index, original) in found_txt.iter().enumerate() {
        html = html.replace(&format!("<textarea>{index}</textarea>"), original);
    }
    for (index, original) in found_pre.iter().enumerate() {
        html = html.replace(&format!("<pre>{index}</pre>"), original);
    }
    html
}
