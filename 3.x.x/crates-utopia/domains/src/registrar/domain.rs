use time::OffsetDateTime;

/// Registrar domain details (PHP `Utopia\Domains\Registrar\Domain`).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct RegistrarDomain {
    pub domain: String,
    pub created_at: Option<OffsetDateTime>,
    pub expires_at: Option<OffsetDateTime>,
    pub auto_renew: Option<bool>,
    pub nameservers: Option<Vec<String>>,
}

impl RegistrarDomain {
    /// PHP constructor.
    pub fn new(
        domain: impl Into<String>,
        created_at: Option<OffsetDateTime>,
        expires_at: Option<OffsetDateTime>,
        auto_renew: Option<bool>,
        nameservers: Option<Vec<String>>,
    ) -> Self {
        Self {
            domain: domain.into(),
            created_at,
            expires_at,
            auto_renew,
            nameservers,
        }
    }
}
