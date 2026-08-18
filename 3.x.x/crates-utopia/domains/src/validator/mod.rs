//! Domain validators implementing [`utopia_validators::Validator`].

mod apex_domain;
mod public_domain;

pub use apex_domain::ApexDomain;
pub use public_domain::PublicDomain;
