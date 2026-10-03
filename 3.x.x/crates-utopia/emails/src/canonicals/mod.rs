//! Canonical email providers (PHP `Utopia\Emails\Canonicals`).

mod fastmail;
mod generic;
mod gmail;
mod icloud;
mod outlook;
mod protonmail;
mod provider;
mod walla;
mod yahoo;
mod yandex;

pub use fastmail::Fastmail;
pub use generic::Generic;
pub use gmail::Gmail;
pub use icloud::Icloud;
pub use outlook::Outlook;
pub use protonmail::Protonmail;
pub use provider::{Canonical, Provider};
pub use walla::Walla;
pub use yahoo::Yahoo;
pub use yandex::Yandex;
