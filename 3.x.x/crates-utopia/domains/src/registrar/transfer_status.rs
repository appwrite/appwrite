use time::OffsetDateTime;

/// Transfer status (PHP `TransferStatusEnum`).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum TransferStatusEnum {
    Transferrable,
    NotTransferrable,
    PendingOwner,
    PendingAdmin,
    PendingRegistry,
    Completed,
    Cancelled,
    ServiceUnavailable,
}

impl TransferStatusEnum {
    /// PHP enum string value.
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Transferrable => "transferrable",
            Self::NotTransferrable => "not_transferrable",
            Self::PendingOwner => "pending_owner",
            Self::PendingAdmin => "pending_admin",
            Self::PendingRegistry => "pending_registry",
            Self::Completed => "completed",
            Self::Cancelled => "cancelled",
            Self::ServiceUnavailable => "service_unavailable",
        }
    }
}

/// Transfer status payload (PHP `TransferStatus`).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct TransferStatus {
    pub status: TransferStatusEnum,
    pub reason: Option<String>,
    pub timestamp: Option<OffsetDateTime>,
}

impl TransferStatus {
    /// PHP constructor.
    pub fn new(
        status: TransferStatusEnum,
        reason: Option<String>,
        timestamp: Option<OffsetDateTime>,
    ) -> Self {
        Self {
            status,
            reason,
            timestamp,
        }
    }
}
