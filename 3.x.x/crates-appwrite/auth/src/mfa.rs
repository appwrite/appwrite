//! MFA factor type identifiers. Rust port of `Appwrite\Auth\MFA\Type`
//! (`src/Appwrite/Auth/MFA/Type.php`).

/// Time-based one-time password factor.
pub const TOTP: &str = "totp";
/// Email OTP factor.
pub const EMAIL: &str = "email";
/// SMS/phone OTP factor.
pub const PHONE: &str = "phone";
/// Recovery code factor. PHP value is `"recoveryCode"` (not `"recovery"`).
pub const RECOVERY_CODE: &str = "recoveryCode";
/// Custom, integration-defined factor.
pub const CUSTOM: &str = "custom";

/// All recognized MFA factor type identifiers, in the same order PHP's
/// `MFAFactors` response model declares its rules.
pub const ALL: &[&str] = &[TOTP, PHONE, EMAIL, RECOVERY_CODE, CUSTOM];
