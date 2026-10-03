//! PHP `Utopia\Messaging\Message` marker interface.

use crate::messages::{Discord, Email, Push, SMS};

/// Discriminator matching PHP `getMessageType()` class checks.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum MessageKind {
    /// PHP `Utopia\Messaging\Messages\SMS`.
    SMS,
    /// PHP `Utopia\Messaging\Messages\Email`.
    Email,
    /// PHP `Utopia\Messaging\Messages\Push`.
    Push,
    /// PHP `Utopia\Messaging\Messages\Discord`.
    Discord,
}

impl MessageKind {
    /// PHP FQCN returned by adapter `getMessageType()`.
    #[must_use]
    pub const fn class_name(self) -> &'static str {
        match self {
            Self::SMS => "Utopia\\Messaging\\Messages\\SMS",
            Self::Email => "Utopia\\Messaging\\Messages\\Email",
            Self::Push => "Utopia\\Messaging\\Messages\\Push",
            Self::Discord => "Utopia\\Messaging\\Messages\\Discord",
        }
    }
}

/// PHP `Utopia\Messaging\Message`.
pub trait Message: Send + Sync {
    /// PHP `setOrigin`.
    fn set_origin(&mut self, origin: Option<String>);

    /// PHP `getOrigin`.
    fn get_origin(&self) -> Option<&str>;

    /// Rust stand-in for PHP `is_a($message, $this->getMessageType())`.
    fn kind(&self) -> MessageKind;

    /// PHP `method_exists($message, 'getTo') ? count($message->getTo()) : None`.
    fn to_count(&self) -> Option<usize>;

    /// Borrow as SMS when `kind` is [`MessageKind::SMS`].
    fn as_sms(&self) -> Option<&SMS> {
        None
    }

    /// Borrow as email when `kind` is [`MessageKind::Email`].
    fn as_email(&self) -> Option<&Email> {
        None
    }

    /// Borrow as push when `kind` is [`MessageKind::Push`].
    fn as_push(&self) -> Option<&Push> {
        None
    }

    /// Borrow as Discord when `kind` is [`MessageKind::Discord`].
    fn as_discord(&self) -> Option<&Discord> {
        None
    }
}
