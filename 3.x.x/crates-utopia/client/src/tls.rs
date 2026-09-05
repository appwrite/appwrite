/// PHP `Utopia\Client\Tls`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Tls {
    V1_0,
    V1_1,
    V1_2,
    V1_3,
}

impl Tls {
    /// PHP enum case name (`V1_2`).
    #[must_use]
    pub fn name(self) -> &'static str {
        match self {
            Self::V1_0 => "V1_0",
            Self::V1_1 => "V1_1",
            Self::V1_2 => "V1_2",
            Self::V1_3 => "V1_3",
        }
    }

    pub(crate) fn reqwest(self) -> reqwest::tls::Version {
        match self {
            Self::V1_0 => reqwest::tls::Version::TLS_1_0,
            Self::V1_1 => reqwest::tls::Version::TLS_1_1,
            Self::V1_2 => reqwest::tls::Version::TLS_1_2,
            Self::V1_3 => reqwest::tls::Version::TLS_1_3,
        }
    }
}
