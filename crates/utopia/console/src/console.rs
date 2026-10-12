//! Log lines, prompts, the process title, exiting, and repeating work.

use std::convert::Infallible;
use std::future::Future;
use std::io::{self, BufRead, Stderr, Stdout, Write};
use std::time::{Duration, Instant};

/// Writes log lines to an output and an error stream: plain and green,
/// blue lines to the output, red and yellow ones to the error stream, each
/// followed by a newline.
///
/// PHP: the static logging methods of `Utopia\Console\Console`, which write
/// to `STDOUT` and `STDERR`; [`Console::stdio`] (and the free functions
/// [`log`], [`success`], ...) write there too.
///
/// ```
/// use utopia_console::Console;
///
/// let mut console = Console::new(Vec::new(), Vec::new());
/// assert_eq!(console.success("deployed")?, 18);
/// assert_eq!(console.log("done")?, 5);
/// assert_eq!(console.out(), b"\x1b[32mdeployed\x1b[0m\ndone\n");
/// assert_eq!(console.error("failed")?, 16);
/// assert_eq!(console.err(), b"\x1b[31mfailed\x1b[0m\n");
/// # Ok::<(), std::io::Error>(())
/// ```
///
/// ```php
/// use Utopia\Console\Console;
///
/// $written = Console::success('deployed'); // "\033[32mdeployed\033[0m\n" on STDOUT
/// Console::log((string) $written);         // 18
/// ```
#[derive(Debug)]
pub struct Console<O: Write, E: Write> {
    out: O,
    err: E,
}

impl Console<Stdout, Stderr> {
    /// A console writing to the process's standard output and error.
    pub fn stdio() -> Self {
        Self { out: io::stdout(), err: io::stderr() }
    }
}

impl<O: Write, E: Write> Console<O, E> {
    /// A console writing to `out` and `err`.
    pub fn new(out: O, err: E) -> Self {
        Self { out, err }
    }

    /// The output stream.
    pub fn out(&self) -> &O {
        &self.out
    }

    /// The error stream.
    pub fn err(&self) -> &E {
        &self.err
    }

    /// The two streams.
    pub fn into_inner(self) -> (O, E) {
        (self.out, self.err)
    }

    fn write(stream: &mut dyn Write, prefix: &str, message: &str, suffix: &str) -> io::Result<usize> {
        let line = [prefix.as_bytes(), message.as_bytes(), suffix.as_bytes()].concat();
        stream.write_all(&line)?;
        stream.flush()?;
        Ok(line.len())
    }

    /// Writes `message` and a newline to the output; returns the bytes
    /// written.
    ///
    /// PHP: `Console::log($message)`.
    pub fn log(&mut self, message: &str) -> io::Result<usize> {
        Self::write(&mut self.out, "", message, "\n")
    }

    /// Writes `message` in green to the output.
    ///
    /// PHP: `Console::success($message)`.
    pub fn success(&mut self, message: &str) -> io::Result<usize> {
        Self::write(&mut self.out, "\x1b[32m", message, "\x1b[0m\n")
    }

    /// Writes `message` in red to the error stream.
    ///
    /// PHP: `Console::error($message)`.
    pub fn error(&mut self, message: &str) -> io::Result<usize> {
        Self::write(&mut self.err, "\x1b[31m", message, "\x1b[0m\n")
    }

    /// Writes `message` in blue to the output.
    ///
    /// PHP: `Console::info($message)`.
    pub fn info(&mut self, message: &str) -> io::Result<usize> {
        Self::write(&mut self.out, "\x1b[34m", message, "\x1b[0m\n")
    }

    /// Writes `message` in bold yellow to the error stream.
    ///
    /// PHP: `Console::warning($message)`.
    pub fn warning(&mut self, message: &str) -> io::Result<usize> {
        Self::write(&mut self.err, "\x1b[1;33m", message, "\x1b[0m\n")
    }

    /// Writes `question` as a log line, then reads one line from `input`
    /// and returns it without surrounding whitespace (spaces, tabs,
    /// newlines, NUL, vertical tabs); empty at the end of the input.
    ///
    /// PHP: `Console::confirm($question)`, reading `php://stdin`.
    pub fn confirm(&mut self, question: &str, input: &mut dyn BufRead) -> io::Result<String> {
        if !is_interactive() {
            return Ok(String::new());
        }
        self.log(question)?;
        let mut line = Vec::new();
        input.read_until(b'\n', &mut line)?;
        let line = php_std::string::trim(&line, php_std::string::TRIM_CHARACTERS);
        Ok(String::from_utf8_lossy(line).into_owned())
    }
}

/// Writes `message` and a newline to standard output.
///
/// PHP: `Console::log($message)`.
pub fn log(message: &str) -> io::Result<usize> {
    Console::stdio().log(message)
}

/// Writes `message` in green to standard output.
///
/// PHP: `Console::success($message)`.
pub fn success(message: &str) -> io::Result<usize> {
    Console::stdio().success(message)
}

/// Writes `message` in red to standard error.
///
/// PHP: `Console::error($message)`.
pub fn error(message: &str) -> io::Result<usize> {
    Console::stdio().error(message)
}

/// Writes `message` in blue to standard output.
///
/// PHP: `Console::info($message)`.
pub fn info(message: &str) -> io::Result<usize> {
    Console::stdio().info(message)
}

/// Writes `message` in bold yellow to standard error.
///
/// PHP: `Console::warning($message)`.
pub fn warning(message: &str) -> io::Result<usize> {
    Console::stdio().warning(message)
}

/// Asks `question` on standard output and reads the answer from standard
/// input.
///
/// PHP: `Console::confirm($question)`.
pub fn confirm(question: &str) -> io::Result<String> {
    Console::stdio().confirm(question, &mut io::stdin().lock())
}

/// Sets the process title tools such as `top` and `ps` show; whether it
/// was set.
///
/// On Linux the process's name (`comm`, truncated by the kernel to 15
/// bytes) is set; other systems have no way to rename a running Rust
/// process without `unsafe` code, and `false` is returned.
///
/// PHP: `Console::title($title)` (`cli_set_process_title()`), which rewrites
/// the process's arguments.
pub fn title(title: &str) -> bool {
    if cfg!(target_os = "linux") { std::fs::write("/proc/self/comm", title.as_bytes()).is_ok() } else { false }
}

/// Ends the process with `status`.
///
/// PHP: `Console::exit($status)`.
pub fn exit(status: i32) -> ! {
    std::process::exit(status)
}

/// Whether the process can prompt: always, for a command-line program.
///
/// PHP: `Console::isInteractive()`: whether PHP runs from the command line
/// with a standard output.
pub fn is_interactive() -> bool {
    true
}

/// Calls `callback` forever, starting after `delay`, once every `sleep`: the
/// time a call takes, in whole seconds, is subtracted from the pause after
/// it.
///
/// A call that fails is passed to `on_error`, and the loop goes on; when
/// `on_error` fails too, or there is none, the loop ends with that error.
///
/// PHP: `Console::loop($callback, $sleep, $delay, $onError)`, timed with
/// `time()`. PHP's engine errors (`\Error`) skip `$onError`; Rust has none.
///
/// ```
/// # tokio::runtime::Builder::new_current_thread().enable_all().build().unwrap().block_on(async {
/// use std::time::Duration;
/// use utopia_console::repeat;
///
/// let mut runs = 0;
/// let error = repeat(
///     || {
///         runs += 1;
///         let run = runs;
///         async move { if run == 3 { Err(format!("stopped at {run}")) } else { Ok(()) } }
///     },
///     Duration::ZERO,
///     Duration::ZERO,
///     None::<fn(String) -> Result<(), String>>,
/// )
/// .await
/// .unwrap_err();
/// assert_eq!(error, "stopped at 3");
/// # });
/// ```
///
/// ```php
/// use Utopia\Console\Console;
///
/// $runs = 0;
/// try {
///     Console::loop(function () use (&$runs) {
///         if (++$runs === 3) {
///             throw new \Exception("stopped at $runs");
///         }
///     }, 0);
/// } catch (\Exception $e) {
///     echo $e->getMessage(), "\n"; // stopped at 3
/// }
/// ```
pub async fn repeat<F, Fut, E, H>(
    mut callback: F,
    sleep: Duration,
    delay: Duration,
    mut on_error: Option<H>,
) -> Result<Infallible, E>
where
    F: FnMut() -> Fut,
    Fut: Future<Output = Result<(), E>>,
    H: FnMut(E) -> Result<(), E>,
{
    if !delay.is_zero() {
        tokio::time::sleep(delay).await;
    }
    loop {
        let start = Instant::now();
        if let Err(e) = callback().await {
            match on_error.as_mut() {
                Some(handler) => handler(e)?,
                None => return Err(e),
            }
        }
        let spent = Duration::from_secs(start.elapsed().as_secs());
        let pause = sleep.saturating_sub(spent);
        if !pause.is_zero() {
            tokio::time::sleep(pause).await;
        }
    }
}
