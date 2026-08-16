/// PHP `Utopia\Feed\Key`.
#[derive(Debug)]
pub struct Key;

impl Key {
    #[must_use]
    pub fn feed(name: &str) -> String {
        format!("feed:{}", escape(name))
    }

    #[must_use]
    pub fn cursor(feed: &str, consumer: &str) -> String {
        format!("feed:{}:cursor:{}", escape(feed), escape(consumer))
    }

    #[must_use]
    pub fn tip(name: &str) -> String {
        format!("{}:tip", Self::feed(name))
    }
}

fn escape(name: &str) -> String {
    name.replace('%', "%25").replace(':', "%3A")
}
