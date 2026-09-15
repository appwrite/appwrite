//! Inbox ID generator (PHP `Utopia\NATS\Inbox`).

use rand::Rng;

const CHARSET: &[u8] = b"0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz";
const ID_LENGTH: usize = 22;

#[derive(Debug, Clone, Copy)]
pub struct Inbox;

impl Inbox {
    /// PHP `Inbox::create($prefix = '_INBOX')`.
    pub fn create() -> String {
        Self::with_prefix("_INBOX")
    }

    pub fn with_prefix(prefix: &str) -> String {
        format!("{}.{}", prefix, Self::generate_id())
    }

    pub fn generate_id() -> String {
        let mut rng = rand::thread_rng();
        let mut id = String::with_capacity(ID_LENGTH);
        for _ in 0..ID_LENGTH {
            let idx = rng.gen::<u8>() as usize % CHARSET.len();
            id.push(CHARSET[idx] as char);
        }
        id
    }
}
