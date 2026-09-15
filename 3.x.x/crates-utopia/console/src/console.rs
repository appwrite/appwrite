//! Console logging, prompts, process execution, and loop helpers.

use crate::command::Command;
use crate::error::ConsoleError;
use std::io::{self, IsTerminal, Read, Write};
use std::process::{Command as ProcessCommand, Stdio};
use std::sync::mpsc;
use std::thread;
use std::time::{Duration, Instant};

const GREEN: &str = "\x1b[32m";
const RED: &str = "\x1b[31m";
const BLUE: &str = "\x1b[34m";
const YELLOW: &str = "\x1b[1;33m";
const RESET: &str = "\x1b[0m";

/// What to execute via [`Console::execute`].
#[derive(Debug, Clone)]
pub enum ExecuteInput {
    /// Structured [`Command`] (argv when plain, shell string otherwise).
    Command(Command),
    /// Argv array (`program`, `arg1`, ...).
    Args(Vec<String>),
    /// Raw shell string executed via `sh -c`.
    Shell(String),
}

impl From<Command> for ExecuteInput {
    fn from(value: Command) -> Self {
        Self::Command(value)
    }
}

impl From<Vec<String>> for ExecuteInput {
    fn from(value: Vec<String>) -> Self {
        Self::Args(value)
    }
}

impl From<&[String]> for ExecuteInput {
    fn from(value: &[String]) -> Self {
        Self::Args(value.to_vec())
    }
}

impl From<&[&str]> for ExecuteInput {
    fn from(value: &[&str]) -> Self {
        Self::Args(value.iter().map(|s| (*s).to_string()).collect())
    }
}

impl From<&str> for ExecuteInput {
    fn from(value: &str) -> Self {
        Self::Shell(value.to_string())
    }
}

impl From<String> for ExecuteInput {
    fn from(value: String) -> Self {
        Self::Shell(value)
    }
}

/// CLI helpers for logging, prompts, and subprocess execution.
#[derive(Debug, Clone, Copy, Default)]
pub struct Console;

impl Console {
    /// Sets the process title visible in tools such as `top` and `ps`.
    ///
    /// On Linux this writes to `/proc/self/comm` (truncated to 15 bytes). On
    /// other platforms this is currently a no-op that returns `false`.
    pub fn title(title: &str) -> bool {
        #[cfg(target_os = "linux")]
        {
            let truncated = if title.len() > 15 {
                &title[..15]
            } else {
                title
            };
            std::fs::write("/proc/self/comm", truncated).is_ok()
        }

        #[cfg(not(target_os = "linux"))]
        {
            let _ = title;
            false
        }
    }

    /// Log a plain message to stdout.
    pub fn log(message: &str) -> io::Result<usize> {
        let mut stdout = io::stdout().lock();
        stdout.write_all(message.as_bytes())?;
        stdout.write_all(b"\n")?;
        stdout.flush()?;
        Ok(message.len() + 1)
    }

    /// Log a success message (green) to stdout.
    pub fn success(message: &str) -> io::Result<usize> {
        Self::colored_stdout(message, GREEN)
    }

    /// Log an error message (red) to stderr.
    pub fn error(message: &str) -> io::Result<usize> {
        Self::colored_stderr(message, RED)
    }

    /// Log an info message (blue) to stdout.
    pub fn info(message: &str) -> io::Result<usize> {
        Self::colored_stdout(message, BLUE)
    }

    /// Log a warning message (bold yellow) to stderr.
    pub fn warning(message: &str) -> io::Result<usize> {
        Self::colored_stderr(message, YELLOW)
    }

    /// Prompt for user input when interactive; otherwise returns an empty string.
    pub fn confirm(question: &str) -> io::Result<String> {
        if !Self::is_interactive() {
            return Ok(String::new());
        }

        Self::log(question)?;

        let mut line = String::new();
        io::stdin().read_line(&mut line)?;
        Ok(line.trim().to_string())
    }

    /// Terminate the current process with the provided exit status.
    pub fn exit(status: i32) -> ! {
        std::process::exit(status);
    }

    /// Returns whether stdin is an interactive terminal.
    pub fn is_interactive() -> bool {
        io::stdin().is_terminal()
    }

    /// Execute a command, capturing stdout/stderr and returning the exit code.
    ///
    /// `timeout_secs` values:
    /// - `-1` - no timeout
    /// - `> 0` - maximum runtime in seconds; returns `1` when exceeded
    pub fn execute(
        cmd: impl Into<ExecuteInput>,
        stdin: &str,
        stdout: &mut String,
        stderr: &mut String,
        timeout_secs: i64,
        mut on_progress: Option<&mut dyn FnMut(&str)>,
    ) -> i32 {
        let Ok(mut process) = build_process(cmd.into()) else {
            return 1;
        };
        process.stdin(Stdio::piped());
        process.stdout(Stdio::piped());
        process.stderr(Stdio::piped());

        let Ok(mut child) = process.spawn() else {
            return 1;
        };

        if let Some(mut stdin_pipe) = child.stdin.take() {
            if !stdin.is_empty() {
                let _ = stdin_pipe.write_all(stdin.as_bytes());
            }
        }

        let stdout_handle = child.stdout.take();
        let stderr_handle = child.stderr.take();

        let (stdout_tx, stdout_rx) = mpsc::channel();
        let (stderr_tx, stderr_rx) = mpsc::channel();

        let stdout_thread = stdout_handle.map(|mut pipe| {
            thread::spawn(move || {
                let mut buffer = [0_u8; 4096];
                loop {
                    match pipe.read(&mut buffer) {
                        Ok(0) => break,
                        Ok(count) => {
                            if stdout_tx.send(buffer[..count].to_vec()).is_err() {
                                break;
                            }
                        }
                        Err(error) if error.kind() == io::ErrorKind::Interrupted => {}
                        Err(_) => break,
                    }
                }
            })
        });

        let stderr_thread = stderr_handle.map(|mut pipe| {
            thread::spawn(move || {
                let mut buffer = [0_u8; 4096];
                loop {
                    match pipe.read(&mut buffer) {
                        Ok(0) => break,
                        Ok(count) => {
                            if stderr_tx.send(buffer[..count].to_vec()).is_err() {
                                break;
                            }
                        }
                        Err(error) if error.kind() == io::ErrorKind::Interrupted => {}
                        Err(_) => break,
                    }
                }
            })
        });

        let deadline = if timeout_secs > 0 {
            Some(Instant::now() + Duration::from_secs(timeout_secs as u64))
        } else {
            None
        };

        loop {
            drain_available_chunks(&stdout_rx, stdout, &mut on_progress);
            drain_available_chunks(&stderr_rx, stderr, &mut None);

            if let Some(deadline) = deadline {
                if Instant::now() >= deadline {
                    let _ = child.kill();
                    let _ = child.wait();
                    join_output_threads(stdout_thread, stderr_thread);
                    drain_available_chunks(&stdout_rx, stdout, &mut on_progress);
                    drain_available_chunks(&stderr_rx, stderr, &mut None);
                    return 1;
                }
            }

            let wait_result = child.try_wait();
            let Ok(status) = wait_result else {
                return 1;
            };

            match status {
                Some(exit_status) => {
                    join_output_threads(stdout_thread, stderr_thread);
                    drain_available_chunks(&stdout_rx, stdout, &mut on_progress);
                    drain_available_chunks(&stderr_rx, stderr, &mut None);
                    return exit_status.code().unwrap_or(1);
                }
                None => thread::sleep(Duration::from_millis(10)),
            }
        }
    }

    /// Repeatedly run `callback`, sleeping between iterations.
    ///
    /// PHP `Console::loop` runs until interrupted; this helper also allows the
    /// callback to stop the loop by returning `Ok(false)`.
    ///
    /// The callback returns `Ok(true)` to continue or `Ok(false)` to stop.
    /// Errors are forwarded to `on_error` when provided; otherwise they are
    /// returned immediately.
    pub fn run_loop<F, E>(
        mut callback: F,
        sleep_secs: f64,
        delay_secs: f64,
        mut on_error: Option<&mut dyn FnMut(E)>,
    ) -> Result<(), E>
    where
        F: FnMut() -> Result<bool, E>,
    {
        if delay_secs > 0.0 {
            thread::sleep(duration_from_secs(delay_secs));
        }

        loop {
            let start = Instant::now();
            match callback() {
                Ok(true) => {}
                Ok(false) => return Ok(()),
                Err(error) => {
                    if let Some(handler) = on_error.as_deref_mut() {
                        handler(error);
                    } else {
                        return Err(error);
                    }
                }
            }

            let elapsed = start.elapsed();
            let target = duration_from_secs(sleep_secs);
            if target > elapsed {
                thread::sleep(target - elapsed);
            }
        }
    }

    /// Test helper that limits [`Console::run_loop`] to `max_iterations`.
    pub fn run_loop_with_max_iterations<F, E>(
        mut callback: F,
        sleep_secs: f64,
        delay_secs: f64,
        max_iterations: usize,
        on_error: Option<&mut dyn FnMut(E)>,
    ) -> Result<(), E>
    where
        F: FnMut() -> Result<bool, E>,
    {
        let mut remaining = max_iterations;
        Self::run_loop(
            move || {
                if remaining == 0 {
                    return Ok(false);
                }
                remaining -= 1;
                callback()
            },
            sleep_secs,
            delay_secs,
            on_error,
        )
    }

    /// PHP-compatible infinite loop alias for [`Self::run_loop_forever`].
    pub fn r#loop<F, E>(
        callback: F,
        sleep_secs: f64,
        delay_secs: f64,
        on_error: Option<&mut dyn FnMut(E)>,
    ) -> Result<(), E>
    where
        F: FnMut() -> Result<(), E>,
    {
        Self::run_loop_forever(callback, sleep_secs, delay_secs, on_error)
    }

    /// Repeatedly run `callback` until interrupted, matching PHP `Console::loop`.
    pub fn run_loop_forever<F, E>(
        mut callback: F,
        sleep_secs: f64,
        delay_secs: f64,
        on_error: Option<&mut dyn FnMut(E)>,
    ) -> Result<(), E>
    where
        F: FnMut() -> Result<(), E>,
    {
        Self::run_loop(
            move || callback().map(|()| true),
            sleep_secs,
            delay_secs,
            on_error,
        )
    }
}

impl Console {
    fn colored_stdout(message: &str, color: &str) -> io::Result<usize> {
        let mut stdout = io::stdout().lock();
        stdout.write_all(color.as_bytes())?;
        stdout.write_all(message.as_bytes())?;
        stdout.write_all(RESET.as_bytes())?;
        stdout.write_all(b"\n")?;
        stdout.flush()?;
        Ok(color.len() + message.len() + RESET.len() + 1)
    }

    fn colored_stderr(message: &str, color: &str) -> io::Result<usize> {
        let mut stderr = io::stderr().lock();
        stderr.write_all(color.as_bytes())?;
        stderr.write_all(message.as_bytes())?;
        stderr.write_all(RESET.as_bytes())?;
        stderr.write_all(b"\n")?;
        stderr.flush()?;
        Ok(color.len() + message.len() + RESET.len() + 1)
    }
}

fn build_process(cmd: ExecuteInput) -> Result<ProcessCommand, ConsoleError> {
    match cmd {
        ExecuteInput::Command(command) => {
            if command.is_plain() {
                let args = command
                    .to_array()
                    .map_err(|_| ConsoleError::Spawn(io::Error::other("command is not plain")))?;
                let mut process = ProcessCommand::new(&args[0]);
                process.args(&args[1..]);
                Ok(process)
            } else {
                let shell = command
                    .to_string_shell()
                    .map_err(|_| ConsoleError::Spawn(io::Error::other("invalid command")))?;
                Ok(shell_process(&shell))
            }
        }
        ExecuteInput::Args(args) => {
            if args.is_empty() {
                return Err(ConsoleError::Spawn(io::Error::other(
                    "command args cannot be empty",
                )));
            }
            let mut process = ProcessCommand::new(&args[0]);
            process.args(&args[1..]);
            Ok(process)
        }
        ExecuteInput::Shell(shell) => Ok(shell_process(&shell)),
    }
}

fn shell_process(shell: &str) -> ProcessCommand {
    #[cfg(unix)]
    {
        let mut process = ProcessCommand::new("sh");
        process.arg("-c").arg(shell);
        process
    }

    #[cfg(windows)]
    {
        let mut process = ProcessCommand::new("cmd");
        process.arg("/C").arg(shell);
        process
    }
}

fn duration_from_secs(secs: f64) -> Duration {
    Duration::from_secs_f64(secs.max(0.0))
}

fn drain_available_chunks(
    receiver: &mpsc::Receiver<Vec<u8>>,
    output: &mut String,
    on_progress: &mut Option<&mut dyn FnMut(&str)>,
) {
    while let Ok(bytes) = receiver.try_recv() {
        let chunk = String::from_utf8_lossy(&bytes);
        if let Some(callback) = on_progress.as_deref_mut() {
            if !chunk.is_empty() && chunk != "0" {
                callback(&chunk);
            }
        }
        output.push_str(&chunk);
    }
}

fn join_output_threads(
    stdout_thread: Option<thread::JoinHandle<()>>,
    stderr_thread: Option<thread::JoinHandle<()>>,
) {
    if let Some(handle) = stdout_thread {
        let _ = handle.join();
    }
    if let Some(handle) = stderr_thread {
        let _ = handle.join();
    }
}

/// Format helpers exposed for tests.
#[doc(hidden)]
pub mod ansi {
    pub const GREEN: &str = super::GREEN;
    pub const RED: &str = super::RED;
    pub const BLUE: &str = super::BLUE;
    pub const YELLOW: &str = super::YELLOW;
    pub const RESET: &str = super::RESET;

    #[must_use]
    pub fn format_success(message: &str) -> String {
        format!("{GREEN}{message}{RESET}\n")
    }

    #[must_use]
    pub fn format_error(message: &str) -> String {
        format!("{RED}{message}{RESET}\n")
    }

    #[must_use]
    pub fn format_info(message: &str) -> String {
        format!("{BLUE}{message}{RESET}\n")
    }

    #[must_use]
    pub fn format_warning(message: &str) -> String {
        format!("{YELLOW}{message}{RESET}\n")
    }

    #[must_use]
    pub fn format_log(message: &str) -> String {
        format!("{message}\n")
    }
}
