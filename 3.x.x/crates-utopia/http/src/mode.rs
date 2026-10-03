#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub enum Mode {
    #[default]
    None,
    Production,
    Development,
    Stage,
}

impl Mode {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::None => "",
            Self::Production => "production",
            Self::Development => "development",
            Self::Stage => "stage",
        }
    }
}
