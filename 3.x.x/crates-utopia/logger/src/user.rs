//! Log user identity (PHP `Utopia\Logger\Log\User`).

/// User who caused a log event.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct User {
    user_id: Option<String>,
    user_email: Option<String>,
    user_name: Option<String>,
}

impl User {
    /// Create a user. All fields are optional (PHP constructor defaults to `null`).
    pub fn new(user_id: Option<&str>, user_email: Option<&str>, user_name: Option<&str>) -> Self {
        Self {
            user_id: user_id.map(ToOwned::to_owned),
            user_email: user_email.map(ToOwned::to_owned),
            user_name: user_name.map(ToOwned::to_owned),
        }
    }

    /// User identifier (PHP `getId()`).
    pub fn get_id(&self) -> Option<&str> {
        self.user_id.as_deref()
    }

    /// User email (PHP `getEmail()`).
    pub fn get_email(&self) -> Option<&str> {
        self.user_email.as_deref()
    }

    /// User display name (PHP `getUsername()`).
    pub fn get_username(&self) -> Option<&str> {
        self.user_name.as_deref()
    }
}
