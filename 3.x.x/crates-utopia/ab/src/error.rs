use thiserror::Error;

/// Errors raised while running an A/B [`crate::Test`].
#[derive(Debug, Error, Clone, PartialEq, Eq)]
pub enum AbError {
    /// Sum of explicit variation probabilities exceeded 100.
    ///
    /// Matches the PHP message from `Utopia\AB\Test::chance()`.
    #[error("Test Error: Total variation probabilities is bigger than 100%")]
    ProbabilitiesExceed100,

    /// Weighted selection did not land on a named variation.
    #[error("Test Error: No variation selected")]
    NoVariation,
}
