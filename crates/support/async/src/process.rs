//! Processes: [`Command`] spawns a [`Child`], whose pipes and exit are
//! awaited without blocking the runtime.
//!
//! PHP: `proc_open()`, `Swoole\Coroutine\System::exec()`,
//! `Swoole\Process`.

use std::ffi::OsStr;
use std::io;
use std::path::Path;

#[doc(no_inline)]
pub use std::process::{ExitStatus, Output, Stdio};

use crate::Engine;
use crate::adapter::{self, Adapter, Child as _};
use crate::io::{AsyncReadExt, impl_read, impl_write};

type Spawn = <Engine as Adapter>::Command;
type Process = <Engine as Adapter>::Child;

/// A process to run: the program, its arguments, environment, working
/// directory and standard streams.
///
/// ```
/// use utopia_async::process::Command;
///
/// utopia_async::run(async {
///     let output = Command::new("echo").arg("hello").output().await?;
///     assert_eq!(output.stdout, b"hello\n");
///     Ok::<(), std::io::Error>(())
/// })?;
/// # Ok::<(), std::io::Error>(())
/// ```
///
/// PHP: `proc_open($command, $descriptors, $pipes, $cwd, $env)`.
#[derive(Debug)]
pub struct Command {
    inner: Spawn,
}

impl Command {
    /// A command running `program` (looked up in `PATH` when it has no
    /// slash).
    pub fn new(program: impl AsRef<OsStr>) -> Command {
        Command { inner: adapter::Command::new(program.as_ref()) }
    }

    fn std(&mut self) -> &mut std::process::Command {
        adapter::Command::as_std_mut(&mut self.inner)
    }

    /// Adds an argument.
    pub fn arg(&mut self, arg: impl AsRef<OsStr>) -> &mut Command {
        self.std().arg(arg);
        self
    }

    /// Adds arguments.
    pub fn args<I, S>(&mut self, args: I) -> &mut Command
    where
        I: IntoIterator<Item = S>,
        S: AsRef<OsStr>,
    {
        self.std().args(args);
        self
    }

    /// Sets an environment variable.
    pub fn env(&mut self, key: impl AsRef<OsStr>, value: impl AsRef<OsStr>) -> &mut Command {
        self.std().env(key, value);
        self
    }

    /// Sets environment variables.
    pub fn envs<I, K, V>(&mut self, vars: I) -> &mut Command
    where
        I: IntoIterator<Item = (K, V)>,
        K: AsRef<OsStr>,
        V: AsRef<OsStr>,
    {
        self.std().envs(vars);
        self
    }

    /// Removes an environment variable.
    pub fn env_remove(&mut self, key: impl AsRef<OsStr>) -> &mut Command {
        self.std().env_remove(key);
        self
    }

    /// Starts from an empty environment.
    pub fn env_clear(&mut self) -> &mut Command {
        self.std().env_clear();
        self
    }

    /// The working directory.
    pub fn current_dir(&mut self, dir: impl AsRef<Path>) -> &mut Command {
        self.std().current_dir(dir);
        self
    }

    /// What the process reads as standard input.
    pub fn stdin(&mut self, stdin: impl Into<Stdio>) -> &mut Command {
        self.std().stdin(stdin);
        self
    }

    /// Where the process's standard output goes.
    pub fn stdout(&mut self, stdout: impl Into<Stdio>) -> &mut Command {
        self.std().stdout(stdout);
        self
    }

    /// Where the process's standard error goes.
    pub fn stderr(&mut self, stderr: impl Into<Stdio>) -> &mut Command {
        self.std().stderr(stderr);
        self
    }

    /// Kills the process when its [`Child`] is dropped before it exits.
    pub fn kill_on_drop(&mut self, kill: bool) -> &mut Command {
        adapter::Command::kill_on_drop(&mut self.inner, kill);
        self
    }

    /// The standard library command this one configures.
    pub fn as_std(&self) -> &std::process::Command {
        adapter::Command::as_std(&self.inner)
    }

    /// Starts the process.
    pub fn spawn(&mut self) -> io::Result<Child> {
        let mut inner = adapter::Command::spawn(&mut self.inner)?;
        Ok(Child {
            stdin: inner.take_stdin().map(|inner| ChildStdin { inner }),
            stdout: inner.take_stdout().map(|inner| ChildStdout { inner }),
            stderr: inner.take_stderr().map(|inner| ChildStderr { inner }),
            inner,
        })
    }

    /// Runs the process to its end, collecting its output (standard output
    /// and error are piped unless configured otherwise).
    ///
    /// PHP: `Swoole\Coroutine\System::exec()`.
    pub async fn output(&mut self) -> io::Result<Output> {
        self.stdout(Stdio::piped()).stderr(Stdio::piped());
        self.spawn()?.wait_with_output().await
    }

    /// Runs the process to its end and returns its exit status.
    pub async fn status(&mut self) -> io::Result<ExitStatus> {
        self.spawn()?.wait().await
    }
}

/// A spawned process. Its piped streams are in [`Child::stdin`],
/// [`Child::stdout`] and [`Child::stderr`], to take.
#[derive(Debug)]
pub struct Child {
    /// The process's standard input, when piped.
    pub stdin: Option<ChildStdin>,
    /// The process's standard output, when piped.
    pub stdout: Option<ChildStdout>,
    /// The process's standard error, when piped.
    pub stderr: Option<ChildStderr>,
    inner: Process,
}

impl Child {
    /// The OS process id; `None` once it has been waited for.
    pub fn id(&self) -> Option<u32> {
        self.inner.id()
    }

    /// Waits for the process to exit. Closes its standard input first, so a
    /// process reading it to its end can finish.
    pub async fn wait(&mut self) -> io::Result<ExitStatus> {
        drop(self.stdin.take());
        self.inner.wait().await
    }

    /// The exit status if the process has exited, without waiting.
    pub fn try_wait(&mut self) -> io::Result<Option<ExitStatus>> {
        self.inner.try_wait()
    }

    /// Sends the kill signal, without waiting for the process to exit.
    pub fn start_kill(&mut self) -> io::Result<()> {
        self.inner.start_kill()
    }

    /// Kills the process and waits for it to exit.
    pub async fn kill(&mut self) -> io::Result<()> {
        self.start_kill()?;
        self.wait().await?;
        Ok(())
    }

    /// Waits for the process to exit, reading its piped standard output and
    /// error to their ends meanwhile.
    pub async fn wait_with_output(mut self) -> io::Result<Output> {
        async fn read_to_end<R: crate::io::AsyncRead + Unpin>(pipe: Option<R>) -> io::Result<Vec<u8>> {
            let mut bytes = Vec::new();
            if let Some(mut pipe) = pipe {
                pipe.read_to_end(&mut bytes).await?;
            }
            Ok(bytes)
        }
        let (stdout, stderr) = (self.stdout.take(), self.stderr.take());
        let (status, stdout, stderr) = crate::try_join!(self.wait(), read_to_end(stdout), read_to_end(stderr))?;
        Ok(Output { status, stdout, stderr })
    }
}

/// A process's standard input pipe; dropping it closes the pipe.
#[derive(Debug)]
pub struct ChildStdin {
    inner: <Process as adapter::Child>::Stdin,
}

/// A process's standard output pipe.
#[derive(Debug)]
pub struct ChildStdout {
    inner: <Process as adapter::Child>::Stdout,
}

/// A process's standard error pipe.
#[derive(Debug)]
pub struct ChildStderr {
    inner: <Process as adapter::Child>::Stderr,
}

impl_write!(ChildStdin);
impl_read!(ChildStdout);
impl_read!(ChildStderr);
