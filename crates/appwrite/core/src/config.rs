//! Process configuration from `_APP_*` environment variables.
//!
//! Read once at startup; values follow PHP `System::getEnv` semantics
//! (unset, empty or `"0"` means "use the default").

use std::path::PathBuf;
use std::time::Duration;

use utopia_system::{env, env_or, env_raw};

/// An integer setting: the variable (trimmed) when it is set, not empty or
/// `"0"`, and an integer; `default` otherwise.
pub fn env_int(name: &str, default: i64) -> i64 {
    env(name).and_then(|v| v.trim().parse().ok()).unwrap_or(default)
}

/// Number of CPUs available to the process (sizes the Rust runtime's
/// worker and connection pools).
pub fn cpus() -> usize {
    std::thread::available_parallelism().map(|n| n.get()).unwrap_or(1)
}

/// Database connection settings for one pool.
#[derive(Debug, Clone)]
pub struct DatabaseDsn {
    pub name: String,
    pub scheme: String,
    pub host: String,
    pub port: u16,
    pub user: String,
    pub password: String,
    pub database: String,
}

/// Redis connection settings.
#[derive(Debug, Clone)]
pub struct RedisDsn {
    pub host: String,
    pub port: u16,
    pub user: Option<String>,
    pub password: Option<String>,
}

impl RedisDsn {
    pub fn url(&self) -> String {
        let auth = match (&self.user, &self.password) {
            (Some(u), Some(p)) => format!("{u}:{p}@"),
            (None, Some(p)) => format!(":{p}@"),
            _ => String::new(),
        };
        format!("redis://{auth}{}:{}/", self.host, self.port)
    }
}

/// All configuration used by the Rust API.
#[derive(Debug, Clone)]
pub struct Config {
    pub development: bool,
    pub edition: String,
    pub locale: String,
    pub region: String,
    pub openssl_key_v1: String,
    pub domain: String,
    pub console_domain: String,
    pub migration_host: Option<String>,
    /// `app/config/platform.php`, as PHP's function messages carry it.
    pub platform: serde_json::Value,
    pub console_url: String,
    pub console_schemas: Vec<String>,
    pub force_https: bool,
    pub trusted_headers: Vec<String>,
    pub trusted_proxies: Vec<String>,
    pub compression: bool,
    pub compression_min_size: usize,
    pub response_format: Option<String>,
    pub databases: Vec<DatabaseDsn>,
    pub shared_tables: Vec<String>,
    pub redis: RedisDsn,
    pub usage_stats: bool,
    pub abuse: bool,
    pub pwned_dsn: String,
    pub geo_endpoint: Option<String>,
    pub geo_secret: Option<String>,
    pub assets: PathBuf,
    pub port: u16,
    pub db_pool_size: usize,
    pub redis_connections: usize,
    pub statement_timeout: Duration,
    pub queue_functions: String,
    pub queue_webhooks: String,
    pub queue_deletes: String,
    pub queue_usage: String,
    pub queue_audits: String,
    pub queue_mails: String,
    pub queue_messaging: String,
    /// `_APP_SMTP_HOST`: empty when the instance sends no email.
    pub smtp_host: String,
    /// `_APP_SMS_PROVIDER`: empty when the instance sends no SMS.
    pub sms_provider: String,
    /// `_APP_SYSTEM_EMAIL_ADDRESS` (`APP_EMAIL_TEAM` by default).
    pub system_email_address: String,
    /// `_APP_SYSTEM_EMAIL_NAME` (`APP_NAME . ' Server'` by default).
    pub system_email_name: String,
    /// `_APP_CONSOLE_URL` is set explicitly (it then carries its own port).
    pub console_url_explicit: bool,
    /// The scheme the OAuth2 and magic URL links use: `http` when
    /// `_APP_OPTIONS_FORCE_HTTPS` is `disabled`, else `https`.
    pub link_scheme: &'static str,
    /// `_APP_CONSOLE_ROOT_SESSION=enabled`: console cookies go on the
    /// registrable domain.
    pub console_root_session: bool,
    pub console_trusted_projects: Vec<String>,
    /// `_APP_OPTIONS_ABUSE_INCREASED_LIMIT_PROJECTS`: projects whose limits
    /// are a hundred times higher.
    pub abuse_increased_projects: Vec<String>,
    /// `_APP_CONSOLE_COUNTRIES_DENYLIST`: countries (upper case) refused
    /// the console's `auth` routes.
    pub console_countries_denylist: Vec<String>,
    /// `_APP_RULES_FORMAT=md5`: rules are stored under the MD5 of their
    /// domain (before the 1.7 migration).
    pub rules_md5: bool,
    pub locking: bool,
}

fn list(value: Option<String>) -> Vec<String> {
    value.map(|v| v.split(',').map(|s| s.trim().to_owned()).filter(|s| !s.is_empty()).collect()).unwrap_or_default()
}

impl Config {
    /// Loads configuration from the environment.
    pub fn from_env() -> Self {
        let domain = env_or("_APP_DOMAIN", "localhost").into_owned();
        let console_domain = env("_APP_CONSOLE_DOMAIN").unwrap_or_else(|| domain.clone());
        let force_https = env_or("_APP_OPTIONS_FORCE_HTTPS", "disabled") == "enabled";
        let console_url = env("_APP_CONSOLE_URL")
            .unwrap_or_else(|| format!("{}://{}", if force_https { "https" } else { "http" }, console_domain))
            .trim_end_matches('/')
            .to_owned();

        let main = DatabaseDsn {
            name: "db_main".to_owned(),
            scheme: env_or("_APP_DB_ADAPTER", "postgresql").into_owned(),
            host: env_or("_APP_DB_HOST", "postgresql").into_owned(),
            port: env_int("_APP_DB_PORT", 5432) as u16,
            user: env_or("_APP_DB_USER", "").into_owned(),
            password: env_or("_APP_DB_PASS", "").into_owned(),
            database: env_or("_APP_DB_SCHEMA", "appwrite").into_owned(),
        };
        let mut databases = vec![main];
        // `_APP_CONNECTIONS_DATABASE`: "name=scheme://user:pass@host:port/db,..."
        if let Some(raw) = env("_APP_CONNECTIONS_DATABASE") {
            databases.clear();
            for entry in raw.split(',') {
                let Some((name, dsn)) = entry.split_once('=') else { continue };
                let Ok(parsed) = utopia_dsn::Dsn::parse(dsn.trim()) else { continue };
                let text = |b: Option<&[u8]>| b.map(|b| String::from_utf8_lossy(b).into_owned()).unwrap_or_default();
                databases.push(DatabaseDsn {
                    name: name.trim().to_owned(),
                    scheme: parsed.scheme().to_owned(),
                    host: parsed.host().to_owned(),
                    port: parsed.port().unwrap_or(5432),
                    user: text(parsed.user()),
                    password: text(parsed.password()),
                    database: parsed.path().to_owned(),
                });
            }
        }

        let cpus = cpus();
        Self {
            development: env_or("_APP_ENV", "production") == "development",
            edition: env_or("_APP_EDITION", "self-hosted").into_owned(),
            locale: env_or("_APP_LOCALE", "en").into_owned(),
            region: env_or("_APP_REGION", "default").into_owned(),
            openssl_key_v1: env_raw("_APP_OPENSSL_KEY_V1").unwrap_or_default(),
            migration_host: env("_APP_MIGRATION_HOST"),
            platform: platform(),
            console_url,
            console_schemas: list(env("_APP_CONSOLE_SCHEMA")),
            force_https,
            trusted_headers: list(Some(env_or("_APP_TRUSTED_HEADERS", "x-forwarded-for").into_owned()))
                .into_iter()
                .map(|h| h.to_lowercase())
                .collect(),
            trusted_proxies: match env_raw("_APP_TRUSTED_PROXIES") {
                Some(v) => list(Some(v)),
                None => list(Some("127.0.0.1,::1,10.0.0.0/8,100.64.0.0/10,172.16.0.0/12,192.168.0.0/16".to_owned())),
            },
            compression: env_or("_APP_COMPRESSION_ENABLED", "enabled") == "enabled",
            compression_min_size: env_int("_APP_COMPRESSION_MIN_SIZE_BYTES", 1024) as usize,
            response_format: env("_APP_SYSTEM_RESPONSE_FORMAT"),
            databases,
            shared_tables: list(env("_APP_DATABASE_SHARED_TABLES")),
            redis: RedisDsn {
                host: env_or("_APP_REDIS_HOST", "redis").into_owned(),
                port: env_int("_APP_REDIS_PORT", 6379) as u16,
                user: env("_APP_REDIS_USER"),
                password: env("_APP_REDIS_PASS"),
            },
            usage_stats: env_or("_APP_USAGE_STATS", "enabled") != "disabled",
            abuse: env_or("_APP_OPTIONS_ABUSE", "enabled") != "disabled",
            pwned_dsn: env_or("_APP_PWNED_PASSWORDS_DSN", "none://localhost").into_owned(),
            geo_endpoint: env("_APP_GEO_ENDPOINT"),
            geo_secret: env("_APP_GEO_SECRET"),
            assets: PathBuf::from(env_or("_APP_RUST_ASSETS", "/usr/src/code").as_ref()),
            port: env_int("_APP_RUST_PORT", 8080) as u16,
            db_pool_size: env_int("_APP_RUST_DB_POOL", (cpus * 8).max(16) as i64) as usize,
            redis_connections: env_int("_APP_RUST_REDIS_CONNECTIONS", cpus.clamp(1, 8) as i64) as usize,
            statement_timeout: Duration::from_millis(env_int("_APP_RUST_DB_TIMEOUT_MS", 15000) as u64),
            queue_functions: env_or("_APP_FUNCTIONS_QUEUE_NAME", "v1-functions").into_owned(),
            queue_webhooks: env_or("_APP_WEBHOOK_QUEUE_NAME", "v1-webhooks").into_owned(),
            queue_deletes: env_or("_APP_DELETE_QUEUE_NAME", "v1-deletes").into_owned(),
            queue_usage: env_or("_APP_STATS_USAGE_QUEUE_NAME", "v1-stats-usage").into_owned(),
            queue_audits: env_or("_APP_AUDITS_QUEUE_NAME", "v1-audits").into_owned(),
            queue_mails: env_or("_APP_MAILS_QUEUE_NAME", "v1-mails").into_owned(),
            queue_messaging: env_or("_APP_MESSAGING_QUEUE_NAME", "v1-messaging").into_owned(),
            smtp_host: env_or("_APP_SMTP_HOST", "").into_owned(),
            sms_provider: env_or("_APP_SMS_PROVIDER", "").into_owned(),
            system_email_address: env_or("_APP_SYSTEM_EMAIL_ADDRESS", "team@localhost.test").into_owned(),
            system_email_name: env_or("_APP_SYSTEM_EMAIL_NAME", "Appwrite Server").into_owned(),
            console_url_explicit: env("_APP_CONSOLE_URL").is_some(),
            link_scheme: if env("_APP_OPTIONS_FORCE_HTTPS").as_deref() == Some("disabled") { "http" } else { "https" },
            console_root_session: env_or("_APP_CONSOLE_ROOT_SESSION", "disabled") == "enabled",
            console_trusted_projects: list(env("_APP_CONSOLE_TRUSTED_PROJECTS")),
            rules_md5: env_or("_APP_RULES_FORMAT", "") == "md5",
            abuse_increased_projects: list(env("_APP_OPTIONS_ABUSE_INCREASED_LIMIT_PROJECTS")),
            console_countries_denylist: list(env("_APP_CONSOLE_COUNTRIES_DENYLIST"))
                .into_iter()
                .map(|c| c.to_uppercase())
                .collect(),
            locking: env_or("_APP_LOCKING_ENABLED", "enabled") == "enabled",
            domain,
            console_domain,
        }
    }

    /// `platform.hostnames`: API, console and migration hosts (as a list).
    pub fn platform_hostnames(&self) -> Vec<String> {
        let console = utopia_system::env_or("_APP_CONSOLE_DOMAIN", "localhost").into_owned();
        let mut out: Vec<String> = Vec::new();
        for h in [Some(self.domain.clone()), Some(console), self.migration_host.clone()].into_iter().flatten() {
            if !h.is_empty() && h != "0" && !out.contains(&h) {
                out.push(h);
            }
        }
        out
    }

    /// Path of a bundled asset (relative to the repository root).
    pub fn asset(&self, relative: &str) -> PathBuf {
        self.assets.join(relative)
    }
}

/// `app/config/platform.php`.
fn platform() -> serde_json::Value {
    use serde_json::{Map, Value};
    // `array_filter()` and `array_unique()` keep keys: with a gap, PHP
    // encodes the array as an object.
    fn php_array(items: Vec<Option<String>>) -> Value {
        let mut kept: Vec<(usize, String)> = Vec::new();
        for (i, item) in items.into_iter().enumerate() {
            let item = item.unwrap_or_default();
            if kept.iter().any(|(_, k)| *k == item) || item.is_empty() || item == "0" {
                continue;
            }
            kept.push((i, item));
        }
        if kept.iter().enumerate().all(|(n, (i, _))| n == *i) {
            Value::Array(kept.into_iter().map(|(_, v)| Value::String(v)).collect())
        } else {
            Value::Object(kept.into_iter().map(|(i, v)| (i.to_string(), Value::String(v))).collect::<Map<_, _>>())
        }
    }
    let first = |name: &str| env_or(name, "").split(',').next().unwrap_or("").to_owned();
    let domain = env_or("_APP_DOMAIN", "localhost").into_owned();
    let console_domain = env_or("_APP_CONSOLE_DOMAIN", &domain).into_owned();
    let scheme = if env("_APP_OPTIONS_FORCE_HTTPS").as_deref() == Some("disabled") { "http" } else { "https" };
    let console_url =
        env_or("_APP_CONSOLE_URL", &format!("{scheme}://{console_domain}")).trim_end_matches('/').to_owned();
    let mut m = Map::new();
    m.insert("apiHostname".into(), Value::String(domain.clone()));
    m.insert("consoleHostname".into(), Value::String(console_domain));
    m.insert("consoleUrl".into(), Value::String(console_url));
    m.insert(
        "hostnames".into(),
        php_array(vec![
            Some(domain),
            Some(env_or("_APP_CONSOLE_DOMAIN", "localhost").into_owned()),
            env("_APP_MIGRATION_HOST"),
        ]),
    );
    m.insert(
        "schemas".into(),
        php_array(env_or("_APP_CONSOLE_SCHEMA", "").split(',').map(|s| Some(s.to_owned())).collect()),
    );
    let constants = [
        ("platformName", "Appwrite"),
        ("logoUrl", "https://cloud.appwrite.io/images/mails/logo.png"),
        ("accentColor", "#fd366e"),
        ("footerImageUrl", "https://appwrite.io/email/footer.png"),
        ("twitterUrl", "https://twitter.com/appwrite"),
        ("discordUrl", "https://appwrite.io/discord"),
        ("githubUrl", "https://github.com/appwrite"),
        ("termsUrl", "https://appwrite.io/terms"),
        ("privacyUrl", "https://appwrite.io/privacy"),
        ("websiteUrl", "https://appwrite.io"),
        ("emailSenderName", "Appwrite"),
    ];
    for (k, v) in constants {
        m.insert(k.into(), Value::String(v.into()));
    }
    m.insert("sitesDomain".into(), Value::String(first("_APP_DOMAIN_SITES")));
    m.insert("functionsDomain".into(), Value::String(first("_APP_DOMAIN_FUNCTIONS")));
    Value::Object(m)
}
