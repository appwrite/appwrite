/// The error a streamed body ends with when the response is aborted: the
/// server closes the connection instead of sending the terminal chunk.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Aborted;

impl std::fmt::Display for Aborted {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str("response aborted")
    }
}

impl std::error::Error for Aborted {}
