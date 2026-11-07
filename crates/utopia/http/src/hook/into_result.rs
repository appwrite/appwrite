use crate::error::Result;

/// What a callback returns: nothing when it cannot fail, or a [`Result`]
/// when it uses `?`, whose error goes to the error hooks.
pub trait IntoResult {
    /// The callback's outcome as a [`Result`].
    fn into_result(self) -> Result<()>;
}

impl IntoResult for () {
    fn into_result(self) -> Result<()> {
        Ok(())
    }
}

impl IntoResult for Result<()> {
    fn into_result(self) -> Result<()> {
        self
    }
}
