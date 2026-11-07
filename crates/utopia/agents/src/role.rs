//! Who takes part in a conversation.

/// The role of messages an assistant sends.
///
/// PHP: `Role::ROLE_ASSISTANT`.
pub const ROLE_ASSISTANT: &str = "assistant";

/// The role of messages a user sends.
///
/// PHP: `Role::ROLE_USER`.
pub const ROLE_USER: &str = "user";

/// A participant of a conversation: an id, a display name, and the role
/// identifier its messages carry. Implement it for participants of your own.
///
/// PHP: the abstract class `Utopia\Agents\Role`.
pub trait Role {
    /// The participant's id.
    ///
    /// PHP: `getId()`.
    fn id(&self) -> &str;

    /// The display name (`""` when none).
    ///
    /// PHP: `getName()`.
    fn name(&self) -> &str;

    /// Renames the participant.
    ///
    /// PHP: `setName($name)`.
    fn set_name(&mut self, name: &str);

    /// The role identifier its messages carry ([`ROLE_USER`],
    /// [`ROLE_ASSISTANT`]).
    ///
    /// PHP: `getIdentifier()`.
    fn identifier(&self) -> &str;
}

macro_rules! participant {
    ($(#[$doc:meta])* $name:ident, $identifier:expr) => {
        $(#[$doc])*
        #[derive(Debug, Clone, PartialEq, Eq)]
        pub struct $name {
            id: String,
            name: String,
        }

        impl $name {
            /// A participant with an id and a display name (`""` for none).
            pub fn new(id: &str, name: &str) -> $name {
                $name { id: id.to_owned(), name: name.to_owned() }
            }
        }

        impl Role for $name {
            fn id(&self) -> &str {
                &self.id
            }

            fn name(&self) -> &str {
                &self.name
            }

            fn set_name(&mut self, name: &str) {
                self.name = name.to_owned();
            }

            fn identifier(&self) -> &str {
                $identifier
            }
        }
    };
}

participant!(
    /// A user: its messages carry [`ROLE_USER`].
    ///
    /// PHP: `Utopia\Agents\Roles\User`.
    ///
    /// ```
    /// use utopia_agents::{Role, User};
    ///
    /// let user = User::new("user-1", "Ada");
    /// assert_eq!((user.id(), user.name(), user.identifier()), ("user-1", "Ada", "user"));
    /// ```
    ///
    /// ```php
    /// use Utopia\Agents\Roles\User;
    ///
    /// $user = new User('user-1', 'Ada');
    /// echo $user->getId(), ' ', $user->getName(), ' ', $user->getIdentifier(), "\n"; // user-1 Ada user
    /// ```
    User,
    ROLE_USER
);

participant!(
    /// An assistant: its messages carry [`ROLE_ASSISTANT`].
    ///
    /// PHP: `Utopia\Agents\Roles\Assistant`.
    Assistant,
    ROLE_ASSISTANT
);
