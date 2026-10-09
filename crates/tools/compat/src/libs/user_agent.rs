//! Compat adapter for `user-agent`: maps `tests/compat/user-agent/spec.json`
//! operations onto `utopia-user-agent`. The `toArray()` shapes are built
//! from the public fields.

use std::borrow::Cow;

use serde_json::{Value, json};
use utopia_user_agent::{Bot, Client, Device, OperatingSystem, Text, UserAgent, detection};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes, bytes_value};

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
