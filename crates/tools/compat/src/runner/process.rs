//! A driver process (PHP or Rust) spoken to over JSON lines.

use std::collections::VecDeque;
use std::io::{BufRead, BufReader, Write};
use std::path::Path;
use std::process::{Child, ChildStdin, Command, Stdio};
use std::sync::mpsc::{Receiver, RecvTimeoutError, channel};
use std::sync::{Arc, Mutex};
use std::time::Duration;

use serde_json::{Value, json};

use crate::adapter::{Fault, OpResult, Outcome};

pub struct Driver {
    pub side: &'static str,
    child: Child,
    stdin: ChildStdin,
    lines: Receiver<String>,
    stderr: Arc<Mutex<VecDeque<String>>>,
    next: u64,
    timeout: Duration,
}

impl Driver {
    pub fn spawn(side: &'static str, command: &[String], cwd: &Path) -> Result<Self, String> {
        let (program, args) =
            command.split_first().ok_or_else(|| format!("no command configured for the {side} driver"))?;
        let mut child = Command::new(program)
            .args(args)
            .current_dir(cwd)
            .stdin(Stdio::piped())
            .stdout(Stdio::piped())
            .stderr(Stdio::piped())
            .spawn()
            .map_err(|e| format!("cannot start the {side} driver ({}): {e}", command.join(" ")))?;
        let stdin = child.stdin.take().ok_or("no stdin")?;
        let stdout = child.stdout.take().ok_or("no stdout")?;
        let stderr_pipe = child.stderr.take().ok_or("no stderr")?;

        let (tx, lines) = channel();
        std::thread::spawn(move || {
            for line in BufReader::new(stdout).lines() {
                let Ok(line) = line else { break };
                if tx.send(line).is_err() {
                    break;
                }
            }
        });
        let stderr = Arc::new(Mutex::new(VecDeque::new()));
        let tail = stderr.clone();
        std::thread::spawn(move || {
            for line in BufReader::new(stderr_pipe).lines() {
                let Ok(line) = line else { break };
                let mut t = tail.lock().unwrap_or_else(|e| e.into_inner());
                if t.len() == 50 {
                    t.pop_front();
                }
                t.push_back(line);
            }
        });
        let timeout = std::env::var("COMPAT_TIMEOUT")
            .ok()
            .and_then(|v| v.parse().ok())
            .map(Duration::from_secs)
            .unwrap_or(Duration::from_secs(60));
        Ok(Self { side, child, stdin, lines, stderr, next: 0, timeout })
    }

    /// The driver's last stderr lines (PHP warnings, panics, ...).
    pub fn stderr_tail(&self) -> String {
        let t = self.stderr.lock().unwrap_or_else(|e| e.into_inner());
        t.iter().cloned().collect::<Vec<_>>().join("\n")
    }

    /// Sends a request without waiting for the reply; returns its id.
    pub fn send(&mut self, lib: &str, op: &str, args: &Value) -> Result<u64, String> {
        self.next += 1;
        let mut line = serde_json::to_vec(&json!({ "id": self.next, "lib": lib, "op": op, "args": args }))
            .map_err(|e| e.to_string())?;
        line.push(b'\n');
        self.stdin
            .write_all(&line)
            .and_then(|_| self.stdin.flush())
            .map_err(|e| format!("{} driver: write failed: {e}\n{}", self.side, self.stderr_tail()))?;
        Ok(self.next)
    }

    /// Waits for the reply to request `id` (replies arrive in order).
    pub fn recv(&mut self, id: u64) -> Result<OpResult, String> {
        let line = match self.lines.recv_timeout(self.timeout) {
            Ok(line) => line,
            Err(RecvTimeoutError::Timeout) => {
                return Err(format!(
                    "{} driver: no reply within {:?}\n{}",
                    self.side,
                    self.timeout,
                    self.stderr_tail()
                ));
            }
            Err(RecvTimeoutError::Disconnected) => {
                return Err(format!("{} driver exited\n{}", self.side, self.stderr_tail()));
            }
        };
        let reply: Value = match serde_json::from_str(&line) {
            Ok(v) => v,
            // Stray output (a library printing to stdout) is reported, not parsed.
            Err(_) => return Err(format!("{} driver printed a non-protocol line: {line}", self.side)),
        };
        if reply.get("id").and_then(Value::as_u64) != Some(id) {
            return Err(format!("{} driver: expected reply {id}, got {line}", self.side));
        }
        Ok(decode(&reply))
    }

    pub fn call(&mut self, lib: &str, op: &str, args: &Value) -> Result<OpResult, String> {
        let id = self.send(lib, op, args)?;
        self.recv(id)
    }

    /// Sends every request before reading any reply (pipelining).
    pub fn batch(&mut self, lib: &str, requests: &[(String, Value)]) -> Result<Vec<OpResult>, String> {
        let mut ids = Vec::with_capacity(requests.len());
        for (op, args) in requests {
            ids.push(self.send(lib, op, args)?);
        }
        ids.into_iter().map(|id| self.recv(id)).collect()
    }

    /// `$config`: namespace and service endpoints; also drops handles.
    pub fn configure(&mut self, ns: &str, services: &serde_json::Map<String, Value>) -> Result<(), String> {
        match self.call("", "$config", &json!({ "ns": ns, "services": services }))? {
            Ok(Outcome::Ok(_)) => Ok(()),
            other => Err(format!("{} driver rejected $config: {other:?}", self.side)),
        }
    }
}

impl Drop for Driver {
    fn drop(&mut self) {
        let _ = self.child.kill();
        let _ = self.child.wait();
    }
}

fn decode(reply: &Value) -> OpResult {
    if let Some(v) = reply.get("ok") {
        return Ok(Outcome::Ok(v.clone()));
    }
    if let Some(e) = reply.get("err") {
        return Ok(Outcome::Err {
            class: e.get("class").and_then(Value::as_str).unwrap_or_default().to_owned(),
            message: e.get("message").and_then(Value::as_str).unwrap_or_default().to_owned(),
        });
    }
    Err(Fault(reply.get("fault").and_then(Value::as_str).unwrap_or("malformed reply").to_owned()))
}
