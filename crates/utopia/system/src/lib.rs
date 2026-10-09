//! Environment variables and machine facts with the semantics of
//! `utopia-php/system`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `System::getEnv($name, $default)` | [`env`], [`env_or`] (`getenv() ?: $default`); raw `getenv()` is [`env_raw`] |
//! | `System::X86`, `PPC`, `ARM64`, `ARMV7`, `ARMV8` | [`Arch`] ([`Arch::name`], [`Arch::from_name`]) |
//! | `getOS()`, `getArch()`, `getHostname()` | [`System::os`], [`System::arch`], [`System::hostname`] |
//! | `getArchEnum()`, `isArch($arch)` | [`System::arch_enum`], [`System::is_arch`] |
//! | `isX86()`, `isPPC()`, `isArm64()`, `isArmV7()`, `isArmV8()` | [`System::is_x86`], [`System::is_ppc`], [`System::is_arm64`], [`System::is_armv7`], [`System::is_armv8`] |
//! | `getCPUCores()`, `getCPU()`, `getCPUUsage($duration)` | [`System::cpu_cores`], [`System::cpu`], [`System::cpu_usage`] |
//! | `getMemoryTotal()`, `getMemory()`, `getMemoryFree()`, `getMemoryAvailable()` | [`System::memory_total`], [`System::memory`], [`System::memory_free`], [`System::memory_available`] |
//! | `getDiskTotal($dir)`, `getDiskFree($dir)` | [`System::disk_total`], [`System::disk_free`] |
//! | `getIOUsage($duration)`, `getNetworkUsage($duration)` | [`System::io_usage`], [`System::network_usage`] |
//! | `\Exception`, `\DivisionByZeroError` | [`Error::Exception`], [`Error::DivisionByZero`] |
//!
//! Reading is separated from parsing. Everything PHP reads from the machine
//! (`php_uname`, files under `/proc` and `/sys`, `shell_exec('sysctl …')`,
//! `scandir`, `disk_*_space`, `sleep`) goes through a [`Host`]; [`Machine`]
//! is the real one, and any other host (a fixture, a remote probe) can be
//! plugged in. The parsers ([`count_processors`], [`count_cpu_list`],
//! [`meminfo`], [`proc_stat_total`], [`diskstats`]) take the content and
//! keep PHP's exact semantics, including integer arithmetic that overflows
//! into floats and results that are integers or floats depending on the
//! division ([`Number`]).

use std::future::Future;
use std::time::Duration;

pub use indexmap::IndexMap;
use php_std::format::{self, RoundingMode};
pub use php_std::value::Number;

/// Why a measurement failed, by the PHP exception class it corresponds to.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// `\Exception`: an unsupported OS, an unreadable source, or no data.
    #[error("{0}")]
    Exception(String),
    /// `\DivisionByZeroError`: no CPU time passed between the two samples of
    /// [`System::cpu_usage`].
    #[error("Division by zero")]
    DivisionByZero,
    /// `\TypeError`: a cpuset list counting more CPUs than an `int` holds
    /// ([`count_cpu_list`]).
    #[error("{0}")]
    Type(String),
}

impl Error {
    /// The PHP exception class this error corresponds to.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Exception(_) => "Exception",
            Error::DivisionByZero => "DivisionByZeroError",
            Error::Type(_) => "TypeError",
        }
    }
}

fn exception(message: impl Into<String>) -> Error {
    Error::Exception(message.into())
}

// ---------------------------------------------------------------------------
// Environment
// ---------------------------------------------------------------------------

/// `System::getEnv($name)`: the variable, or `None` when it is unset, empty
/// or `"0"` (PHP's `getenv($name) ?: null`).
pub fn env(name: &str) -> Option<String> {
    env_value(std::env::var(name).ok())
}

/// `System::getEnv($name, $default)`.
pub fn env_or(name: &str, default: &str) -> String {
    env(name).unwrap_or_else(|| default.to_owned())
}

/// PHP `getenv($name)`: the variable as it is (empty strings included).
pub fn env_raw(name: &str) -> Option<String> {
    std::env::var(name).ok()
}

/// The `?:` of `getEnv`: a value that is empty or `"0"` is no value.
pub fn env_value(raw: Option<String>) -> Option<String> {
    raw.filter(|v| !v.is_empty() && v != "0")
}

// ---------------------------------------------------------------------------
// Architectures
// ---------------------------------------------------------------------------

/// A processor architecture family (`System::X86`, ...).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Arch {
    X86,
    Ppc,
    Arm64,
    ArmV7,
    ArmV8,
}

impl Arch {
    /// Detection order of `getArchEnum()`.
    pub const ALL: [Arch; 5] = [Arch::X86, Arch::Ppc, Arch::Arm64, Arch::ArmV7, Arch::ArmV8];

    /// The PHP constant's value (`x86`, `ppc`, `arm64`, `armv7`, `armv8`).
    pub fn name(self) -> &'static str {
        match self {
            Arch::X86 => "x86",
            Arch::Ppc => "ppc",
            Arch::Arm64 => "arm64",
            Arch::ArmV7 => "armv7",
            Arch::ArmV8 => "armv8",
        }
    }

    /// The architecture a PHP constant's value names (exactly).
    pub fn from_name(name: &str) -> Option<Arch> {
        Arch::ALL.into_iter().find(|a| a.name() == name)
    }

    /// Whether a machine name (`php_uname('m')`) belongs to this family:
    /// PHP's patterns `/(x86*|i386|i686)/`, `/(ppc*)/`, `/(arm64|aarch64)/`,
    /// `/(armv7)/` and `/(armv8)/`, which are substring tests (`x86*` is
    /// `x8` followed by any number of `6`, so any `x8`; `ppc*` any `pp`).
    pub fn matches(self, machine: &str) -> bool {
        match self {
            Arch::X86 => machine.contains("x8") || machine.contains("i386") || machine.contains("i686"),
            Arch::Ppc => machine.contains("pp"),
            Arch::Arm64 => machine.contains("arm64") || machine.contains("aarch64"),
            Arch::ArmV7 => machine.contains("armv7"),
            Arch::ArmV8 => machine.contains("armv8"),
        }
    }

    /// `getArchEnum()` for a machine name: the first family that matches.
    pub fn detect(machine: &str) -> Option<Arch> {
        Arch::ALL.into_iter().find(|a| a.matches(machine))
    }
}

// ---------------------------------------------------------------------------
// Hosts
// ---------------------------------------------------------------------------

/// A field of `php_uname($mode)`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Uname {
    /// `'s'`: the operating system (`Linux`, `Darwin`).
    Os,
    /// `'m'`: the machine (`x86_64`, `aarch64`, `arm64`).
    Machine,
    /// `'n'`: the host name.
    Host,
}

/// Where [`System`] reads the machine from: the PHP functions it calls.
pub trait Host {
    /// `php_uname($mode)`.
    fn uname(&self, field: Uname) -> String;
    /// `file_get_contents($path)`: `None` where PHP returns `false`.
    fn read(&self, path: &str) -> Option<Vec<u8>>;
    /// `is_readable($path)`.
    fn is_readable(&self, path: &str) -> bool;
    /// `shell_exec($command)`: `None` where PHP returns `null` or `false`.
    fn exec(&self, command: &str) -> Option<Vec<u8>>;
    /// `scandir($dir, SCANDIR_SORT_NONE)`: `None` where PHP returns `false`.
    fn scandir(&self, dir: &str) -> Option<Vec<String>>;
    /// `disk_total_space($dir)`: `None` where PHP returns `false`.
    fn disk_total_space(&self, dir: &str) -> Option<f64>;
    /// `disk_free_space($dir)`: `None` where PHP returns `false`.
    fn disk_free_space(&self, dir: &str) -> Option<f64>;
    /// `sleep($seconds)`.
    fn sleep(&self, seconds: u64) -> impl Future<Output = ()> + Send;
}

/// This machine. File reads are of `/proc` and `/sys` pseudo-files, which
/// the kernel serves from memory; `sysctl` and `wmic` (macOS and Windows
/// only) run as child processes.
#[derive(Debug, Clone, Copy, Default)]
pub struct Machine;

impl Host for Machine {
    fn uname(&self, field: Uname) -> String {
        let uname = rustix::system::uname();
        let value = match field {
            Uname::Os => uname.sysname(),
            Uname::Machine => uname.machine(),
            Uname::Host => uname.nodename(),
        };
        value.to_string_lossy().into_owned()
    }

    fn read(&self, path: &str) -> Option<Vec<u8>> {
        std::fs::read(path).ok()
    }

    fn is_readable(&self, path: &str) -> bool {
        rustix::fs::access(path, rustix::fs::Access::READ_OK).is_ok()
    }

    fn exec(&self, command: &str) -> Option<Vec<u8>> {
        let output = std::process::Command::new("sh").arg("-c").arg(command).output().ok()?;
        Some(output.stdout).filter(|o| !o.is_empty())
    }

    fn scandir(&self, dir: &str) -> Option<Vec<String>> {
        let entries = std::fs::read_dir(dir).ok()?;
        let mut names = vec![".".to_owned(), "..".to_owned()];
        names.extend(entries.flatten().map(|e| e.file_name().to_string_lossy().into_owned()));
        Some(names)
    }

    fn disk_total_space(&self, dir: &str) -> Option<f64> {
        let s = rustix::fs::statvfs(dir).ok()?;
        let unit = if s.f_frsize != 0 { s.f_frsize } else { s.f_bsize };
        Some(s.f_blocks as f64 * unit as f64)
    }

    fn disk_free_space(&self, dir: &str) -> Option<f64> {
        let s = rustix::fs::statvfs(dir).ok()?;
        let unit = if s.f_frsize != 0 { s.f_frsize } else { s.f_bsize };
        Some(s.f_bavail as f64 * unit as f64)
    }

    async fn sleep(&self, seconds: u64) {
        tokio::time::sleep(Duration::from_secs(seconds)).await;
    }
}

// ---------------------------------------------------------------------------
// Parsers
// ---------------------------------------------------------------------------

/// PHP's `trim()` characters.
const TRIM: &[u8] = b" \t\n\r\0\x0B";

/// PCRE's `\s` without `/u`: space, `\t`, `\n`, `\v`, `\f`, `\r`.
fn is_pcre_space(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | 0x0B | 0x0C | b'\r')
}

/// A PHP string's truthiness: not `""` and not `"0"`.
fn truthy(s: &[u8]) -> bool {
    !s.is_empty() && s != b"0"
}

/// `is_numeric($string)`.
fn is_numeric(s: &[u8]) -> bool {
    std::str::from_utf8(s).ok().and_then(php_std::value::numeric_str).is_some()
}

/// `intval()` of a number.
fn intval(n: Number) -> i64 {
    match n {
        Number::Int(i) => i,
        Number::Float(f) => format::dval_to_lval(f),
    }
}

/// `intval()` of what `file_get_contents()` or `shell_exec()` returned.
fn intval_read(s: Option<&[u8]>) -> i64 {
    s.map_or(0, format::str_to_int)
}

/// `preg_match_all('/^processor/m', $cpuinfo)`: lines starting with
/// `processor`.
pub fn count_processors(cpuinfo: &[u8]) -> usize {
    let mut count = usize::from(cpuinfo.starts_with(b"processor"));
    for (i, &b) in cpuinfo.iter().enumerate() {
        if b == b'\n' && cpuinfo[i + 1..].starts_with(b"processor") {
            count += 1;
        }
    }
    count
}

/// `System::countCpuList()`: CPUs in a Linux cpu list such as `0-3,5,7-8`.
/// Ranges whose bounds are not numeric or are reversed count nothing. A
/// count beyond `i64` is a float in PHP, which the method's `int` return
/// type refuses: [`Error::Type`].
pub fn count_cpu_list(list: &[u8]) -> Result<i64, Error> {
    let overflow = || {
        Error::Type("Utopia\\System\\System::countCpuList(): Return value must be of type int, float returned".into())
    };
    let mut count: i64 = 0;
    for range in list.split(|&b| b == b',') {
        if range.is_empty() {
            continue;
        }
        if let Some(dash) = range.iter().position(|&b| b == b'-') {
            let (start, end) = (&range[..dash], &range[dash + 1..]);
            if is_numeric(start) && is_numeric(end) {
                let (start, end) = (format::str_to_int(start), format::str_to_int(end));
                if start <= end {
                    let n = end.checked_sub(start).and_then(|n| n.checked_add(1)).ok_or_else(overflow)?;
                    count = count.checked_add(n).ok_or_else(overflow)?;
                }
            }
        } else if is_numeric(range) {
            count = count.checked_add(1).ok_or_else(overflow)?;
        }
    }
    Ok(count)
}

/// `System::getProcMemoryInfo($field)` on `/proc/meminfo` content: the
/// first `<field>:` followed by whitespace and digits
/// (`/<field>:\s+(\d+)/`), in MiB (`intval(intval($kb) / 1024)`).
pub fn meminfo(content: &[u8], field: &str) -> Option<i64> {
    let needle = [field.as_bytes(), b":"].concat();
    let mut from = 0;
    while let Some(at) = find(&content[from..], &needle) {
        let start = from + at + needle.len();
        let spaces = content[start..].iter().take_while(|&&b| is_pcre_space(b)).count();
        let digits = content[start + spaces..].iter().take_while(|b| b.is_ascii_digit()).count();
        if spaces > 0 && digits > 0 {
            let kb = format::str_to_int(&content[start + spaces..start + spaces + digits]);
            return format::div(Number::Int(kb), Number::Int(1024)).map(intval);
        }
        from += at + 1;
    }
    None
}

fn find(haystack: &[u8], needle: &[u8]) -> Option<usize> {
    haystack.windows(needle.len()).position(|w| w == needle)
}

/// The `total` row of `System::getProcStatData()`: the sum, over every
/// `cpuN` line of `/proc/stat` (the aggregate `cpu` line does not match
/// PHP's `/^cpu[0-999]/`), of each field's `intval()`. Fields are split on
/// single spaces; a later line for the same CPU replaces an earlier one.
#[derive(Debug, Clone, Copy, PartialEq)]
pub struct CpuTimes {
    pub user: Number,
    pub nice: Number,
    pub system: Number,
    pub idle: Number,
    pub iowait: Number,
    pub irq: Number,
    pub softirq: Number,
    pub steal: Number,
    pub guest: Number,
}

/// [`CpuTimes`] of `/proc/stat` content; `None` where PHP throws
/// `Unable to read /proc/stat` (content that is empty or `"0"`).
pub fn proc_stat_total(content: &[u8]) -> Option<CpuTimes> {
    if !truthy(content) {
        return None;
    }
    let mut cpus: IndexMap<php_std::zval::Key, [i64; 9]> = IndexMap::new();
    for line in content.split(|&b| b == b'\n') {
        if !(line.starts_with(b"cpu") && line.get(3).is_some_and(u8::is_ascii_digit)) {
            continue;
        }
        let mut tokens = line.split(|&b| b == b' ');
        let name = tokens.next().unwrap_or_default();
        let mut fields = [0i64; 9];
        for (field, token) in fields.iter_mut().zip(tokens) {
            *field = format::str_to_int(token);
        }
        cpus.insert(php_std::zval::Key::from_bytes(&name[3..]), fields);
    }
    let mut total = [Number::Int(0); 9];
    for fields in cpus.values() {
        for (sum, &v) in total.iter_mut().zip(fields) {
            *sum = format::add(*sum, Number::Int(v));
        }
    }
    let [user, nice, system, idle, iowait, irq, softirq, steal, guest] = total;
    Some(CpuTimes { user, nice, system, idle, iowait, irq, softirq, steal, guest })
}

/// `System::getDiskStats()` on `/proc/diskstats` content: each non-empty
/// line, trimmed, tabs runs made one space, split on single spaces, keyed
/// by its third field (`""` when it has none; a later line replaces an
/// earlier one). `None` where PHP throws `Unable to read /proc/diskstats`.
pub fn diskstats(content: &[u8]) -> Option<IndexMap<String, Vec<Vec<u8>>>> {
    if !truthy(content) {
        return None;
    }
    let mut disks = IndexMap::new();
    for line in content.split(|&b| b == b'\n') {
        let line = php_std::string::trim(line, TRIM);
        let mut squeezed = Vec::with_capacity(line.len());
        for (i, &b) in line.iter().enumerate() {
            if b == b'\t' {
                if i == 0 || line[i - 1] != b'\t' {
                    squeezed.push(b' ');
                }
            } else {
                squeezed.push(b);
            }
        }
        if !truthy(&squeezed) {
            continue;
        }
        let fields: Vec<Vec<u8>> = squeezed.split(|&b| b == b' ').map(<[u8]>::to_vec).collect();
        let name = fields.get(2).map(|n| String::from_utf8_lossy(n).into_owned()).unwrap_or_default();
        disks.insert(name, fields);
    }
    Some(disks)
}

// ---------------------------------------------------------------------------
// Measurements
// ---------------------------------------------------------------------------

/// Disk names `getIOUsage()` skips (substring match).
const INVALID_DISKS: [&str; 2] = ["loop", "ram"];

/// Network interfaces `getNetworkUsage()` skips (substring match).
const INVALID_NET_INTERFACES: [&str; 7] = ["veth", "docker", "lo", "tun", "vboxnet", ".", "bonding_masters"];

/// Read and write volume of a disk, in MiB (an integer when the division is
/// exact, as in PHP).
#[derive(Debug, Clone, Copy, PartialEq)]
pub struct Io {
    pub read: Number,
    pub write: Number,
}

/// Download and upload volume of a network interface, in MiB rounded to
/// two decimals.
#[derive(Debug, Clone, Copy, PartialEq)]
pub struct Traffic {
    pub download: Number,
    pub upload: Number,
}

/// `Utopia\System\System` on a [`Host`].
#[derive(Debug, Clone, Default)]
pub struct System<H: Host = Machine> {
    host: H,
}

impl System<Machine> {
    /// This machine.
    pub fn new() -> Self {
        Self { host: Machine }
    }
}

impl<H: Host> System<H> {
    pub fn with_host(host: H) -> Self {
        Self { host }
    }

    pub fn host(&self) -> &H {
        &self.host
    }

    /// `getOS()`.
    pub fn os(&self) -> String {
        self.host.uname(Uname::Os)
    }

    /// `getArch()`.
    pub fn arch(&self) -> String {
        self.host.uname(Uname::Machine)
    }

    /// `getHostname()`.
    pub fn hostname(&self) -> String {
        self.host.uname(Uname::Host)
    }

    /// `getArchEnum()`.
    pub fn arch_enum(&self) -> Result<Arch, Error> {
        let arch = self.arch();
        Arch::detect(&arch).ok_or_else(|| exception(format!("'{arch}' enum not found.")))
    }

    pub fn is_x86(&self) -> bool {
        Arch::X86.matches(&self.arch())
    }

    pub fn is_ppc(&self) -> bool {
        Arch::Ppc.matches(&self.arch())
    }

    pub fn is_arm64(&self) -> bool {
        Arch::Arm64.matches(&self.arch())
    }

    pub fn is_armv7(&self) -> bool {
        Arch::ArmV7.matches(&self.arch())
    }

    pub fn is_armv8(&self) -> bool {
        Arch::ArmV8.matches(&self.arch())
    }

    /// `isArch($arch)`: `$arch` is a PHP constant's value ([`Arch::name`]).
    pub fn is_arch(&self, arch: &str) -> Result<bool, Error> {
        match Arch::from_name(arch) {
            Some(a) => Ok(a.matches(&self.arch())),
            None => Err(exception(format!("'{arch}' not found."))),
        }
    }

    fn unsupported(os: &str) -> Error {
        exception(format!("{os} not supported."))
    }

    /// `getCPUCores()` (deprecated in PHP for [`System::cpu`]).
    pub fn cpu_cores(&self) -> Result<i64, Error> {
        match self.os().as_str() {
            "Linux" => Ok(match self.host.read("/proc/cpuinfo") {
                Some(info) if truthy(&info) => count_processors(&info) as i64,
                _ => 0,
            }),
            "Darwin" => Ok(intval_read(self.host.exec("sysctl -n hw.ncpu").as_deref())),
            "Windows" => Ok(intval_read(self.host.exec("wmic cpu get NumberOfCores").as_deref())),
            os => Err(Self::unsupported(os)),
        }
    }

    /// `getCPU()`: the CPUs available to this process, honouring cgroup
    /// quotas and cpusets on Linux.
    pub fn cpu(&self) -> Result<f64, Error> {
        match self.os().as_str() {
            "Linux" => {
                let quota = self.cgroup_cpu_limit();
                let cpuset = self.cgroup_cpuset_count()?;
                let limits: Vec<Number> = [quota, cpuset].into_iter().flatten().map(Number::Float).collect();
                if let Ok(min) = format::min_array(&limits) {
                    return Ok(min.as_f64());
                }
                let info = self.host.read("/proc/cpuinfo").ok_or_else(|| {
                    exception(
                        "Unable to determine CPU count: /proc/cpuinfo is not readable and no cgroup limits are configured.",
                    )
                })?;
                match count_processors(&info) {
                    0 => Err(exception("Unable to determine CPU count: /proc/cpuinfo contained no processor entries.")),
                    n => Ok(n as f64),
                }
            }
            "Darwin" => {
                let output = self.host.exec("sysctl -n hw.ncpu").unwrap_or_default();
                let digits = digit_runs(&output).next();
                match digits {
                    Some(d) if format::str_to_int(d) > 0 => Ok(format::str_to_float(d)),
                    _ => Err(exception("Unable to determine CPU count via sysctl.")),
                }
            }
            "Windows" => {
                let output = self.host.exec("wmic cpu get NumberOfCores").unwrap_or_default();
                let mut runs = digit_runs(&output).peekable();
                if runs.peek().is_none() {
                    return Err(exception("Unable to determine CPU count via wmic."));
                }
                let total = format::array_sum(runs.map(|d| Number::Int(format::str_to_int(d))));
                if total.as_f64() <= 0.0 {
                    return Err(exception("Unable to determine CPU count via wmic."));
                }
                Ok(total.as_f64())
            }
            os => Err(Self::unsupported(os)),
        }
    }

    /// `getCgroupCPULimit()`: cgroup v2 `cpu.max`, else v1 quota/period.
    fn cgroup_cpu_limit(&self) -> Option<f64> {
        let v2 = "/sys/fs/cgroup/cpu.max";
        if self.host.is_readable(v2) {
            let contents = self.host.read(v2).unwrap_or_default();
            let contents = php_std::string::trim(&contents, TRIM);
            if !contents.is_empty() {
                let parts: Vec<&[u8]> = split_pcre_space(contents);
                if let [quota, period, ..] = parts[..]
                    && quota != b"max"
                    && is_numeric(quota)
                    && is_numeric(period)
                    && format::str_to_float(period) > 0.0
                {
                    return Some(format::str_to_float(quota) / format::str_to_float(period));
                }
            }
        }
        let (quota_file, period_file) = ("/sys/fs/cgroup/cpu/cpu.cfs_quota_us", "/sys/fs/cgroup/cpu/cpu.cfs_period_us");
        if self.host.is_readable(quota_file) && self.host.is_readable(period_file) {
            let quota = self.host.read(quota_file).unwrap_or_default();
            let period = self.host.read(period_file).unwrap_or_default();
            let (quota, period) = (php_std::string::trim(&quota, TRIM), php_std::string::trim(&period, TRIM));
            if is_numeric(quota)
                && is_numeric(period)
                && format::str_to_float(quota) > 0.0
                && format::str_to_float(period) > 0.0
            {
                return Some(format::str_to_float(quota) / format::str_to_float(period));
            }
        }
        None
    }

    /// `getCgroupCpusetCount()`: CPUs of the cpuset, unless it is every
    /// online CPU.
    fn cgroup_cpuset_count(&self) -> Result<Option<f64>, Error> {
        for file in ["/sys/fs/cgroup/cpuset.cpus.effective", "/sys/fs/cgroup/cpuset/cpuset.cpus"] {
            if !self.host.is_readable(file) {
                continue;
            }
            let contents = self.host.read(file).unwrap_or_default();
            let contents = php_std::string::trim(&contents, TRIM);
            if contents.is_empty() {
                continue;
            }
            let count = count_cpu_list(contents)?;
            if count <= 0 {
                continue;
            }
            if let Some(online) = self.host.read("/sys/devices/system/cpu/online") {
                let online = count_cpu_list(php_std::string::trim(&online, TRIM))?;
                if online > 0 && count >= online {
                    return Ok(None);
                }
            }
            return Ok(Some(count as f64));
        }
        Ok(None)
    }

    async fn proc_stat(&self) -> Result<CpuTimes, Error> {
        proc_stat_total(&self.host.read("/proc/stat").unwrap_or_default())
            .ok_or_else(|| exception("Unable to read /proc/stat"))
    }

    /// `getCPUUsage($duration)`: the busy share of CPU time between two
    /// samples `seconds` apart, as a percentage (Linux only).
    pub async fn cpu_usage(&self, seconds: u64) -> Result<f64, Error> {
        let os = self.os();
        if os != "Linux" {
            return Err(Self::unsupported(&os));
        }
        let start = self.proc_stat().await?;
        self.host.sleep(seconds).await;
        let end = self.proc_stat().await?;
        let busy = |t: &CpuTimes| [t.nice, t.system, t.irq, t.softirq, t.steal].into_iter().fold(t.user, format::add);
        let (prev_idle, idle) = (format::add(start.idle, start.iowait), format::add(end.idle, end.iowait));
        let (prev_total, total) = (format::add(prev_idle, busy(&start)), format::add(idle, busy(&end)));
        let total_diff = format::sub(total, prev_total);
        let idle_diff = format::sub(idle, prev_idle);
        let share = format::div(format::sub(total_diff, idle_diff), total_diff).ok_or(Error::DivisionByZero)?;
        Ok(format::mul(share, Number::Int(100)).as_f64())
    }

    fn proc_memory_info(&self, field: &str) -> Result<i64, Error> {
        let info = self.host.read("/proc/meminfo").filter(|i| truthy(i));
        let info = info.ok_or_else(|| exception("Unable to read /proc/meminfo"))?;
        meminfo(&info, field).ok_or_else(|| exception(format!("Unable to find {field} in /proc/meminfo.")))
    }

    /// `intval(intval(shell_exec($command)) / 1024 / 1024)`.
    fn sysctl_mib(&self, command: &str) -> i64 {
        let value = Number::Int(intval_read(self.host.exec(command).as_deref()));
        let mib = format::div(value, Number::Int(1024)).and_then(|kib| format::div(kib, Number::Int(1024)));
        mib.map_or(0, intval)
    }

    /// `getMemoryTotal()`: RAM in MiB.
    pub fn memory_total(&self) -> Result<i64, Error> {
        match self.os().as_str() {
            "Linux" => self.proc_memory_info("MemTotal"),
            "Darwin" => Ok(self.sysctl_mib("sysctl -n hw.memsize")),
            os => Err(Self::unsupported(os)),
        }
    }

    /// `getMemory()`: RAM in MiB available to this process (the cgroup
    /// memory limit on Linux, capped at the total).
    pub fn memory(&self) -> Result<i64, Error> {
        let total = self.memory_total()?;
        if self.os() != "Linux" {
            return Ok(total);
        }
        Ok(match self.cgroup_memory_limit() {
            None => total,
            Some(limit) => total.min(limit / (1024 * 1024)),
        })
    }

    /// `getCgroupMemoryLimit()`: bytes, from cgroup v2 `memory.max` or v1
    /// `memory.limit_in_bytes`.
    fn cgroup_memory_limit(&self) -> Option<i64> {
        use php_std::filter::{FILTER_VALIDATE_INT, Key, Options, Value};
        for file in ["/sys/fs/cgroup/memory.max", "/sys/fs/cgroup/memory/memory.limit_in_bytes"] {
            if !self.host.is_readable(file) {
                continue;
            }
            let contents = self.host.read(file).unwrap_or_default();
            let contents = php_std::string::trim(&contents, TRIM);
            if contents == b"max" || contents == b"-1" {
                return None;
            }
            let options = Options::Array(vec![(
                Key::Str(b"options".to_vec()),
                Value::Array(vec![(Key::Str(b"min_range".to_vec()), Value::Int(0))]),
            )]);
            if let Ok(filtered) =
                php_std::filter::filter_var(&Value::Str(contents.to_vec()), FILTER_VALIDATE_INT, &options)
                && let Value::Int(limit) = filtered.value
            {
                return Some(limit);
            }
        }
        None
    }

    /// `getMemoryFree()`: free RAM in MiB.
    pub fn memory_free(&self) -> Result<i64, Error> {
        match self.os().as_str() {
            "Linux" => self.proc_memory_info("MemFree"),
            "Darwin" => Ok(self.sysctl_mib("sysctl -n vm.page_free_count")),
            os => Err(Self::unsupported(os)),
        }
    }

    /// `getMemoryAvailable()`: available RAM in MiB (Linux only).
    pub fn memory_available(&self) -> Result<i64, Error> {
        match self.os().as_str() {
            "Linux" => self.proc_memory_info("MemAvailable"),
            os => Err(Self::unsupported(os)),
        }
    }

    /// `getDiskTotal($directory)`: size in MiB of the file system holding
    /// `directory` (PHP defaults to the library's own directory; Rust
    /// takes it explicitly).
    pub fn disk_total(&self, directory: &str) -> Result<i64, Error> {
        let bytes = self.host.disk_total_space(directory).ok_or_else(|| exception("Unable to get disk space"))?;
        Ok(format::dval_to_lval(bytes / 1024.0 / 1024.0))
    }

    /// `getDiskFree($directory)`: free space in MiB.
    pub fn disk_free(&self, directory: &str) -> Result<i64, Error> {
        let bytes = self.host.disk_free_space(directory).ok_or_else(|| exception("Unable to get free disk space"))?;
        Ok(format::dval_to_lval(bytes / 1024.0 / 1024.0))
    }

    fn disk_stats(&self) -> Result<IndexMap<String, Vec<Vec<u8>>>, Error> {
        let mut disks = diskstats(&self.host.read("/proc/diskstats").unwrap_or_default())
            .ok_or_else(|| exception("Unable to read /proc/diskstats"))?;
        disks.retain(|_, fields| {
            fields
                .get(2)
                .is_some_and(|name| !INVALID_DISKS.iter().any(|f| php_std::string::str_contains(name, f.as_bytes())))
        });
        Ok(disks)
    }

    /// `getIOUsage($duration)`: MiB read and written per disk over
    /// `seconds`, plus a `total` row.
    pub async fn io_usage(&self, seconds: u64) -> Result<IndexMap<String, Io>, Error> {
        let first = self.disk_stats()?;
        self.host.sleep(seconds).await;
        let second = self.disk_stats()?;
        let field =
            |fields: Option<&Vec<Vec<u8>>>, i: usize| intval_read(fields.and_then(|f| f.get(i)).map(Vec::as_slice));
        let delta = |a: i64, b: i64| {
            let bytes = format::mul(format::sub(Number::Int(a), Number::Int(b)), Number::Int(512));
            format::div(bytes, Number::Int(1_048_576)).unwrap_or(Number::Int(0))
        };
        let mut stats: IndexMap<String, (Option<Number>, Option<Number>)> = IndexMap::new();
        for (name, fields) in &first {
            let later = second.get(name);
            let read = delta(field(later, 5), field(Some(fields), 5));
            let write = delta(field(later, 9), field(Some(fields), 9));
            stats.insert(name.clone(), (Some(read), Some(write)));
        }
        total_row(&mut stats);
        Ok(stats
            .into_iter()
            .map(|(k, (r, w))| (k, Io { read: r.unwrap_or(Number::Int(0)), write: w.unwrap_or(Number::Int(0)) }))
            .collect())
    }

    /// `getNetworkUsage($duration)`: MiB downloaded and uploaded per
    /// interface over `seconds` (each interface is sampled in turn), plus a
    /// `total` row.
    pub async fn network_usage(&self, seconds: u64) -> Result<IndexMap<String, Traffic>, Error> {
        let interfaces = self
            .host
            .scandir("/sys/class/net")
            .filter(|i| !i.is_empty())
            .ok_or_else(|| exception("Unable to read /sys/class/net"))?;
        let counter = |interface: &str, name: &str| {
            intval_read(self.host.read(&format!("/sys/class/net/{interface}/statistics/{name}")).as_deref())
        };
        let mib = |a: i64, b: i64| {
            let n = format::div(format::sub(Number::Int(a), Number::Int(b)), Number::Int(1_048_576));
            Number::Float(format::round(n.unwrap_or(Number::Int(0)), 2, RoundingMode::HalfAwayFromZero))
        };
        let mut usage: IndexMap<String, (Option<Number>, Option<Number>)> = IndexMap::new();
        for interface in interfaces.iter().filter(|i| !INVALID_NET_INTERFACES.iter().any(|f| i.contains(f))) {
            let (tx1, rx1) = (counter(interface, "tx_bytes"), counter(interface, "rx_bytes"));
            self.host.sleep(seconds).await;
            let (tx2, rx2) = (counter(interface, "tx_bytes"), counter(interface, "rx_bytes"));
            let row = usage.entry(interface.clone()).or_default();
            row.0 = Some(mib(rx2, rx1));
            row.1 = Some(mib(tx2, tx1));
        }
        total_row(&mut usage);
        Ok(usage
            .into_iter()
            .map(|(k, (d, u))| {
                (k, Traffic { download: d.unwrap_or(Number::Int(0)), upload: u.unwrap_or(Number::Int(0)) })
            })
            .collect())
    }
}

/// `$rows['total'][a] = array_sum(array_column($rows, a))`, then the same
/// for `b` (so the second sum sees the `total` row's first column only).
fn total_row(rows: &mut IndexMap<String, (Option<Number>, Option<Number>)>) {
    let first = format::array_sum(rows.values().filter_map(|r| r.0));
    rows.entry("total".to_owned()).or_default().0 = Some(first);
    let second = format::array_sum(rows.values().filter_map(|r| r.1));
    rows.entry("total".to_owned()).or_default().1 = Some(second);
}

/// `preg_split('/\s+/', $s)`.
fn split_pcre_space(s: &[u8]) -> Vec<&[u8]> {
    let mut parts = Vec::new();
    let mut start = 0;
    let mut i = 0;
    while i < s.len() {
        if is_pcre_space(s[i]) {
            parts.push(&s[start..i]);
            while i < s.len() && is_pcre_space(s[i]) {
                i += 1;
            }
            start = i;
        } else {
            i += 1;
        }
    }
    parts.push(&s[start..]);
    parts
}

/// `preg_match_all('/\d+/', $s)`: runs of ASCII digits.
fn digit_runs(s: &[u8]) -> impl Iterator<Item = &[u8]> {
    s.split(|b| !b.is_ascii_digit()).filter(|r| !r.is_empty())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn env_falls_back_on_empty_and_zero() {
        assert_eq!(env_value(Some("x".into())), Some("x".into()));
        assert_eq!(env_value(Some("0".into())), None);
        assert_eq!(env_value(Some(String::new())), None);
        assert_eq!(env_value(None), None);
    }

    #[test]
    fn arch_detection_follows_php_patterns() {
        assert_eq!(Arch::detect("x86_64"), Some(Arch::X86));
        assert_eq!(Arch::detect("aarch64"), Some(Arch::Arm64));
        assert_eq!(Arch::detect("ppc64le"), Some(Arch::Ppc));
        assert_eq!(Arch::detect("armv7l"), Some(Arch::ArmV7));
        assert_eq!(Arch::detect("riscv64"), None);
    }

    #[test]
    fn parsers() {
        assert_eq!(count_processors(b"processor\t: 0\nfoo\nprocessor\t: 1\n"), 2);
        assert_eq!(count_cpu_list(b"0-3,5,7-8,,x,3-1"), Ok(7));
        assert!(matches!(count_cpu_list(b"0-9223372036854775807"), Err(Error::Type(_))));
        assert_eq!(meminfo(b"MemTotal:       8388608 kB\nMemFree: 1 kB", "MemTotal"), Some(8192));
        assert_eq!(meminfo(b"MemFree: 1 kB", "MemTotal"), None);
        let stat = proc_stat_total(b"cpu  9 9 9 9\ncpu0 1 2 3 4 5 6 7 8 9\ncpu1 1 1 1 1\n").unwrap();
        assert_eq!(stat.user, Number::Int(2));
        assert_eq!(stat.guest, Number::Int(9));
        let disks = diskstats(b"   8       0 sda 1 2 3 4\n\t8\t\t1\tsdb 5\n").unwrap();
        assert_eq!(disks.keys().collect::<Vec<_>>(), ["", "sdb"]);
    }
}
