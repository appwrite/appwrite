/// Domain update payload (PHP `Utopia\Domains\Registrar\UpdateDetails`).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub struct UpdateDetails {
    pub auto_renew: Option<bool>,
}

impl UpdateDetails {
    /// PHP constructor (`$autoRenew = null`).
    pub fn new(auto_renew: Option<bool>) -> Self {
        Self { auto_renew }
    }
}
