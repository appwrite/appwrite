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
        Command::new("git")?.argument("config")?.flag("--global")?.argument("init.defaultBranch")?.argument("main")?,
        git(directory)?.argument("init")?,
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
            Value::Int(_) | Value::Float(_) | Value::Bool(_) => php::string(&name),
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
