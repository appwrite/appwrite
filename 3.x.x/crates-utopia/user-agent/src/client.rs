use serde::Serialize;

/// Detected client (browser or HTTP library).
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct Client {
    pub r#type: Option<String>,
    pub code: Option<String>,
    pub name: Option<String>,
    pub version: Option<String>,
    pub engine: Option<String>,
    pub engine_version: Option<String>,
}

impl Client {
    /// Empty / unknown client.
    pub fn new() -> Self {
        Self {
            r#type: None,
            code: None,
            name: None,
            version: None,
            engine: None,
            engine_version: None,
        }
    }

    /// Known client with optional engine metadata.
    pub fn known(
        r#type: &str,
        code: Option<&str>,
        name: &str,
        version: Option<String>,
        engine: Option<&str>,
        engine_version: Option<String>,
    ) -> Self {
        Self {
            r#type: Some(r#type.to_string()),
            code: code.map(str::to_string),
            name: Some(name.to_string()),
            version,
            engine: engine.map(str::to_string),
            engine_version,
        }
    }

    /// Whether a known client name was detected.
    pub fn is_known(&self) -> bool {
        self.name.is_some()
    }

    /// Whether the client is a browser.
    pub fn is_browser(&self) -> bool {
        self.r#type.as_deref() == Some("browser")
    }

    /// Serialize to a flat map (PHP `toArray` shape, `snake_case` keys).
    pub fn to_array(&self) -> ClientArray {
        ClientArray {
            r#type: self.r#type.clone(),
            code: self.code.clone(),
            name: self.name.clone(),
            version: self.version.clone(),
            engine: self.engine.clone(),
            engine_version: self.engine_version.clone(),
        }
    }
}

impl Default for Client {
    fn default() -> Self {
        Self::new()
    }
}

/// `Client::to_array()` output.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct ClientArray {
    pub r#type: Option<String>,
    pub code: Option<String>,
    pub name: Option<String>,
    pub version: Option<String>,
    pub engine: Option<String>,
    pub engine_version: Option<String>,
}
