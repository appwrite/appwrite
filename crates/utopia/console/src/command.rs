//! Shell-safe commands: plain commands built argument by argument, and
//! pipelines, `&&`/`||` lists, groups and redirections of them.

use std::fmt;

use serde_json::Value;
use utopia_validators::{Text, Validator};

use crate::Error;

/// A value a command argument is made from: a string or a number, written as
/// PHP's `(string)` writes it.
///
/// PHP: `string|int|float|Stringable`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Arg(String);

impl From<&str> for Arg {
    fn from(s: &str) -> Self {
        Arg(s.to_owned())
    }
}

impl From<String> for Arg {
    fn from(s: String) -> Self {
        Arg(s)
    }
}

impl From<&String> for Arg {
    fn from(s: &String) -> Self {
        Arg(s.clone())
    }
}

impl From<i64> for Arg {
    fn from(n: i64) -> Self {
        Arg(n.to_string())
    }
}

impl From<f64> for Arg {
    /// As PHP prints a float (`1.5`, `1.0E+25`, `0.1`).
    fn from(n: f64) -> Self {
        Arg(php_std::number::to_string(n))
    }
}

/// How an argument is checked before it is added.
///
/// PHP: the `Validator|callable` given to `option()` or `argument()`.
#[derive(Clone, Copy)]
pub enum Check<'a> {
    /// A validator; a refused argument's error quotes its description.
    Validator(&'a dyn Validator),
    /// A predicate; `false` refuses the argument.
    Predicate(&'a dyn Fn(&str) -> bool),
}

impl fmt::Debug for Check<'_> {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Check::Validator(v) => write!(f, "Validator({:?})", v.description()),
            Check::Predicate(_) => f.write_str("Predicate"),
        }
    }
}

/// The operator joining the commands of a list.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Operator {
    /// `|`: each command's output is the next one's input.
    Pipe,
    /// `&&`: each command runs only if the previous one succeeded.
    And,
    /// `||`: each command runs only if the previous one failed.
    Or,
}

impl Operator {
    /// The shell operator.
    pub fn as_str(self) -> &'static str {
        match self {
            Operator::Pipe => "|",
            Operator::And => "&&",
            Operator::Or => "||",
        }
    }
}

/// A redirection of a command's standard streams.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Redirect {
    /// `>`: standard output replaces the file.
    Stdout,
    /// `>>`: standard output is appended to the file.
    Append,
    /// `<`: standard input is read from the file.
    Input,
}

impl Redirect {
    /// The shell operator.
    pub fn as_str(self) -> &'static str {
        match self {
            Redirect::Stdout => ">",
            Redirect::Append => ">>",
            Redirect::Input => "<",
        }
    }
}

#[derive(Debug, Clone, PartialEq)]
enum Kind {
    Plain(Vec<String>),
    List(Operator, Vec<Command>),
    Group(Box<Command>),
    Redirect(Box<Command>, Redirect, String),
}

/// A command line built without string concatenation: a plain command
/// (an executable, flags, options and arguments, each one shell word), or a
/// pipeline, an `&&`/`||` list, a group or a redirection of commands.
///
/// A plain command runs without a shell ([`Command::to_array`]); the others
/// render to a shell string where every word is quoted
/// ([`Command::to_shell`]).
///
/// PHP: `Utopia\Console\Command`.
///
/// ```
/// use utopia_console::Command;
///
/// let tar = Command::new("tar")?.flag("-cz")?.option("-f", "archive.tar.gz")?.argument(".")?;
/// assert_eq!(tar.to_array()?, ["tar", "-cz", "-f", "archive.tar.gz", "."]);
///
/// let errors = Command::append_stdout(
///     Command::pipe(vec![Command::new("cat")?.argument("app.log")?, Command::new("grep")?.argument("ERROR")?])?,
///     "errors.log",
/// )?;
/// assert_eq!(errors.to_shell()?, "'cat' 'app.log' | 'grep' 'ERROR' >> 'errors.log'");
/// # Ok::<(), utopia_console::Error>(())
/// ```
///
/// ```php
/// use Utopia\Console\Command;
///
/// $tar = (new Command('tar'))->flag('-cz')->option('-f', 'archive.tar.gz')->argument('.');
/// echo implode(' ', $tar->toArray()), "\n"; // tar -cz -f archive.tar.gz .
///
/// $errors = Command::appendStdout(
///     Command::pipe((new Command('cat'))->argument('app.log'), (new Command('grep'))->argument('ERROR')),
///     'errors.log',
/// );
/// echo $errors, "\n"; // 'cat' 'app.log' | 'grep' 'ERROR' >> 'errors.log'
/// ```
#[derive(Debug, Clone, PartialEq)]
pub struct Command {
    kind: Kind,
}

/// `(string) $value`, refused when empty.
fn normalize(value: Arg, context: &str) -> Result<String, Error> {
    if value.0.is_empty() {
        return Err(Error::InvalidArgument(format!("{context} cannot be empty")));
    }
    Ok(value.0)
}

/// `^-[A-Za-z0-9]+$|^--[A-Za-z0-9][A-Za-z0-9_-]*$` (`single`: one character
/// after a single dash), where `$` also matches before a final newline.
fn is_key(key: &str, single: bool) -> bool {
    let key = key.strip_suffix('\n').unwrap_or(key).as_bytes();
    if let Some(rest) = key.strip_prefix(b"--")
        && let Some((first, tail)) = rest.split_first()
        && first.is_ascii_alphanumeric()
        && tail.iter().all(|b| b.is_ascii_alphanumeric() || *b == b'_' || *b == b'-')
    {
        return true;
    }
    match key.strip_prefix(b"-") {
        Some(rest) if single => rest.len() == 1 && rest[0].is_ascii_alphanumeric(),
        Some(rest) => !rest.is_empty() && rest.iter().all(u8::is_ascii_alphanumeric),
        None => false,
    }
}

/// The default check of options and arguments: `new Text(0)`.
const DEFAULT: Text = Text::new(0);

impl Command {
    /// A plain command running `executable`; [`Error::InvalidArgument`]
    /// (`Command executable cannot be empty`) for an empty one.
    ///
    /// PHP: `new Command($executable)`.
    pub fn new(executable: impl Into<Arg>) -> Result<Self, Error> {
        Ok(Self { kind: Kind::Plain(vec![normalize(executable.into(), "Command executable")?]) })
    }

    fn plain(&mut self) -> Result<&mut Vec<String>, Error> {
        match &mut self.kind {
            Kind::Plain(args) => Ok(args),
            _ => Err(Error::InvalidArgument(
                "Flags, options, and arguments can only be added to plain commands".to_owned(),
            )),
        }
    }

    fn check(argument: &str, check: Check<'_>) -> Result<(), Error> {
        match check {
            Check::Validator(v) => {
                if !v.is_valid(&Value::String(argument.to_owned())) {
                    return Err(Error::InvalidArgument(format!(
                        "Invalid command argument: {argument} ({})",
                        v.description()
                    )));
                }
            }
            Check::Predicate(p) => {
                if !p(argument) {
                    return Err(Error::InvalidArgument(format!("Invalid command argument: {argument}")));
                }
            }
        }
        Ok(())
    }

    /// Adds a flag: `-` and letters or digits (`-cz`), or `--` and a name
    /// (`--verbose`); [`Error::InvalidArgument`] (`Invalid command flag:
    /// <key>`) otherwise, or when the command is not plain.
    ///
    /// PHP: `flag($key)`.
    pub fn flag(mut self, key: &str) -> Result<Self, Error> {
        let args = self.plain()?;
        if !is_key(key, false) {
            return Err(Error::InvalidArgument(format!("Invalid command flag: {key}")));
        }
        args.push(key.to_owned());
        Ok(self)
    }

    /// Adds an option and its value as two words. The key is `-` and one
    /// letter or digit (`-f`), or `--` and a name (`--env`); the value must
    /// not be empty.
    ///
    /// PHP: `option($key, $value)`.
    pub fn option(self, key: &str, value: impl Into<Arg>) -> Result<Self, Error> {
        self.option_checked(key, value, Check::Validator(&DEFAULT))
    }

    /// Adds an option whose value `check` accepts.
    ///
    /// PHP: `option($key, $value, $validator)`.
    pub fn option_checked(mut self, key: &str, value: impl Into<Arg>, check: Check<'_>) -> Result<Self, Error> {
        let args = self.plain()?;
        if !is_key(key, true) {
            return Err(Error::InvalidArgument(format!("Invalid command option: {key}")));
        }
        let value = normalize(value.into(), "Command option value")?;
        Self::check(&value, check)?;
        args.push(key.to_owned());
        args.push(value);
        Ok(self)
    }

    /// Adds an argument; it must not be empty.
    ///
    /// PHP: `argument($value)`.
    pub fn argument(self, value: impl Into<Arg>) -> Result<Self, Error> {
        self.argument_checked(value, Check::Validator(&DEFAULT))
    }

    /// Adds an argument `check` accepts; [`Error::InvalidArgument`]
    /// (`Invalid command argument: <value>`, followed by the validator's
    /// description in parentheses) otherwise.
    ///
    /// PHP: `argument($value, $validator)`.
    ///
    /// ```
    /// use utopia_console::{Check, Command};
    ///
    /// let branch = |v: &str| ["main", "develop"].contains(&v);
    /// let git = Command::new("git")?.argument("checkout")?.argument_checked("develop", Check::Predicate(&branch))?;
    /// assert_eq!(git.to_array()?, ["git", "checkout", "develop"]);
    ///
    /// let error = Command::new("git")?.argument_checked("feature; rm -rf /", Check::Predicate(&branch)).unwrap_err();
    /// assert_eq!(error.to_string(), "Invalid command argument: feature; rm -rf /");
    /// # Ok::<(), utopia_console::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\Console\Command;
    ///
    /// $branch = fn (string $v): bool => in_array($v, ['main', 'develop'], true);
    /// $git = (new Command('git'))->argument('checkout')->argument('develop', $branch);
    /// echo implode(' ', $git->toArray()), "\n"; // git checkout develop
    ///
    /// try {
    ///     (new Command('git'))->argument('feature; rm -rf /', $branch);
    /// } catch (\InvalidArgumentException $e) {
    ///     echo $e->getMessage(), "\n"; // Invalid command argument: feature; rm -rf /
    /// }
    /// ```
    pub fn argument_checked(mut self, value: impl Into<Arg>, check: Check<'_>) -> Result<Self, Error> {
        let args = self.plain()?;
        let value = normalize(value.into(), "Command argument")?;
        Self::check(&value, check)?;
        args.push(value);
        Ok(self)
    }

    fn list(operator: Operator, commands: Vec<Command>) -> Result<Self, Error> {
        if commands.len() < 2 {
            return Err(Error::InvalidArgument("Composed commands require at least two commands".to_owned()));
        }
        Ok(Self { kind: Kind::List(operator, commands) })
    }

    /// Pipes each command's output into the next (`a | b`); at least two.
    ///
    /// PHP: `Command::pipe(...$commands)`.
    pub fn pipe(commands: Vec<Command>) -> Result<Self, Error> {
        Self::list(Operator::Pipe, commands)
    }

    /// Runs each command while the previous ones succeed (`a && b`); at least
    /// two.
    ///
    /// PHP: `Command::and(...$commands)`.
    pub fn and(commands: Vec<Command>) -> Result<Self, Error> {
        Self::list(Operator::And, commands)
    }

    /// Runs each command until one succeeds (`a || b`); at least two.
    ///
    /// PHP: `Command::or(...$commands)`.
    pub fn or(commands: Vec<Command>) -> Result<Self, Error> {
        Self::list(Operator::Or, commands)
    }

    /// Groups a command in a subshell (`( a )`), to set precedence.
    ///
    /// PHP: `Command::group($command)`.
    pub fn group(command: Command) -> Self {
        Self { kind: Kind::Group(Box::new(command)) }
    }

    fn redirect(redirect: Redirect, command: Command, path: impl Into<Arg>) -> Result<Self, Error> {
        let target = normalize(path.into(), "Command redirect target")?;
        Ok(Self { kind: Kind::Redirect(Box::new(command), redirect, target) })
    }

    /// Writes the command's output to a file (`a > file`).
    ///
    /// PHP: `Command::redirectStdout($command, $path)`.
    pub fn redirect_stdout(command: Command, path: impl Into<Arg>) -> Result<Self, Error> {
        Self::redirect(Redirect::Stdout, command, path)
    }

    /// Appends the command's output to a file (`a >> file`).
    ///
    /// PHP: `Command::appendStdout($command, $path)`.
    pub fn append_stdout(command: Command, path: impl Into<Arg>) -> Result<Self, Error> {
        Self::redirect(Redirect::Append, command, path)
    }

    /// Reads the command's input from a file (`a < file`).
    ///
    /// PHP: `Command::redirectInput($command, $path)`.
    pub fn redirect_input(command: Command, path: impl Into<Arg>) -> Result<Self, Error> {
        Self::redirect(Redirect::Input, command, path)
    }

    /// Whether this is a plain command (not a list, group or redirection).
    ///
    /// PHP: `isPlain()`.
    pub fn is_plain(&self) -> bool {
        matches!(self.kind, Kind::Plain(_))
    }

    /// The words of a plain command: the executable first;
    /// [`Error::InvalidArgument`] for any other command.
    ///
    /// PHP: `toArray()`.
    pub fn to_array(&self) -> Result<&[String], Error> {
        match &self.kind {
            Kind::Plain(args) => Ok(args),
            _ => Err(Error::InvalidArgument("Only plain commands can be converted to an array".to_owned())),
        }
    }

    /// The command as a shell string, every word quoted
    /// ([`php_std::string::escapeshellarg`]); [`Error::Value`] when a word
    /// holds a NUL byte.
    ///
    /// PHP: `toString()` and `__toString()`.
    pub fn to_shell(&self) -> Result<String, Error> {
        let mut out = Vec::new();
        self.write(&mut out)?;
        // Quoting keeps UTF-8 characters whole and drops nothing from UTF-8 input.
        Ok(String::from_utf8(out).unwrap_or_else(|e| String::from_utf8_lossy(e.as_bytes()).into_owned()))
    }

    fn write(&self, out: &mut Vec<u8>) -> Result<(), Error> {
        let quote = |out: &mut Vec<u8>, word: &str| -> Result<(), Error> {
            out.extend_from_slice(
                &php_std::string::escapeshellarg(word.as_bytes()).map_err(|e| Error::Value(e.message().to_owned()))?,
            );
            Ok(())
        };
        match &self.kind {
            Kind::Plain(args) => {
                for (i, a) in args.iter().enumerate() {
                    if i > 0 {
                        out.push(b' ');
                    }
                    quote(out, a)?;
                }
            }
            Kind::List(operator, commands) => {
                for (i, c) in commands.iter().enumerate() {
                    if i > 0 {
                        out.push(b' ');
                        out.extend_from_slice(operator.as_str().as_bytes());
                        out.push(b' ');
                    }
                    c.write(out)?;
                }
            }
            Kind::Group(c) => {
                out.extend_from_slice(b"( ");
                c.write(out)?;
                out.extend_from_slice(b" )");
            }
            Kind::Redirect(c, redirect, target) => {
                c.write(out)?;
                out.push(b' ');
                out.extend_from_slice(redirect.as_str().as_bytes());
                out.push(b' ');
                quote(out, target)?;
            }
        }
        Ok(())
    }
}

impl fmt::Display for Command {
    /// The shell string ([`Command::to_shell`]); fails for a word holding a
    /// NUL byte.
    ///
    /// PHP: `__toString()`.
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.to_shell().map_err(|_| fmt::Error)?)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn keys() {
        assert!(is_key("-cz", false));
        assert!(!is_key("-cz", true));
        assert!(is_key("--env", true));
        assert!(is_key("-v\n", false));
        assert!(!is_key("-v\n\n", false));
        assert!(!is_key("--", false));
        assert!(!is_key("verbose", false));
        assert!(is_key("--a-b_c", false));
        assert!(!is_key("---a", false));
    }

    #[test]
    fn nested() {
        let c = Command::redirect_stdout(
            Command::group(
                Command::and(vec![
                    Command::or(vec![Command::new("build").unwrap(), Command::new("build:fallback").unwrap()]).unwrap(),
                    Command::new("publish").unwrap(),
                ])
                .unwrap(),
            ),
            "deploy.log",
        )
        .unwrap();
        assert_eq!(c.to_shell().unwrap(), "( 'build' || 'build:fallback' && 'publish' ) > 'deploy.log'");
    }
}
