use time::OffsetDateTime;

/// Renewal result (PHP `Utopia\Domains\Registrar\Renewal`).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Renewal {
    pub order_id: Option<String>,
    pub expires_at: Option<OffsetDateTime>,
}

impl Renewal {
    /// PHP constructor.
    pub fn new(order_id: Option<String>, expires_at: Option<OffsetDateTime>) -> Self {
        Self {
            order_id,
            expires_at,
        }
    }
}
