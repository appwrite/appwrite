//! Authentication proof implementations.

mod code;
mod password;
mod phrase;
mod token;

pub use code::Code;
pub use password::Password;
pub use phrase::Phrase;
pub use token::Token;
