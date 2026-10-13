//! Asking an agent about the difference between two versions of a
//! repository.

use std::path::{Path, PathBuf};

use php_std::pcre::Regex;
use utopia_console::{Command, execute};

use crate::{Adapter, Agent, Conversation, Error, Instruction, Message, Schema, User};

/// A repository to compare: a local directory, or a remote Git URL to
/// clone, at an optional ref.
///
/// PHP: `Utopia\Agents\DiffCheck\Repository`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Repository {
    source: String,
    remote: bool,
    reference: Option<String>,
}

impl Repository {
    /// A repository at `source` (trimmed), remote or local, at `reference`.
    ///
    /// Fails with [`Error::InvalidArgument`] for an empty source.
    ///
    /// PHP: `new Repository($source, $remote, $ref)`.
    pub fn new(source: &str, remote: bool, reference: Option<&str>) -> Result<Repository, Error> {
        let source = php_std::string::trim(source.as_bytes(), php_std::string::TRIM_CHARACTERS);
        if source.is_empty() {
            return Err(Error::InvalidArgument("Repository source must not be empty".into()));
        }
        Ok(Repository {
            source: String::from_utf8_lossy(source).into_owned(),
            remote,
            reference: reference.map(str::to_owned),
        })
    }

    /// A local directory, by its real path.
    ///
    /// Fails with [`Error::InvalidArgument`] when it is not a directory.
    ///
    /// PHP: `Repository::local($path, $ref)`.
    pub fn local(path: &str, reference: Option<&str>) -> Result<Repository, Error> {
        match std::fs::canonicalize(path) {
            Ok(resolved) if resolved.is_dir() => Repository::new(&resolved.to_string_lossy(), false, reference),
            _ => Err(Error::InvalidArgument(format!("Local repository path does not exist: {path}"))),
        }
    }

    /// A remote repository, cloned from `url`.
    ///
    /// PHP: `Repository::remote($url, $ref)`.
    pub fn remote(url: &str, reference: Option<&str>) -> Result<Repository, Error> {
        Repository::new(url, true, reference)
    }

    /// A local path that exists, or a URL that looks like a Git remote
    /// (`https://`, `ssh://`, `git://`, `file://`, `git@`, `user@host:path`).
    ///
    /// Fails with [`Error::InvalidArgument`] otherwise.
    ///
    /// PHP: `Repository::from($repository)` with a string.
    pub fn parse(value: &str) -> Result<Repository, Error> {
        let value = String::from_utf8_lossy(php_std::string::trim(value.as_bytes(), php_std::string::TRIM_CHARACTERS));
        if value.is_empty() {
            return Err(Error::InvalidArgument("Repository source must not be empty".into()));
        }
        if Path::new(value.as_ref()).exists() {
            return Repository::local(&value, None);
        }
        if looks_like_remote(&value) {
            return Repository::remote(&value, None);
        }
        Err(Error::InvalidArgument(format!("Repository source is not a valid local path or remote URL: {value}")))
    }

    /// The path or URL.
    ///
    /// PHP: `getSource()`.
    pub fn source(&self) -> &str {
        &self.source
    }

    /// Whether it is cloned.
    ///
    /// PHP: `isRemote()`.
    pub fn is_remote(&self) -> bool {
        self.remote
    }

    /// The ref checked out, if any.
    ///
    /// PHP: `getRef()`.
    pub fn reference(&self) -> Option<&str> {
        self.reference.as_deref()
    }
}

/// PHP: `Repository::looksLikeRemote($value)`.
fn looks_like_remote(value: &str) -> bool {
    let matches = |pattern: &[u8]| Regex::compiled(pattern).is_ok_and(|re| re.is_match(value.as_bytes()));
    matches(br"/^(https?:\/\/|ssh:\/\/|git:\/\/|git@|file:\/\/)/") || matches(br"/^[^@\s]+@[^:\s]+:.+$/")
}

/// What to compare: a path or URL, or a [`Repository`].
///
/// PHP: the `Repository|string` of `DiffCheck::run()`.
#[derive(Debug, Clone)]
pub enum Source {
    /// A local path or remote URL, as [`Repository::parse`] reads it.
    Text(String),
    /// A repository.
    Repository(Repository),
}

impl From<&str> for Source {
    fn from(value: &str) -> Source {
        Source::Text(value.to_owned())
    }
}

impl From<String> for Source {
    fn from(value: String) -> Source {
        Source::Text(value)
    }
}

impl From<Repository> for Source {
    fn from(repository: Repository) -> Source {
        Source::Repository(repository)
    }
}

impl Source {
    fn repository(self) -> Result<Repository, Error> {
        match self {
            Source::Text(text) => Repository::parse(&text),
            Source::Repository(repository) => Ok(repository),
        }
    }
}

/// How a diff check runs.
///
/// PHP: `Utopia\Agents\DiffCheck\Options`.
#[derive(Debug, Clone, PartialEq)]
pub struct Options {
    max_diff_lines: i64,
    exclude_paths: Vec<String>,
    ignore_all_space: bool,
    ignore_blank_lines: bool,
    schema: Option<Schema>,
    instructions: Vec<(String, String)>,
    description: String,
    user_id: String,
    trim_response: bool,
}

impl Default for Options {
    fn default() -> Options {
        Options {
            max_diff_lines: 500,
            exclude_paths: Vec::new(),
            ignore_all_space: true,
            ignore_blank_lines: true,
            schema: None,
            instructions: Vec::new(),
            description: String::new(),
            user_id: "diff-check-user".to_owned(),
            trim_response: true,
        }
    }
}

fn trimmed(s: &str) -> String {
    String::from_utf8_lossy(php_std::string::trim(s.as_bytes(), php_std::string::TRIM_CHARACTERS)).into_owned()
}

impl Options {
    /// The most diff lines sent (default 500); longer diffs are truncated.
    ///
    /// PHP: `getMaxDiffLines()`.
    pub fn max_diff_lines(&self) -> i64 {
        self.max_diff_lines
    }

    /// Changes the most diff lines; [`Error::InvalidArgument`] below 1.
    ///
    /// PHP: `setMaxDiffLines($maxDiffLines)`.
    pub fn set_max_diff_lines(&mut self, max_diff_lines: i64) -> Result<&mut Options, Error> {
        if max_diff_lines < 1 {
            return Err(Error::InvalidArgument("maxDiffLines must be greater than 0".into()));
        }
        self.max_diff_lines = max_diff_lines;
        Ok(self)
    }

    /// Path globs left out of both sides (`*` and `?` within a segment,
    /// `**` across segments).
    ///
    /// PHP: `getExcludePaths()`.
    pub fn exclude_paths(&self) -> &[String] {
        &self.exclude_paths
    }

    /// Replaces the excluded paths (each trimmed, empty ones dropped).
    ///
    /// PHP: `setExcludePaths($excludePaths)`.
    pub fn set_exclude_paths(&mut self, paths: &[&str]) -> &mut Options {
        self.exclude_paths = paths.iter().map(|p| trimmed(p)).filter(|p| !p.is_empty()).collect();
        self
    }

    /// Adds an excluded path (trimmed; an empty one is ignored).
    ///
    /// PHP: `addExcludePath($excludePath)`.
    pub fn add_exclude_path(&mut self, path: &str) -> &mut Options {
        let path = trimmed(path);
        if !path.is_empty() {
            self.exclude_paths.push(path);
        }
        self
    }

    /// Whether whitespace changes are ignored (default `true`).
    ///
    /// PHP: `getIgnoreAllSpace()`.
    pub fn ignore_all_space(&self) -> bool {
        self.ignore_all_space
    }

    /// PHP: `setIgnoreAllSpace($ignoreAllSpace)`.
    pub fn set_ignore_all_space(&mut self, ignore: bool) -> &mut Options {
        self.ignore_all_space = ignore;
        self
    }

    /// Whether blank line changes are ignored (default `true`).
    ///
    /// PHP: `getIgnoreBlankLines()`.
    pub fn ignore_blank_lines(&self) -> bool {
        self.ignore_blank_lines
    }

    /// PHP: `setIgnoreBlankLines($ignoreBlankLines)`.
    pub fn set_ignore_blank_lines(&mut self, ignore: bool) -> &mut Options {
        self.ignore_blank_lines = ignore;
        self
    }

    /// The schema the answer must follow, set on the agent.
    ///
    /// PHP: `getSchema()`.
    pub fn schema(&self) -> Option<&Schema> {
        self.schema.as_ref()
    }

    /// PHP: `setSchema($schema)`.
    pub fn set_schema(&mut self, schema: Option<Schema>) -> &mut Options {
        self.schema = schema;
        self
    }

    /// Instructions set on the agent, when any.
    ///
    /// PHP: `getInstructions()`.
    pub fn instructions(&self) -> &[(String, String)] {
        &self.instructions
    }

    /// Replaces the instructions; a name given twice keeps its first place
    /// and its last content, as keys of a PHP array do.
    ///
    /// PHP: `setInstructions($instructions)`.
    pub fn set_instructions(&mut self, instructions: Vec<(String, String)>) -> &mut Options {
        self.instructions = Vec::with_capacity(instructions.len());
        for (name, content) in instructions {
            self.add_instruction(&name, &content);
        }
        self
    }

    /// Adds an instruction, or replaces the one of that name in place.
    ///
    /// PHP: `addInstruction($name, $content)`.
    pub fn add_instruction(&mut self, name: &str, content: &str) -> &mut Options {
        match self.instructions.iter_mut().find(|(n, _)| n == name) {
            Some((_, existing)) => *existing = content.to_owned(),
            None => self.instructions.push((name.to_owned(), content.to_owned())),
        }
        self
    }

    /// The description set on the agent, when not empty.
    ///
    /// PHP: `getDescription()`.
    pub fn description(&self) -> &str {
        &self.description
    }

    /// PHP: `setDescription($description)`.
    pub fn set_description(&mut self, description: &str) -> &mut Options {
        self.description = description.to_owned();
        self
    }

    /// The id of the user the prompt is sent as (default `diff-check-user`).
    ///
    /// PHP: `getUserId()`.
    pub fn user_id(&self) -> &str {
        &self.user_id
    }

    /// Changes the user id (trimmed); [`Error::InvalidArgument`] when empty.
    ///
    /// PHP: `setUserId($userId)`.
    pub fn set_user_id(&mut self, user_id: &str) -> Result<&mut Options, Error> {
        let user_id = trimmed(user_id);
        if user_id.is_empty() {
            return Err(Error::InvalidArgument("userId must not be empty".into()));
        }
        self.user_id = user_id;
        Ok(self)
    }

    /// Whether the answer is trimmed (default `true`).
    ///
    /// PHP: `getTrimResponse()`.
    pub fn trim_response(&self) -> bool {
        self.trim_response
    }

    /// PHP: `setTrimResponse($trimResponse)`.
    pub fn set_trim_response(&mut self, trim: bool) -> &mut Options {
        self.trim_response = trim;
        self
    }
}

/// Who answers: an agent (configured further by the options) or an adapter
/// (wrapped in a new agent).
///
/// PHP: the `Agent|Adapter $runner` of `DiffCheck::run()`.
pub enum Runner<'a, A> {
    /// An agent, which keeps the options' description, instructions and
    /// schema.
    Agent(&'a mut Agent<A>),
    /// An adapter.
    Adapter(&'a mut A),
}

/// The result of a diff check.
///
/// PHP: the array `run()` returns, `{hasChanges, response}`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Outcome {
    /// Whether the two sides differ (the agent is only asked when they do).
    pub has_changes: bool,
    /// The agent's answer (empty without changes).
    pub response: Vec<u8>,
}

/// Compares two versions of a repository with `git diff` and asks an agent
/// about the difference.
///
/// Both sides are copied (or cloned) to temporary directories, the
/// excluded paths are removed, and `git diff --no-index` compares them. When
/// they differ, the diff (truncated to the most lines the options allow) is
/// put in the prompt (`{{diff}}`, `{{base}}`, `{{target}}`, `{{diff_stats}}`
/// placeholders; appended when the prompt has no `{{diff}}`), and the
/// answer comes back.
///
/// PHP: `Utopia\Agents\DiffCheck\DiffCheck`, running `cp`, `git` and `head`
/// through `utopia-php/console`, in `sys_get_temp_dir()`.
///
/// ```no_run
/// use utopia_agents::{DiffCheck, OpenAi};
/// use utopia_agents::diff_check::{Options, Runner};
///
/// # async fn run() -> Result<(), utopia_agents::Error> {
/// let mut adapter = OpenAi::new("sk-...");
/// let mut options = Options::default();
/// options.set_exclude_paths(&[".github"]);
/// let outcome = DiffCheck::new()
///     .run(Runner::Adapter(&mut adapter), "/srv/sdk-old", "/srv/sdk-new", "Summarize:\n{{diff}}", &options)
///     .await?;
/// if outcome.has_changes {
///     println!("{}", String::from_utf8_lossy(&outcome.response));
/// }
/// # Ok(())
/// # }
/// ```
///
/// ```php
/// use Utopia\Agents\Adapters\OpenAI;
/// use Utopia\Agents\DiffCheck\DiffCheck;
/// use Utopia\Agents\DiffCheck\Options;
///
/// $options = (new Options())->setExcludePaths(['.github']);
/// // $result = (new DiffCheck())->run(new OpenAI('sk-...'), '/srv/sdk-old', '/srv/sdk-new', "Summarize:\n{{diff}}", $options);
/// // if ($result['hasChanges']) { echo $result['response'], "\n"; }
/// ```
#[derive(Debug, Clone)]
pub struct DiffCheck {
    temp_dir: PathBuf,
}

impl Default for DiffCheck {
    fn default() -> DiffCheck {
        DiffCheck::new()
    }
}

/// What [`DiffCheck::diff`] found.
struct Diff {
    has_changes: bool,
    diff: String,
    stats: String,
}

impl DiffCheck {
    /// A diff check working in the system's temporary directory.
    ///
    /// PHP: `new DiffCheck()`.
    pub fn new() -> DiffCheck {
        DiffCheck { temp_dir: std::env::temp_dir() }
    }

    /// Working in another temporary directory.
    ///
    /// PHP: `sys_get_temp_dir()`, which `TMPDIR` sets.
    pub fn with_temp_dir(mut self, dir: impl Into<PathBuf>) -> DiffCheck {
        self.temp_dir = dir.into();
        self
    }

    /// Compares `base` and `target` and asks the runner about the
    /// difference with `prompt`.
    ///
    /// Fails with [`Error::InvalidArgument`] for a source that is neither a
    /// local directory nor a remote URL, [`Error::Runtime`] when copying,
    /// cloning, checking out or diffing fails, and with the agent's errors.
    /// The temporary copies are removed in every case.
    ///
    /// PHP: `run($runner, $base, $target, $prompt, $options)`.
    pub async fn run<A: Adapter>(
        &self,
        runner: Runner<'_, A>,
        base: impl Into<Source>,
        target: impl Into<Source>,
        prompt: &str,
        options: &Options,
    ) -> Result<Outcome, Error> {
        let base = base.into().repository()?;
        let target = target.into().repository()?;

        let mut paths: Vec<PathBuf> = Vec::with_capacity(2);
        let result = self.compare(runner, &base, &target, prompt, options, &mut paths).await;
        for path in &paths {
            delete_path(path);
        }
        result
    }

    async fn compare<A: Adapter>(
        &self,
        runner: Runner<'_, A>,
        base: &Repository,
        target: &Repository,
        prompt: &str,
        options: &Options,
        paths: &mut Vec<PathBuf>,
    ) -> Result<Outcome, Error> {
        let base_path = self.materialize(base, "base", paths).await?;
        let target_path = self.materialize(target, "target", paths).await?;

        apply_excludes(&base_path, options.exclude_paths());
        apply_excludes(&target_path, options.exclude_paths());

        let diff = diff(&base_path, &target_path, options).await?;
        if !diff.has_changes {
            return Ok(Outcome { has_changes: false, response: Vec::new() });
        }

        let prompt = build_prompt(prompt, &diff.diff, base.source(), target.source(), &diff.stats);
        let user = User::new(options.user_id(), "");
        let response = match runner {
            Runner::Agent(agent) => {
                configure(agent, options)?;
                let profile = agent.profile().clone();
                ask(agent.adapter_mut(), profile, &user, prompt).await?
            }
            Runner::Adapter(adapter) => {
                let mut agent = Agent::new(&mut *adapter);
                configure(&mut agent, options)?;
                let profile = agent.profile().clone();
                ask(agent.into_adapter(), profile, &user, prompt).await?
            }
        };
        let response = if options.trim_response() {
            php_std::string::trim(&response, php_std::string::TRIM_CHARACTERS).to_vec()
        } else {
            response
        };
        Ok(Outcome { has_changes: true, response })
    }

    /// PHP: `materializeRepository($repository, $suffix)`.
    async fn materialize(&self, repository: &Repository, suffix: &str, paths: &mut Vec<PathBuf>) -> Result<String, Error> {
        let path = self.temp_directory(suffix)?;
        paths.push(PathBuf::from(&path));

        if repository.is_remote() {
            clone(repository, &path).await?;
            return Ok(path);
        }

        let copy = Command::new("cp")?.flag("-R")?.argument(format!("{}/.", repository.source()))?.argument(&path)?;
        let (code, output) = run(&copy).await?;
        if code != 0 {
            return Err(Error::Runtime(format!("Failed to copy local repository: {}", output.join("\n"))));
        }
        if let Some(reference) = repository.reference() {
            checkout(&path, reference).await?;
        }
        Ok(path)
    }

    /// PHP: `createTempDirectory($suffix)`.
    fn temp_directory(&self, suffix: &str) -> Result<String, Error> {
        let mut random = [0u8; 8];
        getrandom::fill(&mut random).map_err(|e| Error::Runtime(e.to_string()))?;
        let root = self.temp_dir.to_string_lossy();
        let path = format!("{}/utopia-diff-check-{suffix}-{}", root.trim_end_matches('/'), hex::encode(random));
        if std::fs::create_dir_all(&path).is_err() && !Path::new(&path).is_dir() {
            return Err(Error::Runtime(format!("Failed to create temp directory: {path}")));
        }
        Ok(path)
    }
}

/// PHP: `resolveAgent($runner, $options)`.
fn configure<A: Adapter>(agent: &mut Agent<A>, options: &Options) -> Result<(), Error> {
    if !options.description().is_empty() {
        agent.set_description(options.description());
    }
    if !options.instructions().is_empty() {
        agent.set_instructions(
            options.instructions().iter().map(|(n, c)| (n.clone(), Instruction::Text(c.clone()))).collect(),
        );
    }
    if let Some(schema) = options.schema() {
        agent.set_schema(schema.clone())?;
    }
    Ok(())
}

/// Sends the prompt in a new conversation and returns the answer.
///
/// PHP: `(new Conversation($agent))->message(new User($userId), new
/// Message($prompt))->send()`; the conversation borrows the agent's adapter
/// with a copy of its profile.
async fn ask<A: Adapter>(adapter: &mut A, profile: crate::Profile, user: &User, prompt: String) -> Result<Vec<u8>, Error> {
    let mut conversation = Conversation::new(Agent::from_parts(adapter, profile));
    conversation.message(user, Message::new(prompt), Vec::new())?;
    Ok(conversation.send().await?.into_content())
}

/// PHP: `cloneRemoteRepository($repository, $destination)`.
async fn clone(repository: &Repository, destination: &str) -> Result<(), Error> {
    let command = match repository.reference() {
        None => Command::new("git")?
            .argument("clone")?
            .option("--depth", "1")?
            .argument(repository.source())?
            .argument(destination)?,
        Some(_) => Command::new("git")?.argument("clone")?.argument(repository.source())?.argument(destination)?,
    };
    let (code, output) = run(&command).await?;
    if code != 0 {
        return Err(Error::Runtime(format!("Failed to clone repository: {}", output.join("\n"))));
    }
    if let Some(reference) = repository.reference() {
        checkout(destination, reference).await?;
    }
    Ok(())
}

/// `git -C <path> checkout <ref>`.
async fn checkout(path: &str, reference: &str) -> Result<(), Error> {
    let command = Command::new("git")?.option("-C", path)?.argument("checkout")?.argument(reference)?;
    let (code, output) = run(&command).await?;
    if code != 0 {
        return Err(Error::Runtime(format!("Failed to checkout ref \"{reference}\": {}", output.join("\n"))));
    }
    Ok(())
}

/// PHP: `diffCommand($basePath, $targetPath, $options, $quiet)`.
fn diff_command(base: &str, target: &str, options: &Options, quiet: bool) -> Result<Command, Error> {
    let mut command = Command::new("git")?.argument("diff")?;
    if quiet {
        command = command.flag("--quiet")?;
    }
    command = command.flag("--no-index")?;
    if options.ignore_all_space() {
        command = command.flag("--ignore-all-space")?;
    }
    if options.ignore_blank_lines() {
        command = command.flag("--ignore-blank-lines")?;
    }
    Ok(command.argument("--")?.argument(base)?.argument(target)?)
}

/// PHP: `generateDiff($basePath, $targetPath, $options)`.
async fn diff(base: &str, target: &str, options: &Options) -> Result<Diff, Error> {
    let (code, output) = run(&diff_command(base, target, options, true)?).await?;
    if code != 0 && code != 1 {
        return Err(Error::Runtime(format!("Failed to generate diff: {}", output.join("\n"))));
    }
    if code == 0 {
        return Ok(Diff { has_changes: false, diff: String::new(), stats: "0 lines, 0 bytes".into() });
    }

    let max = options.max_diff_lines();
    let head = Command::new("head")?.option("-n", max + 1)?;
    let (_, mut lines) = run(&Command::pipe(vec![diff_command(base, target, options, false)?, head])?).await?;
    let captured = lines.len();
    let truncated = captured as i64 > max;
    if truncated {
        lines.truncate(max as usize);
    }
    let mut diff = lines.join("\n");
    if truncated {
        diff.push_str(&format!("\n\n[Diff truncated to {max} lines.]"));
    }
    let stats = format!(
        "{} lines, {} bytes{}",
        if truncated { format!(">={max}") } else { captured.to_string() },
        diff.len(),
        if truncated { ", truncated" } else { "" }
    );
    Ok(Diff { has_changes: true, diff, stats })
}

/// PHP: `buildPrompt($prompt, $diff, $base, $target, $stats)`.
fn build_prompt(prompt: &str, diff: &str, base: &str, target: &str, stats: &str) -> String {
    let pairs: [(&[u8], &[u8]); 4] = [
        (b"{{diff}}", diff.as_bytes()),
        (b"{{base}}", base.as_bytes()),
        (b"{{target}}", target.as_bytes()),
        (b"{{diff_stats}}", stats.as_bytes()),
    ];
    let mut out = String::from_utf8_lossy(&php_std::string::strtr_array(prompt.as_bytes(), &pairs)).into_owned();
    if !prompt.contains("{{diff}}") {
        out.push_str(&format!("\n\nGit diff:\n```diff\n{diff}\n```"));
    }
    out
}

/// PHP: `applyExcludes($root, $patterns)`.
fn apply_excludes(root: &str, patterns: &[String]) {
    let patterns: Vec<String> = patterns
        .iter()
        .map(|p| trimmed(&p.replace('\\', "/")))
        .filter(|p| !p.is_empty())
        .map(|p| p.trim_start_matches('/').to_owned())
        .collect();
    if patterns.is_empty() {
        return;
    }
    walk(Path::new(root), &mut |path| {
        let relative = path.to_string_lossy();
        let relative = relative.get(root.len() + 1..).unwrap_or_default().replace('\\', "/");
        if relative.is_empty() {
            return;
        }
        if patterns.iter().any(|pattern| matches_glob(&relative, pattern)) {
            if path.is_dir() {
                delete_path(path);
            } else {
                let _ = std::fs::remove_file(path);
            }
        }
    });
}

/// Visits every entry under `dir`, children before their directory,
/// without following links to directories.
fn walk(dir: &Path, visit: &mut dyn FnMut(&Path)) {
    let Ok(entries) = std::fs::read_dir(dir) else { return };
    for entry in entries.flatten() {
        let path = entry.path();
        if entry.file_type().is_ok_and(|t| t.is_dir()) {
            walk(&path, visit);
        }
        visit(&path);
    }
}

/// PHP: `matchesGlob($path, $pattern)`.
fn matches_glob(path: &str, pattern: &str) -> bool {
    let path = path.trim_matches('/');
    let pattern = pattern.trim_matches('/');
    if pattern.is_empty() {
        return false;
    }
    if let Some(prefix) = pattern.strip_suffix("/**")
        && path == prefix
    {
        return true;
    }
    let escaped = php_std::pcre::preg_quote(pattern.as_bytes(), Some(b"/"));
    let escaped = String::from_utf8_lossy(&escaped)
        .replace(r"\*\*", "::DOUBLE_WILDCARD::")
        .replace(r"\*", "[^/]*")
        .replace(r"\?", "[^/]")
        .replace("::DOUBLE_WILDCARD::", ".*");
    let regex = format!("/^{escaped}$/");
    Regex::compiled(regex.as_bytes()).is_ok_and(|re| re.is_match(path.as_bytes()))
}

/// PHP: `runCommand($command)`: the exit code and the output lines
/// (standard output, then standard error, without trailing newlines).
async fn run(command: &Command) -> Result<(i32, Vec<String>), Error> {
    let output = execute(command, b"", None, None).await?;
    let mut printed = output.stdout;
    if !printed.is_empty() && !output.stderr.is_empty() {
        printed.push(b'\n');
    }
    printed.extend_from_slice(&output.stderr);
    let printed = php_std::string::rtrim(&printed, b"\n");
    let lines = if printed.is_empty() {
        Vec::new()
    } else {
        printed.split(|&b| b == b'\n').map(|l| String::from_utf8_lossy(l).into_owned()).collect()
    };
    Ok((output.status, lines))
}

/// PHP: `deletePath($path)`.
fn delete_path(path: &Path) {
    let Ok(meta) = std::fs::symlink_metadata(path) else { return };
    if !meta.is_dir() {
        let _ = std::fs::remove_file(path);
        return;
    }
    let _ = std::fs::remove_dir_all(path);
}
