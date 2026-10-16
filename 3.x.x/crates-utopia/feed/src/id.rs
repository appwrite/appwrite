use crate::FeedError;

/// PHP `Utopia\Feed\Id`.
#[derive(Debug)]
pub struct Id;

impl Id {
    #[must_use]
    pub fn is_valid(id: &str) -> bool {
        parse(id).is_some()
    }

    #[must_use]
    pub fn encode(timestamp: i64, sequence: i64) -> String {
        format!("{timestamp}-{sequence}")
    }

    pub fn decode(id: &str) -> Result<(i64, i64), FeedError> {
        parse(id).ok_or_else(|| FeedError::invalid(format!("Invalid feed event id: {id}")))
    }

    pub fn after(id: &str) -> Result<String, FeedError> {
        let (timestamp, sequence) = Self::decode(id)?;
        Ok(Self::encode(timestamp, sequence + 1))
    }
}

fn parse(id: &str) -> Option<(i64, i64)> {
    let (left, right) = id.split_once('-')?;
    if left.is_empty() || right.is_empty() || id.matches('-').count() != 1 {
        return None;
    }
    if !left.bytes().all(|b| b.is_ascii_digit()) || !right.bytes().all(|b| b.is_ascii_digit()) {
        return None;
    }
    Some((left.parse().ok()?, right.parse().ok()?))
}
