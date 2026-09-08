/// PHP `Utopia\CircuitBreaker\CircuitState`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum CircuitState {
    Closed,
    Open,
    HalfOpen,
}

impl CircuitState {
    #[must_use]
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Closed => "closed",
            Self::Open => "open",
            Self::HalfOpen => "half_open",
        }
    }

    #[must_use]
    pub fn value(self) -> f64 {
        match self {
            Self::Closed => 0.0,
            Self::Open => 1.0,
            Self::HalfOpen => 2.0,
        }
    }

    #[must_use]
    pub fn try_from_str(value: &str) -> Option<Self> {
        match value {
            "closed" => Some(Self::Closed),
            "open" => Some(Self::Open),
            "half_open" => Some(Self::HalfOpen),
            _ => None,
        }
    }
}
