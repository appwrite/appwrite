//! Messaging providers, topics, subscribers and messages.

use php_std::{Array, Value};

use super::{Input, param, to_array, to_bool, to_int, to_string};
use crate::error::Result;
use crate::resource::{Base, Kind, Resource, ResourceRef};

fn based(kind: Kind, id: String, created: String, updated: String) -> ResourceRef {
    Resource::new(kind, Base { id, created_at: created, updated_at: updated, ..Base::default() })
}

/// A message (email, SMS or push).
///
/// PHP: `Utopia\Migration\Resources\Messaging\Message`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct Message {
    /// `email`, `sms` or `push`.
    pub provider_type: String,
    /// Topic ids.
    pub topics: Array,
    /// User ids.
    pub users: Array,
    /// Target ids.
    pub targets: Array,
    /// The provider-specific content.
    pub data: Array,
    /// The status (`draft`, `scheduled`, ...).
    pub message_status: String,
    /// When it is scheduled.
    pub scheduled_at: String,
    /// When it was delivered.
    pub delivered_at: String,
    /// Delivery errors.
    pub delivery_errors: Array,
    /// Messages delivered.
    pub delivered_total: i64,
}

impl Message {
    /// A resource of this message.
    pub fn resource(
        self,
        id: impl Into<String>,
        created_at: impl Into<String>,
        updated_at: impl Into<String>,
    ) -> ResourceRef {
        based(Kind::Message(self), id.into(), created_at.into(), updated_at.into())
    }

    /// PHP: `Message::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let p = |n, name| param("Utopia\\Migration\\Resources\\Messaging\\Message::__construct", n, name);
        let empty = || Value::Array(Array::new());
        let id = to_string(&i.raw("id"), &p(1, "id"))?;
        let m = Message {
            provider_type: to_string(&i.or("providerType", Value::from("")), &p(2, "providerType"))?,
            topics: to_array(&i.or("topics", empty()), &p(3, "topics"))?,
            users: to_array(&i.or("users", empty()), &p(4, "users"))?,
            targets: to_array(&i.or("targets", empty()), &p(5, "targets"))?,
            data: to_array(&i.or("data", empty()), &p(6, "data"))?,
            message_status: to_string(
                &i.get("messageStatus").or_else(|| i.get("status")).cloned().unwrap_or_else(|| Value::from("")),
                &p(7, "messageStatus"),
            )?,
            scheduled_at: to_string(&i.or("scheduledAt", Value::from("")), &p(8, "scheduledAt"))?,
            delivered_at: to_string(&i.or("deliveredAt", Value::from("")), &p(9, "deliveredAt"))?,
            delivery_errors: to_array(&i.or("deliveryErrors", empty()), &p(10, "deliveryErrors"))?,
            delivered_total: to_int(&i.or("deliveredTotal", Value::Int(0)), &p(11, "deliveredTotal"))?,
        };
        let created = to_string(&i.or("createdAt", Value::from("")), &p(12, "createdAt"))?;
        let updated = to_string(&i.or("updatedAt", Value::from("")), &p(13, "updatedAt"))?;
        Ok(m.resource(id, created, updated))
    }
}

/// A messaging provider.
///
/// PHP: `Utopia\Migration\Resources\Messaging\Provider` (`getProviderName()` is the name).
#[derive(Debug, Clone, PartialEq)]
pub struct Provider {
    /// The name.
    pub name: String,
    /// The provider (`mailgun`, `twilio`, ...).
    pub provider: String,
    /// `email`, `sms` or `push` (`getType()`).
    pub provider_type: String,
    /// Whether it is enabled.
    pub enabled: bool,
    /// Its credentials.
    pub credentials: Array,
    /// Its options.
    pub options: Array,
}

impl Default for Provider {
    fn default() -> Self {
        Self {
            name: String::new(),
            provider: String::new(),
            provider_type: String::new(),
            enabled: true,
            credentials: Array::new(),
            options: Array::new(),
        }
    }
}

impl Provider {
    /// A resource of this provider.
    pub fn resource(
        self,
        id: impl Into<String>,
        created_at: impl Into<String>,
        updated_at: impl Into<String>,
    ) -> ResourceRef {
        based(Kind::Provider(self), id.into(), created_at.into(), updated_at.into())
    }

    /// PHP: `Provider::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let p = |n, name| param("Utopia\\Migration\\Resources\\Messaging\\Provider::__construct", n, name);
        let id = to_string(&i.raw("id"), &p(1, "id"))?;
        let provider = Provider {
            name: to_string(&i.or("name", Value::from("")), &p(2, "name"))?,
            provider: to_string(&i.or("provider", Value::from("")), &p(3, "provider"))?,
            provider_type: to_string(&i.or("type", Value::from("")), &p(4, "type"))?,
            enabled: to_bool(&i.or("enabled", Value::Bool(true)), &p(5, "enabled"))?,
            credentials: to_array(&i.or("credentials", Value::Array(Array::new())), &p(6, "credentials"))?,
            options: to_array(&i.or("options", Value::Array(Array::new())), &p(7, "options"))?,
        };
        let created = to_string(&i.or("createdAt", Value::from("")), &p(8, "createdAt"))?;
        let updated = to_string(&i.or("updatedAt", Value::from("")), &p(9, "updatedAt"))?;
        Ok(provider.resource(id, created, updated))
    }
}

/// A topic subscriber.
///
/// PHP: `Utopia\Migration\Resources\Messaging\Subscriber`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct Subscriber {
    /// The topic.
    pub topic_id: String,
    /// The target.
    pub target_id: String,
    /// The target's user.
    pub user_id: String,
    /// The user's name.
    pub user_name: String,
    /// The target's provider type.
    pub provider_type: String,
}

impl Subscriber {
    /// A resource of this subscriber.
    pub fn resource(
        self,
        id: impl Into<String>,
        created_at: impl Into<String>,
        updated_at: impl Into<String>,
    ) -> ResourceRef {
        based(Kind::Subscriber(self), id.into(), created_at.into(), updated_at.into())
    }

    /// PHP: `Subscriber::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let p = |n, name| param("Utopia\\Migration\\Resources\\Messaging\\Subscriber::__construct", n, name);
        let s = |k: &str, n, name| to_string(&i.or(k, Value::from("")), &p(n, name));
        let id = to_string(&i.raw("id"), &p(1, "id"))?;
        let sub = Subscriber {
            topic_id: s("topicId", 2, "topicId")?,
            target_id: s("targetId", 3, "targetId")?,
            user_id: s("userId", 4, "userId")?,
            user_name: s("userName", 5, "userName")?,
            provider_type: s("providerType", 6, "providerType")?,
        };
        Ok(sub.resource(id, s("createdAt", 7, "createdAt")?, s("updatedAt", 8, "updatedAt")?))
    }
}

/// A messaging topic.
///
/// PHP: `Utopia\Migration\Resources\Messaging\Topic` (`getTopicName()` is the name).
#[derive(Debug, Clone, PartialEq, Default)]
pub struct Topic {
    /// The name.
    pub name: String,
    /// Roles allowed to subscribe.
    pub subscribe: Array,
}

impl Topic {
    /// A resource of this topic.
    pub fn resource(
        self,
        id: impl Into<String>,
        created_at: impl Into<String>,
        updated_at: impl Into<String>,
    ) -> ResourceRef {
        based(Kind::Topic(self), id.into(), created_at.into(), updated_at.into())
    }

    /// PHP: `Topic::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let p = |n, name| param("Utopia\\Migration\\Resources\\Messaging\\Topic::__construct", n, name);
        let id = to_string(&i.raw("id"), &p(1, "id"))?;
        let topic = Topic {
            name: to_string(&i.or("name", Value::from("")), &p(2, "name"))?,
            subscribe: to_array(&i.or("subscribe", Value::Array(Array::new())), &p(3, "subscribe"))?,
        };
        let created = to_string(&i.or("createdAt", Value::from("")), &p(4, "createdAt"))?;
        let updated = to_string(&i.or("updatedAt", Value::from("")), &p(5, "updatedAt"))?;
        Ok(topic.resource(id, created, updated))
    }
}
