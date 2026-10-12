//! Running commands: input, output, progress and a timeout.

use std::process::Stdio;
use std::time::{Duration, Instant};

use tokio::io::{AsyncRead, AsyncReadExt, AsyncWriteExt};

use crate::{Command, Error};

/// A callback receiving each piece of output as it arrives.
///
/// PHP: the `$onProgress` callable of `Console::execute()`.
pub type Progress<'p> = &'p mut (dyn FnMut(&[u8]) + Send);

/// What to run.
///
/// PHP: the `Command|array|string $cmd` of `Console::execute()`.
#[derive(Debug, Clone, Copy)]
pub enum Program<'a> {
    /// A command: a plain one runs without a shell, any other through
    /// `/bin/sh`.
    Command(&'a Command),
    /// An executable and its arguments, run without a shell.
    Argv(&'a [&'a str]),
    /// A shell command line, run by `/bin/sh`.
    Shell(&'a str),
}

impl<'a> From<&'a Command> for Program<'a> {
    fn from(c: &'a Command) -> Self {
        Program::Command(c)
    }
}

impl<'a> From<&'a [&'a str]> for Program<'a> {
    fn from(a: &'a [&'a str]) -> Self {
        Program::Argv(a)
    }
}

impl<'a, const N: usize> From<&'a [&'a str; N]> for Program<'a> {
    fn from(a: &'a [&'a str; N]) -> Self {
        Program::Argv(a)
    }
}

impl<'a> From<&'a str> for Program<'a> {
    fn from(s: &'a str) -> Self {
        Program::Shell(s)
    }
}

/// What a run produced.
///
/// PHP: `Console::execute()`'s return value and its `$stdout` and `$stderr`
/// references.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Output {
    /// The exit status: the command's code (0 to 255); for a shell command
    /// the shell's status of it (128 + the signal when a signal ended it);
    /// `-1` when a signal ended the process run; `1` when it timed out or
    /// could not be started.
    pub status: i32,
    /// What it wrote to standard output (up to the timeout).
    pub stdout: Vec<u8>,
    /// What it wrote to standard error (up to the timeout).
    pub stderr: Vec<u8>,
}

/// Runs `program` with `stdin` as its input, collecting its output, and
/// returns its exit status and output.
///
/// `on_progress` receives each piece of standard output as it arrives. A
/// piece of output (or of error output) that is exactly `0` is dropped, as
/// PHP drops it. With a `timeout`, a run lasting
/// longer is killed and reported with status 1 and the output read so far.
///
/// A shell command runs as `/bin/sh -c '( <command> ) 3>/dev/null'`, so its
/// status is the shell's `$?` of it. A program that cannot be started
/// reports status 1 and no output.
///
/// PHP: `Console::execute($cmd, $stdin, &$stdout, &$stderr, $timeout,
/// $onProgress)`, polling the pipes every 10 ms; a timeout is checked in
/// whole seconds (`time()`), so PHP kills up to a second later.
///
/// ```
/// # tokio::runtime::Builder::new_current_thread().enable_all().build().unwrap().block_on(async {
/// use utopia_console::{execute, Command};
///
/// let sort = Command::new("sort")?;
/// let output = execute(&sort, b"delta\nalpha\n", None, None).await?;
/// assert_eq!(output.status, 0);
/// assert_eq!(output.stdout, b"alpha\ndelta\n");
///
/// let output = execute("echo hello; exit 3", b"", None, None).await?;
/// assert_eq!((output.status, output.stdout.as_slice()), (3, &b"hello\n"[..]));
/// # Ok::<(), utopia_console::Error>(())
/// # }).unwrap();
/// ```
///
/// ```php
/// use Utopia\Console\Command;
/// use Utopia\Console\Console;
///
/// $stdout = $stderr = '';
/// $status = Console::execute(new Command('sort'), "delta\nalpha\n", $stdout, $stderr);
/// echo $status, ' ', json_encode($stdout), "\n"; // 0 "alpha\ndelta\n"
///
/// $status = Console::execute('echo hello; exit 3', '', $stdout, $stderr);
/// echo $status, ' ', json_encode($stdout), "\n"; // 3 "hello\n"
/// ```
pub async fn execute<'a>(
    program: impl Into<Program<'a>>,
    stdin: &[u8],
    timeout: Option<Duration>,
    mut on_progress: Option<Progress<'_>>,
) -> Result<Output, Error> {
    let mut process = match program.into() {
        Program::Command(c) if c.is_plain() => argv(c.to_array()?.iter().map(String::as_str))?,
        Program::Command(c) => shell(&c.to_shell()?),
        Program::Argv(a) => argv(a.iter().copied())?,
        Program::Shell(s) => shell(s),
    };
    process.stdin(Stdio::piped()).stdout(Stdio::piped()).stderr(Stdio::piped()).kill_on_drop(false);
    let Ok(mut child) = process.spawn() else {
        return Ok(Output { status: 1, ..Output::default() });
    };

    let mut input = child.stdin.take();
    let data = stdin.to_vec();
    let writer = tokio::spawn(async move {
        if let Some(input) = input.as_mut() {
            let _ = input.write_all(&data).await;
            let _ = input.shutdown().await;
        }
        drop(input);
    });

    let mut out = child.stdout.take();
    let mut err = child.stderr.take();
    let mut output = Output::default();
    let start = Instant::now();
    let (mut obuf, mut ebuf) = (vec![0u8; 8192], vec![0u8; 8192]);
    let status = loop {
        let deadline = async {
            match timeout {
                Some(t) => tokio::time::sleep(t.saturating_sub(start.elapsed())).await,
                None => std::future::pending().await,
            }
        };
        tokio::select! {
            n = read(&mut out, &mut obuf) => match n {
                Some(n) => collect(&obuf[..n], &mut output.stdout, on_progress.as_mut()),
                None => out = None,
            },
            n = read(&mut err, &mut ebuf) => match n {
                Some(n) => collect(&ebuf[..n], &mut output.stderr, None),
                None => err = None,
            },
            status = child.wait() => break status.ok(),
            () = deadline => {
                let _ = child.start_kill();
                writer.abort();
                output.status = 1;
                return Ok(output);
            }
        }
    };
    // What was written before the exit; a process the command left running
    // may keep the pipes open, so the drain does not wait for their end.
    drain(&mut out, &mut obuf, &mut output.stdout, on_progress.as_mut()).await;
    drain(&mut err, &mut ebuf, &mut output.stderr, None).await;
    writer.abort();
    output.status = match status {
        Some(s) => s.code().unwrap_or(-1),
        None => 1,
    };
    Ok(output)
}

fn argv<'a>(mut words: impl Iterator<Item = &'a str>) -> Result<tokio::process::Command, Error> {
    let program = words
        .next()
        .ok_or_else(|| Error::Value("proc_open(): Argument #1 ($command) must have at least one element".to_owned()))?;
    let mut command = tokio::process::Command::new(program);
    command.args(words);
    Ok(command)
}

fn shell(line: &str) -> tokio::process::Command {
    let mut command = tokio::process::Command::new("/bin/sh");
    command.arg("-c").arg(format!("( {line} ) 3>/dev/null"));
    command
}

/// One read from an open pipe; `None` at its end (or on an error). A closed
/// pipe never completes.
async fn read<R: AsyncRead + Unpin>(pipe: &mut Option<R>, buf: &mut [u8]) -> Option<usize> {
    match pipe {
        Some(p) => match p.read(buf).await {
            Ok(0) | Err(_) => None,
            Ok(n) => Some(n),
        },
        None => std::future::pending().await,
    }
}

/// Reads what is left in a pipe, stopping when nothing arrives for 10 ms.
async fn drain<R: AsyncRead + Unpin>(
    pipe: &mut Option<R>,
    buf: &mut [u8],
    into: &mut Vec<u8>,
    mut progress: Option<&mut Progress<'_>>,
) {
    let Some(p) = pipe.as_mut() else { return };
    while let Ok(Ok(n)) = tokio::time::timeout(Duration::from_millis(10), p.read(buf)).await {
        if n == 0 {
            break;
        }
        collect(&buf[..n], into, progress.as_deref_mut());
    }
}

/// Keeps a piece of output and reports it, except a piece that is exactly
/// `0`, which PHP's `stream_get_contents(...) ?: ''` drops as falsy.
fn collect(chunk: &[u8], into: &mut Vec<u8>, progress: Option<&mut Progress<'_>>) {
    if chunk == b"0" {
        return;
    }
    if let Some(progress) = progress {
        progress(chunk);
    }
    into.extend_from_slice(chunk);
}
