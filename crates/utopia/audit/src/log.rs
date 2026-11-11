//! [`Log`]: one audit entry, as its adapter stored it.

use php_std::{Array, ArrayKey, Str, Value};

/// One audit log entry: its attributes as the adapter returned them, in
/// their order, with typed getters for the known ones.
///
/// The Database adapter returns the whole document (`$id`, `$sequence`,
/// `$createdAt`, ..., `userId`, `event`, ...); the ClickHouse adapter the
/// selected columns, with `id` as `$id`, `time` in ISO 8601 and the legacy
/// `userId`/`userType`/`userInternalId` copies of the actor columns.
///
/// ```
/// use php_std::{Array, ArrayKey, Value};
/// use utopia_audit::Log;
///
/// let mut log = Log::default();
/// log.set("$id", "a1").set("event", "users.create").set("tenant", "7");
/// assert_eq!(log.id(), "a1");
/// assert_eq!(log.event(), "users.create");
/// assert_eq!(log.tenant(), Some(7));
/// assert_eq!(log.user_id(), None);
/// ```
///
/// ```php
/// use Utopia\Audit\Log;
///
/// $log = new Log(['$id' => 'a1', 'event' => 'users.create', 'tenant' => '7']);
/// echo $log->getId(), ' ', $log->getEvent(), ' ', $log->getTenant(), "\n"; // a1 users.create 7
/// var_dump($log->getUserId()); // NULL
/// ```
///
/// PHP: `Utopia\Audit\Log` (an `ArrayObject`).
#[derive(Debug, Clone, Default, PartialEq)]
pub struct Log {
    attributes: Array,
}

impl Log {
    /// A log of these attributes.
    ///
    /// PHP: `new Log($input)`.
    pub fn new(attributes: Array) -> Log {
        Log { attributes }
    }

    /// The attribute `key`, if set.
    ///
    /// PHP: `getAttribute($key)` (which returns the default for a missing one).
    pub fn get(&self, key: &str) -> Option<&Value> {
        self.attributes.get_str(key)
    }

    /// Sets the attribute `key`.
    ///
    /// PHP: `setAttribute($key, $value)`.
    pub fn set(&mut self, key: &str, value: impl Into<Value>) -> &mut Log {
        self.attributes.set(ArrayKey::normalize(Str::from(key)), value.into());
        self
    }

    /// Removes the attribute `key`, if set.
    ///
    /// PHP: `removeAttribute($key)`.
    pub fn remove(&mut self, key: &str) -> &mut Log {
        self.attributes.remove(&ArrayKey::normalize(Str::from(key)));
        self
    }

    /// Whether the attribute `key` is set (to anything, `null` included).
    ///
    /// PHP: `isSet($key)`.
    pub fn is_set(&self, key: &str) -> bool {
        self.get(key).is_some()
    }

    /// The attributes, in order.
    ///
    /// PHP: `getArrayCopy()`.
    pub fn attributes(&self) -> &Array {
        &self.attributes
    }

    /// The attributes, owned.
    pub fn into_attributes(self) -> Array {
        self.attributes
    }

    /// The string attribute `key`, or `None` when it is missing or not a string.
    fn text(&self, key: &str) -> Option<&str> {
        match self.get(key) {
            Some(Value::Str(s)) => std::str::from_utf8(s.as_bytes()).ok(),
            _ => None,
        }
    }

    /// The id (`$id`), or `""`.
    ///
    /// PHP: `getId()`.
    pub fn id(&self) -> &str {
        self.text("$id").unwrap_or("")
    }

    /// The user id (`userId`).
    ///
    /// PHP: `getUserId()`.
    pub fn user_id(&self) -> Option<&str> {
        self.text("userId")
    }

    /// The actor id (`actorId`).
    ///
    /// PHP: `getActorId()`.
    pub fn actor_id(&self) -> Option<&str> {
        self.text("actorId")
    }

    /// The actor type (`actorType`).
    ///
    /// PHP: `getActorType()`.
    pub fn actor_type(&self) -> Option<&str> {
        self.text("actorType")
    }

    /// The actor's internal id (`actorInternalId`).
    ///
    /// PHP: `getActorInternalId()`.
    pub fn actor_internal_id(&self) -> Option<&str> {
        self.text("actorInternalId")
    }

    /// The event (`event`), or `""`.
    ///
    /// PHP: `getEvent()`.
    pub fn event(&self) -> &str {
        self.text("event").unwrap_or("")
    }

    /// The resource (`resource`), or `""`.
    ///
    /// PHP: `getResource()`.
    pub fn resource(&self) -> &str {
        self.text("resource").unwrap_or("")
    }

    /// The SDK name (`sdk`).
    ///
    /// PHP: `getSdk()`.
    pub fn sdk(&self) -> Option<&str> {
        self.text("sdk")
    }

    /// The SDK version (`sdkVersion`).
    ///
    /// PHP: `getSdkVersion()`.
    pub fn sdk_version(&self) -> Option<&str> {
        self.text("sdkVersion")
    }

    /// The user agent's OS code (`osCode`, ClickHouse).
    ///
    /// PHP: `getOsCode()`.
    pub fn os_code(&self) -> Option<&str> {
        self.text("osCode")
    }

    /// The user agent's OS name (`osName`, ClickHouse).
    ///
    /// PHP: `getOsName()`.
    pub fn os_name(&self) -> Option<&str> {
        self.text("osName")
    }

    /// The user agent's OS version (`osVersion`, ClickHouse).
    ///
    /// PHP: `getOsVersion()`.
    pub fn os_version(&self) -> Option<&str> {
        self.text("osVersion")
    }

    /// The user agent's client type (`clientType`, ClickHouse).
    ///
    /// PHP: `getClientType()`.
    pub fn client_type(&self) -> Option<&str> {
        self.text("clientType")
    }

    /// The user agent's client code (`clientCode`, ClickHouse).
    ///
    /// PHP: `getClientCode()`.
    pub fn client_code(&self) -> Option<&str> {
        self.text("clientCode")
    }

    /// The user agent's client name (`clientName`, ClickHouse).
    ///
    /// PHP: `getClientName()`.
    pub fn client_name(&self) -> Option<&str> {
        self.text("clientName")
    }

    /// The user agent's client version (`clientVersion`, ClickHouse).
    ///
    /// PHP: `getClientVersion()`.
    pub fn client_version(&self) -> Option<&str> {
        self.text("clientVersion")
    }

    /// The user agent's client engine (`clientEngine`, ClickHouse).
    ///
    /// PHP: `getClientEngine()`.
    pub fn client_engine(&self) -> Option<&str> {
        self.text("clientEngine")
    }

    /// The user agent's client engine version (`clientEngineVersion`, ClickHouse).
    ///
    /// PHP: `getClientEngineVersion()`.
    pub fn client_engine_version(&self) -> Option<&str> {
        self.text("clientEngineVersion")
    }

    /// The user agent's device type (`deviceName`, ClickHouse).
    ///
    /// PHP: `getDeviceName()`.
    pub fn device_name(&self) -> Option<&str> {
        self.text("deviceName")
    }

    /// The user agent's device brand (`deviceBrand`, ClickHouse).
    ///
    /// PHP: `getDeviceBrand()`.
    pub fn device_brand(&self) -> Option<&str> {
        self.text("deviceBrand")
    }

    /// The user agent's device model (`deviceModel`, ClickHouse).
    ///
    /// PHP: `getDeviceModel()`.
    pub fn device_model(&self) -> Option<&str> {
        self.text("deviceModel")
    }

    /// The user agent (`userAgent`), or `""`.
    ///
    /// PHP: `getUserAgent()`.
    pub fn user_agent(&self) -> &str {
        self.text("userAgent").unwrap_or("")
    }

    /// The IP address (`ip`), or `""`.
    ///
    /// PHP: `getIp()`.
    pub fn ip(&self) -> &str {
        self.text("ip").unwrap_or("")
    }

    /// The time (`time`), or `""`.
    ///
    /// PHP: `getTime()`.
    pub fn time(&self) -> &str {
        self.text("time").unwrap_or("")
    }

    /// The additional data (`data`), or an empty array when it is missing
    /// or not an array.
    ///
    /// PHP: `getData()`.
    pub fn data(&self) -> Array {
        match self.get("data") {
            Some(Value::Array(a)) => a.clone(),
            _ => Array::new(),
        }
    }

    /// The tenant (`tenant`): an integer, or a numeric string or float cast
    /// to one; `None` otherwise.
    ///
    /// PHP: `getTenant()`.
    pub fn tenant(&self) -> Option<i64> {
        match self.get("tenant")? {
            Value::Int(i) => Some(*i),
            Value::Float(f) => Some(php_std::format::dval_to_lval(*f)),
            Value::Str(s) if php_std::value::numeric_bytes_ex(s.as_bytes()).is_some() => {
                Some(php_std::format::str_to_int(s.as_bytes()))
            }
            _ => None,
        }
    }
}

impl From<Array> for Log {
    fn from(attributes: Array) -> Log {
        Log::new(attributes)
    }
}
