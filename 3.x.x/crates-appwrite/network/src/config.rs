//! Centralised CORS method / header lists. Rust port of `app/config/cors.php`.

/// PHP `$corsConfig['allowedMethods']`.
pub const ALLOWED_METHODS: &[&str] = &["GET", "POST", "PUT", "PATCH", "DELETE"];

/// PHP `$corsConfig['allowedHeaders']`.
pub const ALLOWED_HEADERS: &[&str] = &[
    "Accept",
    "Origin",
    "Cookie",
    "Set-Cookie",
    "Content-Type",
    "Content-Range",
    "X-Appwrite-Project",
    "X-Appwrite-Key",
    "X-Appwrite-Dev-Key",
    "X-Appwrite-Locale",
    "X-Appwrite-Mode",
    "X-Appwrite-JWT",
    "X-Appwrite-Organization",
    "X-Appwrite-Response-Format",
    "X-Appwrite-Timeout",
    "X-Appwrite-ID",
    "X-Appwrite-Timestamp",
    "X-Appwrite-Session",
    "X-Appwrite-Platform",
    "X-Appwrite-Impersonate-User-Id",
    "X-Appwrite-Impersonate-User-Email",
    "X-Appwrite-Impersonate-User-Phone",
    "X-SDK-Version",
    "X-SDK-Name",
    "X-SDK-Language",
    "X-SDK-Platform",
    "X-SDK-GraphQL",
    "X-SDK-Profile",
    "Range",
    "Cache-Control",
    "Expires",
    "Pragma",
    "X-Fallback-Cookies",
    "X-Requested-With",
    "X-Forwarded-For",
    "X-Forwarded-User-Agent",
];

/// PHP `$corsConfig['exposedHeaders']`.
pub const EXPOSED_HEADERS: &[&str] = &["X-Appwrite-Session", "X-Fallback-Cookies"];

/// PHP `Cors` constructor default `$maxAge` (86400 seconds).
pub const MAX_AGE: u64 = 86_400;
