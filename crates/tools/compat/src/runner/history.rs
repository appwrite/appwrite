//! When things last changed, for the docs site to say how fresh each number
//! is: the time the data was generated, and the date of the last commit to a
//! set of paths, read with git. Without git, or in a shallow clone (whose
//! history stops at its boundary, so a date would be wrong), every date is
//! `None` and the site leaves it out.

use std::path::Path;
use std::process::Command;

use chrono::{DateTime, SecondsFormat, Utc};

pub struct History<'a> {
    root: &'a Path,
    /// Git works here and the history is whole.
    whole: bool,
}

impl<'a> History<'a> {
    pub fn new(root: &'a Path) -> Self {
        let whole = git(root, &["rev-parse", "--is-shallow-repository"]).is_some_and(|s| s == "false");
        Self { root, whole }
    }

    /// The committer date (ISO 8601) of `HEAD`.
    pub fn head(&self) -> Option<String> {
        git(self.root, &["log", "-1", "--format=%cI", "HEAD"]).filter(|d| !d.is_empty())
    }

    /// The committer date (ISO 8601) of the last commit touching any of `paths`.
    pub fn changed(&self, paths: &[&str]) -> Option<String> {
        if !self.whole || paths.is_empty() {
            return None;
        }
        let mut args = vec!["log", "-1", "--format=%cI", "HEAD", "--"];
        args.extend(paths);
        git(self.root, &args).filter(|d| !d.is_empty())
    }

    /// Whether a commit after `commit` (up to `HEAD`) touches any of `paths`;
    /// `None` when `commit` is unknown here.
    pub fn changed_since(&self, commit: &str, paths: &[&str]) -> Option<bool> {
        if !self.whole || paths.is_empty() || commit.is_empty() {
            return None;
        }
        let rev = format!("{commit}^{{commit}}");
        git(self.root, &["rev-parse", "--verify", "--quiet", &rev])?;
        let range = format!("{commit}..HEAD");
        let mut args = vec!["rev-list", "--count", range.as_str(), "--"];
        args.extend(paths);
        git(self.root, &args)?.parse::<usize>().ok().map(|n| n > 0)
    }
}

/// Now, in UTC (`2026-10-13T09:30:00Z`).
pub fn now() -> String {
    Utc::now().to_rfc3339_opts(SecondsFormat::Secs, true)
}

/// The latest of ISO 8601 dates, whatever their offsets; unparsable ones are skipped.
pub fn latest<'d>(dates: impl IntoIterator<Item = Option<&'d str>>) -> Option<String> {
    dates
        .into_iter()
        .flatten()
        .filter_map(|d| DateTime::parse_from_rfc3339(d).ok().map(|t| (t, d)))
        .max_by_key(|(t, _)| *t)
        .map(|(_, d)| d.to_owned())
}

fn git(root: &Path, args: &[&str]) -> Option<String> {
    let out = Command::new("git").args(args).current_dir(root).output().ok()?;
    out.status.success().then(|| String::from_utf8_lossy(&out.stdout).trim().to_owned())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn latest_compares_instants() {
        // 10:00+02:00 is 08:00Z: earlier than 09:00Z.
        let dates = [Some("2026-10-01T10:00:00+02:00"), None, Some("2026-10-01T09:00:00Z"), Some("soon")];
        assert_eq!(latest(dates).as_deref(), Some("2026-10-01T09:00:00Z"));
        assert_eq!(latest([None, Some("x")]), None);
    }

    #[test]
    fn now_is_utc() {
        let t = now();
        assert!(t.ends_with('Z') && DateTime::parse_from_rfc3339(&t).is_ok());
    }

    #[test]
    fn dates_from_git() {
        let dir = std::env::temp_dir().join(format!("compat-history-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(dir.join("a")).unwrap();
        // Outside a repository: nothing.
        let none = History::new(&dir);
        assert_eq!(none.changed(&["a"]), None);
        assert_eq!(none.head(), None);

        let run = |args: &[&str], date: &str| {
            let ok = Command::new("git")
                .args(["-c", "user.name=t", "-c", "user.email=t@t", "-c", "commit.gpgsign=false"])
                .args(args)
                .env("GIT_AUTHOR_DATE", date)
                .env("GIT_COMMITTER_DATE", date)
                .current_dir(&dir)
                .output()
                .unwrap()
                .status
                .success();
            assert!(ok, "git {args:?}");
        };
        run(&["init", "--quiet"], "2026-01-01T00:00:00Z");
        std::fs::write(dir.join("a/x"), "1").unwrap();
        run(&["add", "."], "2026-01-01T00:00:00Z");
        run(&["commit", "--quiet", "-m", "a"], "2026-01-01T00:00:00Z");
        let first = git(&dir, &["rev-parse", "HEAD"]).unwrap();
        std::fs::write(dir.join("b"), "1").unwrap();
        run(&["add", "."], "2026-02-01T00:00:00Z");
        run(&["commit", "--quiet", "-m", "b"], "2026-02-01T00:00:00Z");

        let history = History::new(&dir);
        let day = |d: Option<String>| d.map(|d| d[..10].to_owned());
        assert_eq!(day(history.changed(&["a"])).as_deref(), Some("2026-01-01"));
        assert_eq!(day(history.changed(&["a", "b"])).as_deref(), Some("2026-02-01"));
        assert_eq!(day(history.head()).as_deref(), Some("2026-02-01"));
        assert_eq!(history.changed(&["missing"]), None);
        assert_eq!(history.changed_since(&first, &["a"]), Some(false));
        assert_eq!(history.changed_since(&first, &["b"]), Some(true));
        assert_eq!(history.changed_since("0123456789", &["a"]), None);
        std::fs::remove_dir_all(&dir).unwrap();
    }
}
