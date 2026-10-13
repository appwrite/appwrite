//! Email templates.

use php_std::{Array, Value};

use super::{Input, cast_string, param, to_string};
use crate::error::Result;
use crate::resource::{Base, Kind, Resource, ResourceRef};

/// A customised project email template for one locale.
///
/// PHP: `Utopia\Migration\Resources\Templates\EmailTemplate` (its array form
/// names the body `message`).
#[derive(Debug, Clone, PartialEq, Default)]
pub struct EmailTemplate {
    /// The template (`verification`, `magicSession`, ...).
    pub template_id: String,
    /// The locale.
    pub locale: String,
    /// The subject.
    pub subject: String,
    /// The body (`getBody()`).
    pub body: String,
    /// The sender name.
    pub sender_name: String,
    /// The sender email.
    pub sender_email: String,
    /// The reply-to email.
    pub reply_to_email: String,
    /// The reply-to name.
    pub reply_to_name: String,
}

impl EmailTemplate {
    /// A resource of this template.
    pub fn resource(
        self,
        id: impl Into<String>,
        created_at: impl Into<String>,
        updated_at: impl Into<String>,
    ) -> ResourceRef {
        Resource::new(
            Kind::EmailTemplate(self),
            Base { id: id.into(), created_at: created_at.into(), updated_at: updated_at.into(), ..Base::default() },
        )
    }

    /// PHP: `EmailTemplate::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let class = "Utopia\\Migration\\Resources\\Templates\\EmailTemplate::__construct";
        let id = to_string(&i.raw("id"), &param(class, 1, "id"))?;
        let s = |k: &str| cast_string(&i.or(k, Value::from("")));
        let t = EmailTemplate {
            template_id: cast_string(&i.raw("templateId")),
            locale: cast_string(&i.raw("locale")),
            subject: s("subject"),
            body: s("message"),
            sender_name: s("senderName"),
            sender_email: s("senderEmail"),
            reply_to_email: s("replyToEmail"),
            reply_to_name: s("replyToName"),
        };
        let created = to_string(&i.or("createdAt", Value::from("")), &param(class, 10, "createdAt"))?;
        let updated = to_string(&i.or("updatedAt", Value::from("")), &param(class, 11, "updatedAt"))?;
        Ok(t.resource(id, created, updated))
    }
}
