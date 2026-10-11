//! Benchmark coverage: which endpoints each service's k6 script
//! (`tests/benchmarks/api/<service>.js`) has a scenario for, and which it
//! skips and why. Read from the script itself, so a scenario counts as soon
//! as it is written, before a run records its timings.

use std::collections::BTreeMap;
use std::path::Path;

/// A service's benchmark script, as declared.
#[derive(Default)]
pub struct Coverage {
    /// The script, relative to the repository.
    pub script: String,
    /// `METHOD /path` of each scenario (a route can have several).
    pub routes: Vec<String>,
    /// `METHOD /path` → why it is not benchmarked.
    pub skipped: BTreeMap<String, String>,
}

/// Every service's benchmark script under `tests/benchmarks/api`, by service id.
pub fn coverage(root: &Path) -> BTreeMap<String, Coverage> {
    let dir = root.join("tests/benchmarks/api");
    let mut out = BTreeMap::new();
    for entry in std::fs::read_dir(&dir).into_iter().flatten().flatten() {
        let path = entry.path();
        let Some(service) = path.file_name().and_then(|n| n.to_str()).and_then(|n| n.strip_suffix(".js")) else {
            continue;
        };
        if service == "lib" {
            continue;
        }
        let Ok(src) = std::fs::read_to_string(&path) else { continue };
        out.insert(
            service.to_owned(),
            Coverage {
                script: format!("tests/benchmarks/api/{service}.js"),
                routes: strings(&src, "route: '").into_iter().map(|(_, v)| v).collect(),
                skipped: strings(&src, "skipped: '")
                    .into_iter()
                    .map(|(at, route)| {
                        (route, strings(&src[at..], "reason: '").into_iter().next().map(|(_, r)| r).unwrap_or_default())
                    })
                    .collect(),
            },
        );
    }
    out
}

/// Each single-quoted string literal that follows `prefix`, with the offset
/// just past it. Template literals (`` `...` ``) are not declarations: a
/// route is always written out.
fn strings(src: &str, prefix: &str) -> Vec<(usize, String)> {
    let mut out = Vec::new();
    let mut from = 0;
    while let Some(i) = src[from..].find(prefix) {
        let start = from + i + prefix.len();
        let mut value = String::new();
        let mut chars = src[start..].char_indices();
        let mut end = src.len();
        while let Some((j, c)) = chars.next() {
            match c {
                '\\' => {
                    if let Some((_, n)) = chars.next() {
                        value.push(n);
                    }
                }
                '\'' => {
                    end = start + j + 1;
                    break;
                }
                c => value.push(c),
            }
        }
        out.push((end, value));
        from = end;
    }
    out
}

#[cfg(test)]
mod tests {
    use super::strings;

    #[test]
    fn declarations() {
        let src = "{ name: 'a', route: 'GET /v1/x' }, { skipped: 'POST /v1/y', reason: 'calls GitHub\\'s API' }";
        assert_eq!(strings(src, "route: '"), vec![(31, "GET /v1/x".to_owned())]);
        let skipped = strings(src, "skipped: '");
        assert_eq!(skipped[0].1, "POST /v1/y");
        assert_eq!(strings(&src[skipped[0].0..], "reason: '")[0].1, "calls GitHub's API");
    }
}
