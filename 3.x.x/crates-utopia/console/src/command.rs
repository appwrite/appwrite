//! Safe shell command AST for argv and shell-string execution.

use crate::error::CommandError;
use regex::Regex;
use serde_json::Value;
use std::fmt;
use std::sync::OnceLock;
use utopia_validators::Validator;

const TYPE_PLAIN: &str = "plain";
const TYPE_COMPOSITE: &str = "composite";
const TYPE_GROUP: &str = "group";
const TYPE_REDIRECT: &str = "redirect";

const OPERATOR_PIPE: &str = "|";
const OPERATOR_AND: &str = "&&";
const OPERATOR_OR: &str = "||";

const REDIRECT_STDOUT: &str = ">";
const REDIRECT_APPEND_STDOUT: &str = ">>";
const REDIRECT_INPUT: &str = "<";

static FLAG_RE: OnceLock<Regex> = OnceLock::new();
static OPTION_RE: OnceLock<Regex> = OnceLock::new();

fn flag_re() -> &'static Regex {
    FLAG_RE.get_or_init(|| Regex::new(r"^-[A-Za-z0-9]+$|^--[A-Za-z0-9][A-Za-z0-9_-]*$").unwrap())
}

fn option_re() -> &'static Regex {
    OPTION_RE.get_or_init(|| Regex::new(r"^-[A-Za-z0-9]$|^--[A-Za-z0-9][A-Za-z0-9_-]*$").unwrap())
}

/// Optional validator for command argument and option values.
pub type CommandValidator = Box<dyn Fn(&str) -> bool + Send + Sync>;

/// Wrap a [`utopia_validators::Validator`] as a [`CommandValidator`] (PHP `Validator`).
#[must_use]
pub fn from_validator(validator: impl Validator + 'static) -> CommandValidator {
    Box::new(move |value| validator.is_valid(&Value::String(value.to_owned())))
}

fn default_validator() -> CommandValidator {
    Box::new(|_| true)
}

/// Shell command expression (plain argv, pipes, groups, redirects).
#[derive(Debug, Clone)]
pub struct Command {
    command_type: String,
    arguments: Vec<String>,
    commands: Vec<Command>,
    operator: Option<String>,
    inner: Option<Box<Command>>,
    redirect: Option<String>,
    redirect_target: Option<String>,
}

impl Command {
    /// Create a plain command starting with `executable`.
    pub fn new(executable: impl Into<String>) -> Result<Self, CommandError> {
        Ok(Self {
            command_type: TYPE_PLAIN.to_string(),
            arguments: vec![normalize(executable.into(), "Command executable")?],
            commands: Vec::new(),
            operator: None,
            inner: None,
            redirect: None,
            redirect_target: None,
        })
    }

    /// Pipe commands together (`|`).
    pub fn pipe(commands: Vec<Command>) -> Result<Self, CommandError> {
        compose(OPERATOR_PIPE, commands)
    }

    /// Chain commands with logical AND (`&&`).
    pub fn and(commands: Vec<Command>) -> Result<Self, CommandError> {
        compose(OPERATOR_AND, commands)
    }

    /// Chain commands with logical OR (`||`).
    pub fn or(commands: Vec<Command>) -> Result<Self, CommandError> {
        compose(OPERATOR_OR, commands)
    }

    /// Group a command in parentheses.
    pub fn group(command: Command) -> Self {
        Self {
            command_type: TYPE_GROUP.to_string(),
            arguments: Vec::new(),
            commands: Vec::new(),
            operator: None,
            inner: Some(Box::new(command)),
            redirect: None,
            redirect_target: None,
        }
    }

    /// Redirect stdout to `path` (`>`).
    pub fn redirect_stdout(
        command: Command,
        path: impl Into<String>,
    ) -> Result<Self, CommandError> {
        redirect(REDIRECT_STDOUT, command, path)
    }

    /// Append stdout to `path` (`>>`).
    pub fn append_stdout(command: Command, path: impl Into<String>) -> Result<Self, CommandError> {
        redirect(REDIRECT_APPEND_STDOUT, command, path)
    }

    /// Redirect stdin from `path` (`<`).
    pub fn redirect_input(command: Command, path: impl Into<String>) -> Result<Self, CommandError> {
        redirect(REDIRECT_INPUT, command, path)
    }

    /// Add a flag without a value (for example `-v` or `--verbose`).
    pub fn flag(mut self, key: impl Into<String>) -> Result<Self, CommandError> {
        self.ensure_plain()?;
        let key = key.into();
        if !flag_re().is_match(&key) {
            return Err(CommandError::InvalidFlag(key));
        }
        self.arguments.push(key);
        Ok(self)
    }

    /// Add an option key/value pair (for example `--env` / `prod`).
    pub fn option(
        mut self,
        key: impl Into<String>,
        value: impl Into<String>,
        validator: Option<CommandValidator>,
    ) -> Result<Self, CommandError> {
        self.ensure_plain()?;
        let key = key.into();
        if !option_re().is_match(&key) {
            return Err(CommandError::InvalidOption(key));
        }
        let argument = normalize(value.into(), "Command option value")?;
        validate(&argument, validator)?;
        self.arguments.push(key);
        self.arguments.push(argument);
        Ok(self)
    }

    /// Add a positional argument.
    pub fn argument(
        mut self,
        value: impl Into<String>,
        validator: Option<CommandValidator>,
    ) -> Result<Self, CommandError> {
        self.ensure_plain()?;
        let argument = normalize(value.into(), "Command argument")?;
        validate(&argument, validator)?;
        self.arguments.push(argument);
        Ok(self)
    }

    /// Returns whether this is a plain argv command.
    #[must_use]
    pub fn is_plain(&self) -> bool {
        self.command_type == TYPE_PLAIN
    }

    /// Convert a plain command to an argv array.
    pub fn to_array(&self) -> Result<Vec<String>, CommandError> {
        if !self.is_plain() {
            return Err(CommandError::NotPlain);
        }
        Ok(self.arguments.clone())
    }

    /// Render the command as a shell string with escaped arguments.
    pub fn to_string_shell(&self) -> Result<String, CommandError> {
        match self.command_type.as_str() {
            TYPE_PLAIN => Ok(self
                .arguments
                .iter()
                .map(|arg| escape_shell_arg(arg))
                .collect::<Vec<_>>()
                .join(" ")),
            TYPE_COMPOSITE => {
                let parts = self
                    .commands
                    .iter()
                    .map(Command::to_string_shell)
                    .collect::<Result<Vec<_>, _>>()?;
                let operator = self.operator.as_deref().unwrap_or("");
                Ok(parts.join(&format!(" {operator} ")))
            }
            TYPE_GROUP => {
                let inner = self
                    .inner
                    .as_ref()
                    .ok_or(CommandError::UnsupportedType)?
                    .to_string_shell()?;
                Ok(format!("( {inner} )"))
            }
            TYPE_REDIRECT => {
                let inner = self
                    .inner
                    .as_ref()
                    .ok_or(CommandError::UnsupportedType)?
                    .to_string_shell()?;
                let redirect = self.redirect.as_deref().unwrap_or("");
                let target = escape_shell_arg(self.redirect_target.as_deref().unwrap_or(""));
                Ok(format!("{inner} {redirect} {target}"))
            }
            _ => Err(CommandError::UnsupportedType),
        }
    }
}

impl fmt::Display for Command {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self.to_string_shell() {
            Ok(value) => write!(f, "{value}"),
            Err(_) => write!(f, "<invalid command>"),
        }
    }
}

fn compose(operator: &str, commands: Vec<Command>) -> Result<Command, CommandError> {
    if commands.len() < 2 {
        return Err(CommandError::CompositeTooFew);
    }

    Ok(Command {
        command_type: TYPE_COMPOSITE.to_string(),
        arguments: Vec::new(),
        commands,
        operator: Some(operator.to_string()),
        inner: None,
        redirect: None,
        redirect_target: None,
    })
}

fn redirect(
    redirect: &str,
    command: Command,
    path: impl Into<String>,
) -> Result<Command, CommandError> {
    Ok(Command {
        command_type: TYPE_REDIRECT.to_string(),
        arguments: Vec::new(),
        commands: Vec::new(),
        operator: None,
        inner: Some(Box::new(command)),
        redirect: Some(redirect.to_string()),
        redirect_target: Some(normalize(path.into(), "Command redirect target")?),
    })
}

fn ensure_plain(command: &Command) -> Result<(), CommandError> {
    if command.is_plain() {
        Ok(())
    } else {
        Err(CommandError::NotPlainMutation)
    }
}

impl Command {
    fn ensure_plain(&self) -> Result<(), CommandError> {
        ensure_plain(self)
    }
}

fn normalize(value: String, context: &'static str) -> Result<String, CommandError> {
    if value.is_empty() {
        return Err(CommandError::EmptyValue { context });
    }
    Ok(value)
}

fn validate(value: &str, validator: Option<CommandValidator>) -> Result<(), CommandError> {
    let validator = validator.unwrap_or_else(default_validator);
    if validator(value) {
        Ok(())
    } else {
        Err(CommandError::InvalidArgument {
            value: value.to_string(),
        })
    }
}

/// Escape a single argument the way PHP's `escapeshellarg` does.
#[must_use]
pub fn escape_shell_arg(value: &str) -> String {
    format!("'{}'", value.replace('\'', "'\\''"))
}

#[cfg(test)]
mod unit_tests {
    use super::*;

    #[test]
    fn escape_shell_arg_matches_php() {
        assert_eq!(
            escape_shell_arg("echo 'hello'; rm -rf /"),
            "'echo '\\''hello'\\''; rm -rf /'"
        );
    }
}
