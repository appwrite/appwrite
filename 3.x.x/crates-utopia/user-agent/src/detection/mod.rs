mod bot;
mod client;
mod device;
mod operating_system;
mod util;

pub use bot::detect as detect_bot;
pub use client::detect as detect_client;
pub use device::detect as detect_device;
pub use operating_system::detect as detect_operating_system;
