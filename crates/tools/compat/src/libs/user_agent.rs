//! Compat adapter for `user-agent`: maps `tests/compat/user-agent/spec.json`
//! operations onto `utopia-user-agent`. The `toArray()` shapes are built
//! from the public fields.

use std::borrow::Cow;
use std::hint::black_box;

use serde_json::{Value, json};
use utopia_user_agent::{Bot, Client, Device, OperatingSystem, Text, UserAgent, detection};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes, bytes_value};
use crate::bench::Bench;

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &[
    "agent.inspect",
    "detect.operating_system",
    "detect.client",
    "detect.device",
    "detect.bot",
    "os.new",
    "client.new",
    "device.new",
    "bot.new",
];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("chrome", "Detect the OS, client, device and bot of a desktop Chrome on Windows"),
    ("safari", "Detect the OS, client, device and bot of a mobile Safari on iPhone"),
    ("bot", "Detect the OS, client, device and bot of Googlebot"),
    ("client", "Detect the client of a desktop Chrome on Windows"),
    ("is_bot", "Tell whether a desktop Chrome on Windows is a bot"),
];

const CHROME: &str =
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36";
const SAFARI: &str = "Mozilla/5.0 (iPhone; CPU iPhone OS 17_4_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4.1 Mobile/15E148 Safari/604.1";
const GOOGLEBOT: &str = "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)";

/// Everything a request reads of a user agent.
fn detect(value: &str) {
    let agent = UserAgent::parse(black_box(value));
    black_box(agent.operating_system());
    black_box(agent.client());
    black_box(agent.device());
    black_box(agent.bot());
}

pub async fn bench(name: &str, _session: &mut Session) -> Result<Bench, Fault> {
    Ok(match name {
        "chrome" => Bench::sync(|| detect(CHROME)),
        "safari" => Bench::sync(|| detect(SAFARI)),
        "bot" => Bench::sync(|| detect(GOOGLEBOT)),
        "client" => Bench::sync(|| {
            black_box(detection::client(black_box(CHROME.as_bytes())));
        }),
        "is_bot" => Bench::sync(|| {
            black_box(UserAgent::parse(black_box(CHROME)).is_bot());
        }),
        _ => return Err(Fault::new(format!("user-agent: no benchmark `{name}`"))),
    })
}

fn text(value: &Option<Text<'_>>) -> Value {
    value.as_deref().map_or(Value::Null, bytes_value)
}

/// An optional text argument (absent or `null` is `None`).
fn arg(a: &Args<'_>, key: &str) -> Result<Option<Text<'static>>, Fault> {
    a.opt(key)
        .map(|v| bytes(v).map(Cow::Owned).ok_or_else(|| Fault::new(format!("argument `{key}` must be a string"))))
        .transpose()
}

fn os_array(os: &OperatingSystem<'_>) -> Value {
    json!({ "code": text(&os.code), "name": text(&os.name), "version": text(&os.version) })
}

fn client_array(client: &Client<'_>) -> Value {
    json!({
        "type": text(&client.kind),
        "code": text(&client.code),
        "name": text(&client.name),
        "version": text(&client.version),
        "engine": text(&client.engine),
        "engineVersion": text(&client.engine_version),
    })
}

fn device_array(device: &Device<'_>) -> Value {
    json!({ "type": text(&device.kind), "brand": text(&device.brand), "model": text(&device.model) })
}

fn bot_array(bot: Option<&Bot<'_>>) -> Value {
    bot.map_or(Value::Null, |b| json!({ "name": bytes_value(&b.name), "category": bytes_value(&b.category) }))
}

fn inspect(agent: &UserAgent<'_>) -> Value {
    let memoized = std::ptr::eq(agent.operating_system(), agent.operating_system())
        && std::ptr::eq(agent.client(), agent.client())
        && std::ptr::eq(agent.device(), agent.device())
        && agent.bot().map(std::ptr::from_ref) == agent.bot().map(std::ptr::from_ref);
    json!({
        "raw": bytes_value(agent.raw()),
        "array": {
            "os": os_array(agent.operating_system()),
            "client": client_array(agent.client()),
            "device": device_array(agent.device()),
            "bot": bot_array(agent.bot()),
        },
        "isBot": agent.is_bot(),
        "osKnown": agent.operating_system().is_known(),
        "clientKnown": agent.client().is_known(),
        "clientBrowser": agent.client().is_browser(),
        "deviceKnown": agent.device().is_known(),
        "memoized": memoized,
    })
}

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    let a = Args(args);
    Ok(Outcome::Ok(match op {
        "agent.inspect" => inspect(&UserAgent::parse(&a.bytes("value")?)),
        "detect.operating_system" => os_array(&detection::operating_system(&a.bytes("value")?)),
        "detect.client" => client_array(&detection::client(&a.bytes("value")?)),
        "detect.device" => device_array(&detection::device(&a.bytes("value")?)),
        "detect.bot" => bot_array(detection::bot(&a.bytes("value")?).as_ref()),
        "os.new" => {
            let os = OperatingSystem { code: arg(&a, "code")?, name: arg(&a, "name")?, version: arg(&a, "version")? };
            json!({ "array": os_array(&os), "known": os.is_known() })
        }
        "client.new" => {
            let client = Client {
                kind: arg(&a, "type")?,
                code: arg(&a, "code")?,
                name: arg(&a, "name")?,
                version: arg(&a, "version")?,
                engine: arg(&a, "engine")?,
                engine_version: arg(&a, "engineVersion")?,
            };
            json!({ "array": client_array(&client), "known": client.is_known(), "browser": client.is_browser() })
        }
        "device.new" => {
            let device = Device { kind: arg(&a, "type")?, brand: arg(&a, "brand")?, model: arg(&a, "model")? };
            json!({ "array": device_array(&device), "known": device.is_known() })
        }
        "bot.new" => {
            let name = a.bytes("name")?;
            let bot = match arg(&a, "category")? {
                Some(category) => Bot::with_category(name, category),
                None => Bot::new(name),
            };
            bot_array(Some(&bot))
        }
        _ => return Err(Fault::new(format!("user-agent: unknown operation `{op}`"))),
    }))
}
