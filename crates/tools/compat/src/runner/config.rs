//! `tests/compat/compat.json`: how to start each driver and where each
//! runtime (and the runner itself) reaches the shared services.

use std::collections::HashMap;
use std::path::{Path, PathBuf};

use serde_json::{Map, Value};

pub struct Config {
    pub root: PathBuf,
    pub php: Vec<String>,
    pub rust: Vec<String>,
    /// `name -> {"php": url, "rust": url, "runner": url}`.
    pub services: Map<String, Value>,
}

impl Config {
    /// Loads the config from the repository root. `COMPAT_PHP` and
    /// `COMPAT_RUST` (whitespace-separated commands) override the drivers.
    pub fn load(root: &Path) -> Result<Self, String> {
        let path = root.join("tests/compat/compat.json");
        let raw = std::fs::read_to_string(&path).map_err(|e| format!("{}: {e}", path.display()))?;
        let dotenv = read_dotenv(&root.join(".env"));
        let raw = interpolate(&raw, &dotenv);
        let json: Value = serde_json::from_str(&raw).map_err(|e| format!("{}: {e}", path.display()))?;
        let command = |key: &str, env: &str| -> Vec<String> {
            if let Ok(v) = std::env::var(env) {
                return v.split_whitespace().map(str::to_owned).collect();
            }
            json.get("drivers")
                .and_then(|d| d.get(key))
                .and_then(Value::as_array)
                .map(|a| a.iter().filter_map(Value::as_str).map(str::to_owned).collect())
                .unwrap_or_default()
        };
        let mut rust = command("rust", "COMPAT_RUST");
        if rust.is_empty() {
            let exe = std::env::current_exe().map_err(|e| e.to_string())?;
            rust = vec![exe.with_file_name("compat-driver").display().to_string()];
        }
        Ok(Self {
            root: root.to_path_buf(),
            php: command("php", "COMPAT_PHP"),
            rust,
            services: json.get("services").and_then(Value::as_object).cloned().unwrap_or_default(),
        })
    }

    /// Service endpoints as one runtime (`php` or `rust`) sees them.
    pub fn services_for(&self, side: &str) -> Map<String, Value> {
        self.services.iter().filter_map(|(name, v)| v.get(side).map(|url| (name.clone(), url.clone()))).collect()
    }

    /// Endpoint the runner uses to snapshot a service.
    pub fn runner_service(&self, name: &str) -> Result<String, String> {
        self.services
            .get(name)
            .and_then(|v| v.get("runner"))
            .and_then(Value::as_str)
            .map(str::to_owned)
            .ok_or_else(|| format!("service `{name}` has no `runner` endpoint in tests/compat/compat.json"))
    }
}

fn read_dotenv(path: &Path) -> HashMap<String, String> {
    std::fs::read_to_string(path)
        .unwrap_or_default()
        .lines()
        .filter_map(|line| {
            let line = line.trim();
            if line.starts_with('#') {
                return None;
            }
            let (k, v) = line.split_once('=')?;
            Some((k.trim().to_owned(), v.trim().trim_matches('"').to_owned()))
        })
        .collect()
}

/// Replaces `${VAR}` with the environment variable, else the `.env` value.
/// `${ns}` is left alone: it is the per-case namespace placeholder.
fn interpolate(raw: &str, dotenv: &HashMap<String, String>) -> String {
    let mut out = String::with_capacity(raw.len());
    let mut rest = raw;
    while let Some(start) = rest.find("${") {
        out.push_str(&rest[..start]);
        let after = &rest[start + 2..];
        match after.find('}') {
            Some(end) if &after[..end] != "ns" => {
                let name = &after[..end];
                let value = std::env::var(name).ok().or_else(|| dotenv.get(name).cloned()).unwrap_or_default();
                out.push_str(&value);
                rest = &after[end + 1..];
            }
            _ => {
                out.push_str("${");
                rest = after;
            }
        }
    }
    out.push_str(rest);
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn interpolates_env_and_keeps_ns() {
        let env = HashMap::from([("USER_X".to_owned(), "bob".to_owned())]);
        assert_eq!(interpolate("a ${USER_X} ${ns} ${", &env), "a bob ${ns} ${");
    }
}
