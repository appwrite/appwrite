//! What every Git adapter shares: the clone command, path normalization and
//! tag glob matching.

use php_std::Value;
use utopia_console::Command;

use crate::adapter::clone_type;
use crate::error::{Error, Result};
use crate::php;

/// Resolves a tag glob the way GitHub and Origin used to: the last tag `git
/// ls-remote --tags` lists for the pattern (`--refs` omits the peeled `^{}`
/// line of an annotated tag). The directory and pattern arrive as `$1` and
/// `$2`.
const TAG_GLOB_CHECKOUT: &str = r#"refs=$(git -C "$1" ls-remote --refs --tags origin -- "$2") || exit
line=$(printf '%s\n' "$refs" | tail -n 1)
tag=${line#*refs/tags/}
if [ -z "$tag" ]; then
    printf 'fatal: no tag matching %s\n' "$2" >&2
    exit 1
fi
git -C "$1" fetch --depth=1 origin "refs/tags/$tag" && git -C "$1" checkout FETCH_HEAD"#;

/// The branch checkout: pull the branch when the remote has it, create it
/// locally otherwise.
const BRANCH_CHECKOUT: &str = r#"if git -C "$1" ls-remote --exit-code --heads origin "$2"; then git -C "$1" pull --depth=1 origin "$2" && git -C "$1" checkout "$2"; else git -C "$1" checkout -b "$2"; fi"#;

/// `git -C <directory>`.
pub(crate) fn git(directory: &str) -> Result<Command> {
    Ok(Command::new("git")?.option("-C", directory)?)
}

/// A sparse, shallow clone of one ref into `directory`, checking out only
/// `root_directory`. Every value reaches git as its own argument; the shell
/// scripts are constant and read their values from positional parameters.
///
/// PHP: `Git::cloneCommand()` (protected there: each adapter passes its own
/// clone URL; Rust exposes it for any URL, such as a `file://` one).
pub fn clone_command(
    clone_url: &str,
    version: &str,
    version_type: &str,
    directory: &str,
    root_directory: &str,
) -> Result<Command> {
    let mut root_directory = normalize_repository_path(root_directory);
    if root_directory.is_empty() {
        root_directory = "*".to_owned();
    }

    // A leading dash would reach git as an option instead of a ref; a tag
    // travels as refs/tags/<name>, so any name is safe there.
    if version_type != clone_type::TAG && version.starts_with('-') {
        return Err(Error::InvalidArgument(format!("Invalid clone version: {version}")));
    }

    let checkout = match version_type {
        clone_type::BRANCH => Command::new("sh")?
            .flag("-c")?
            .argument(BRANCH_CHECKOUT)?
            .argument("sh")?
            .argument(directory)?
            .argument(version)?,
        clone_type::COMMIT => Command::and(vec![
            git(directory)?.argument("fetch")?.option("--depth", "1")?.argument("origin")?.argument(version)?,
            git(directory)?.argument("checkout")?.argument(version)?,
        ])?,
        clone_type::TAG => tag_checkout(directory, version)?,
        other => return Err(Error::exception(format!("Unsupported clone type: {other}"), 0)),
    };

    Ok(Command::and(vec![
        Command::new("mkdir")?.flag("-p")?.argument(directory)?,
        // Set for this repository only: concurrent builds writing the global
        // config fail on each other's lock
        git(directory)?.option("-c", "init.defaultBranch=main")?.argument("init")?,
        git(directory)?.argument("remote")?.argument("add")?.argument("origin")?.argument(clone_url)?,
        git(directory)?.argument("config")?.argument("core.sparseCheckout")?.argument("true")?,
        Command::append_stdout(
            Command::new("printf")?.argument("%s\\n")?.argument(root_directory)?,
            format!("{directory}/.git/info/sparse-checkout"),
        )?,
        // Disable fetching of refs we don't need
        git(directory)?
            .argument("config")?
            .flag("--add")?
            .argument("remote.origin.fetch")?
            .argument("+refs/heads/*:refs/remotes/origin/*")?,
        // Disable fetching of tags
        git(directory)?.argument("config")?.argument("remote.origin.tagopt")?.argument("--no-tags")?,
        checkout,
    ])?)
}

/// Checks out a tag: a glob resolves to one tag; an exact name is fetched as
/// `refs/tags/<name>`, so a leading dash stays part of the name.
fn tag_checkout(directory: &str, version: &str) -> Result<Command> {
    if !is_tag_glob(version) {
        return Ok(Command::and(vec![
            git(directory)?
                .argument("fetch")?
                .option("--depth", "1")?
                .argument("origin")?
                .argument(format!("refs/tags/{version}"))?,
            git(directory)?.argument("checkout")?.argument("FETCH_HEAD")?,
        ])?);
    }
    Ok(Command::new("sh")?
        .flag("-c")?
        .argument(TAG_GLOB_CHECKOUT)?
        .argument("sh")?
        .argument(directory)?
        .argument(version)?)
}

/// Whether a version is a ref glob: `*`, `?` and `[` cannot appear in a tag
/// name, and they are what `git ls-remote` matches on.
fn is_tag_glob(version: &str) -> bool {
    version.contains('*') || version.contains('?') || version.contains('[')
}

/// The plain path the sentinels a caller may pass (`''`, `.`, `./`,
/// `src//`) stand for: segments that are empty or `.` are dropped.
///
/// PHP: `Git::normalizeRepositoryPath()`.
pub(crate) fn normalize_repository_path(path: &str) -> String {
    path.split('/').filter(|segment| !segment.is_empty() && *segment != ".").collect::<Vec<_>>().join("/")
}

/// The names matching a shell glob (`v1.*`); an empty pattern keeps them
/// all.
///
/// PHP: `Git::matchGlob()`, `fnmatch($pattern, $name)` on each name.
pub(crate) fn match_glob(names: Vec<Value>, pattern: &str) -> Result<Vec<Value>> {
    if pattern.is_empty() {
        return Ok(names);
    }
    let mut kept = Vec::with_capacity(names.len());
    for name in names {
        let bytes = match &name {
            Value::Str(s) => s.as_bytes().to_vec(),
            Value::Int(_) | Value::Float(_) | Value::Bool(_) => php::string(&name).to_vec(),
            other => {
                return Err(Error::type_error(format!(
                    "{{closure:Utopia\\VCS\\Adapter\\Git::matchGlob():434}}(): Argument #1 ($name) must be of type string, {} given",
                    php::value_name(other)
                )));
            }
        };
        let matched = php_std::glob::fnmatch(pattern.as_bytes(), &bytes).map_err(Error::Engine)?;
        if matched {
            kept.push(name);
        }
    }
    Ok(kept)
}

/// The repository path a caller-supplied file path names, normalized
/// lexically. Rejects a path that climbs out of the checkout through `..`
/// segments, or that reaches into `.git`, whose config and hooks the
/// following git commands would run.
///
/// PHP: `Git::confinedPath()`.
pub(crate) fn confined_path(filepath: &str) -> Result<String> {
    let mut segments: Vec<&str> = Vec::new();
    let replaced = filepath.replace('\\', "/");
    for segment in replaced.split('/') {
        match segment {
            "" | "." => continue,
            ".." => {
                if segments.pop().is_none() {
                    return Err(Error::exception(format!("File path escapes the repository: {filepath}"), 0));
                }
            }
            other if other.eq_ignore_ascii_case(".git") => {
                return Err(Error::exception(format!("File path names repository metadata: {filepath}"), 0));
            }
            other => segments.push(other),
        }
    }
    if segments.is_empty() {
        return Err(Error::exception(format!("File path names no file: {filepath}"), 0));
    }
    Ok(segments.join("/"))
}

/// Whether a path is a symbolic link (`is_link()`).
fn is_link(path: &str) -> bool {
    std::fs::symlink_metadata(path).map(|m| m.file_type().is_symlink()).unwrap_or(false)
}

/// Writes a file at a confined path inside a checkout. Lexical confinement
/// is not enough once the checkout itself carries symlinks: a linked
/// directory or file would carry the write outside. So each missing
/// directory is created one at a time and every component is refused if it
/// is a link, before anything is created beneath it.
///
/// PHP: `Git::writeCheckoutFile()`.
pub(crate) async fn write_checkout_file(directory: &str, relative: &str, content: &[u8]) -> Result<()> {
    let mut segments: Vec<&str> = relative.split('/').collect();
    let file = segments.pop().unwrap_or_default();
    let mut path = directory.to_owned();
    for segment in segments {
        path.push('/');
        path.push_str(segment);
        if is_link(&path) {
            return Err(Error::exception(format!("File path escapes the repository: {relative}"), 0));
        }
        let target = std::path::Path::new(&path);
        if !target.is_dir() && (target.exists() || utopia_async::fs::create_dir(&path).await.is_err()) {
            return Err(Error::exception(format!("Failed to create directory for {relative}"), 0));
        }
    }
    path.push('/');
    path.push_str(file);
    if is_link(&path) {
        return Err(Error::exception(format!("File path escapes the repository: {relative}"), 0));
    }
    if utopia_async::fs::write(&path, content).await.is_err() {
        return Err(Error::exception(format!("Failed to write {relative}"), 0));
    }
    Ok(())
}
