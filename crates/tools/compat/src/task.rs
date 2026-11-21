//! Runs a crate's task binary (crates/CONVERSION.md §2 rule 6) as its own
//! process, the way a developer runs it: built with cargo, started from the
//! repository root with extra environment variables. The PHP side is
//! `tests/compat/php/Task.php`.

use std::collections::HashSet;
use std::path::PathBuf;
use std::process::{Command, Stdio};
use std::sync::Mutex;

use serde_json::{Value, json};

use crate::adapter::Fault;

/// What a task did.
pub struct Output {
    pub exit: i32,
    pub stdout: String,
    pub stderr: String,
}

impl Output {
    /// The output with `replace` applied to both streams (each runtime's own
    /// paths), as JSON.
    pub fn to_json(&self, replace: &[(&str, &str)]) -> Value {
        let clean = |s: &str| replace.iter().fold(s.to_owned(), |s, (from, to)| s.replace(from, to));
        json!({ "exit": self.exit, "stdout": clean(&self.stdout), "stderr": clean(&self.stderr) })
    }
}

/// Binaries built by this process.
static BUILT: Mutex<Option<HashSet<String>>> = Mutex::new(None);

/// The target directory and profile this driver was built in, where the
/// task binaries are built too.
fn target() -> Result<(PathBuf, bool), Fault> {
    let exe = std::env::current_exe().map_err(|e| Fault::new(format!("current_exe: {e}")))?;
    let mut dir = exe.parent().map(PathBuf::from).unwrap_or_default();
    // Test binaries live in <target>/<profile>/deps.
    if dir.file_name().is_some_and(|n| n == "deps") {
        dir.pop();
    }
    let release = dir.file_name().is_some_and(|n| n == "release");
    dir.pop();
    Ok((dir, release))
}

/// Builds `package`'s binary `bin` (once per process) and runs it with `args`
/// and `env` added to this process's environment.
pub fn run(package: &str, bin: &str, args: &[String], env: &[(String, String)]) -> Result<Output, Fault> {
    let (target, release) = target()?;
    {
        let mut built = BUILT.lock().unwrap_or_else(|e| e.into_inner());
        let built = built.get_or_insert_with(HashSet::new);
        if !built.contains(bin) {
            let mut cargo = Command::new(std::env::var("CARGO").unwrap_or_else(|_| "cargo".to_owned()));
            cargo.args(["build", "--quiet", "-p", package, "--bin", bin, "--target-dir"]).arg(&target);
            if release {
                cargo.arg("--release");
            }
            let status = cargo.status().map_err(|e| Fault::new(format!("cargo build {bin}: {e}")))?;
            if !status.success() {
                return Err(Fault::new(format!("cargo build -p {package} --bin {bin} failed")));
            }
            built.insert(bin.to_owned());
        }
    }
    let exe = target.join(if release { "release" } else { "debug" }).join(bin);
    let out = Command::new(&exe)
        .args(args)
        .envs(env.iter().map(|(k, v)| (k.as_str(), v.as_str())))
        .stdin(Stdio::null())
        .output()
        .map_err(|e| Fault::new(format!("{}: {e}", exe.display())))?;
    Ok(Output {
        // A process ended by a signal has no code; PHP reports -1 for it.
        exit: out.status.code().unwrap_or(-1),
        stdout: String::from_utf8_lossy(&out.stdout).into_owned(),
        stderr: String::from_utf8_lossy(&out.stderr).into_owned(),
    })
}
