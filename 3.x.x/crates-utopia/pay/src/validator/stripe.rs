use hmac::{Hmac, Mac};
use sha2::Sha256;

type HmacSha256 = Hmac<Sha256>;

/// PHP `Utopia\Pay\Validator\Stripe\Webhook`.
#[derive(Debug, Default, Clone, Copy)]
pub struct Webhook;

impl Webhook {
    pub const DEFAULT_TOLERANCE: i64 = 300;
    pub const EXPECTED_SCHEME: &'static str = "v1";

    /// PHP `isValid($payload, $header, $secret, $tolerance = null)`.
    #[must_use]
    pub fn is_valid(
        &self,
        payload: &str,
        header: &str,
        secret: &str,
        tolerance: Option<i64>,
    ) -> bool {
        let timestamp = match get_timestamp(header) {
            Some(t) if t >= 0 => t,
            _ => return false,
        };
        let signatures = get_signatures(header, Self::EXPECTED_SCHEME);
        if signatures.is_empty() {
            return false;
        }
        let signed_payload = format!("{timestamp}.{payload}");
        let expected = compute_signature(&signed_payload, secret);
        if !signatures.iter().any(|sig| secure_compare(&expected, sig)) {
            return false;
        }
        if let Some(tolerance) = tolerance {
            if tolerance > 0 {
                let now = std::time::SystemTime::now()
                    .duration_since(std::time::UNIX_EPOCH)
                    .map(|d| d.as_secs() as i64)
                    .unwrap_or(0);
                if (now - timestamp).abs() > tolerance {
                    return false;
                }
            }
        }
        true
    }

    #[must_use]
    pub fn secure_compare(&self, a: &str, b: &str) -> bool {
        secure_compare(a, b)
    }
}

fn get_timestamp(header: &str) -> Option<i64> {
    for item in header.split(',') {
        let mut parts = item.splitn(2, '=');
        let key = parts.next()?;
        let value = parts.next().unwrap_or("");
        if key == "t" {
            return value.parse().ok().or(Some(-1));
        }
    }
    Some(-1)
}

fn get_signatures(header: &str, scheme: &str) -> Vec<String> {
    let mut signatures = Vec::new();
    for item in header.split(',') {
        let mut parts = item.splitn(2, '=');
        let key = parts.next().unwrap_or("").trim();
        let value = parts.next().unwrap_or("");
        if key == scheme {
            signatures.push(value.to_owned());
        }
    }
    signatures
}

fn compute_signature(payload: &str, secret: &str) -> String {
    let mut mac = HmacSha256::new_from_slice(secret.as_bytes()).expect("HMAC key");
    mac.update(payload.as_bytes());
    hex::encode(mac.finalize().into_bytes())
}

fn secure_compare(a: &str, b: &str) -> bool {
    if a.len() != b.len() {
        return false;
    }
    let mut result = 0u8;
    for (x, y) in a.bytes().zip(b.bytes()) {
        result |= x ^ y;
    }
    result == 0
}
