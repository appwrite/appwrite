//! Bot detection: known bots by name, then a generic rule for crawlers.
//!
//! PHP: `Utopia\UserAgent\Detection\BotDetector`.

use std::borrow::Cow;
use std::sync::LazyLock;

use aho_corasick::AhoCorasick;
use php_std::string;

use crate::values::Bot;

/// Known bots: a lowercase needle, the bot's name and its category. The
/// first needle the lowercased user-agent contains wins, so more specific
/// needles come first (`applebot-extended` before `applebot`).
pub(super) const BOTS: [(&str, &str, &str); 69] = [
    ("googlebot", "Googlebot", "search crawler"),
    ("google-inspectiontool", "Google Inspection Tool", "search crawler"),
    ("googleother", "GoogleOther", "search crawler"),
    ("google-extended", "Google Extended", "ai crawler"),
    ("storebot-google", "Google StoreBot", "search crawler"),
    ("adsbot-google", "Google AdsBot", "advertising crawler"),
    ("mediapartners-google", "Google AdSense", "advertising crawler"),
    ("bingbot", "Bingbot", "search crawler"),
    ("bingpreview", "Bing Preview", "search crawler"),
    ("duckduckbot", "DuckDuckBot", "search crawler"),
    ("duckassistbot", "DuckAssistBot", "ai crawler"),
    ("baiduspider", "Baiduspider", "search crawler"),
    ("yandexbot", "YandexBot", "search crawler"),
    ("yandeximages", "YandexImages", "search crawler"),
    ("slurp", "Yahoo! Slurp", "search crawler"),
    ("seznambot", "SeznamBot", "search crawler"),
    ("sogou web spider", "Sogou Spider", "search crawler"),
    ("exabot", "Exabot", "search crawler"),
    ("yeti", "Naver Bot", "search crawler"),
    ("yisouspider", "YisouSpider", "search crawler"),
    ("applebot-extended", "Applebot Extended", "ai crawler"),
    ("applebot", "Applebot", "search crawler"),
    ("petalbot", "PetalBot", "search crawler"),
    ("facebookexternalhit", "Facebook External Hit", "social preview"),
    ("facebookbot", "Facebook Bot", "social preview"),
    ("facebot", "Facebook Bot", "social preview"),
    ("meta-externalagent", "Meta External Agent", "ai crawler"),
    ("meta-externalfetcher", "Meta External Fetcher", "social preview"),
    ("twitterbot", "Twitterbot", "social preview"),
    ("linkedinbot", "LinkedInBot", "social preview"),
    ("slackbot", "Slackbot", "social preview"),
    ("discordbot", "Discordbot", "social preview"),
    ("telegrambot", "TelegramBot", "social preview"),
    ("pinterestbot", "Pinterestbot", "social preview"),
    ("pinterest/0.", "Pinterest", "social preview"),
    ("redditbot", "Redditbot", "social preview"),
    ("ahrefsbot", "AhrefsBot", "site crawler"),
    ("semrushbot", "SemrushBot", "site crawler"),
    ("mj12bot", "MJ12bot", "site crawler"),
    ("dotbot", "DotBot", "site crawler"),
    ("dataforseobot", "DataForSeoBot", "site crawler"),
    ("blexbot", "BLEXBot", "site crawler"),
    ("screaming frog", "Screaming Frog SEO Spider", "site crawler"),
    ("gptbot", "GPTBot", "ai crawler"),
    ("oai-searchbot", "OAI SearchBot", "ai crawler"),
    ("chatgpt-user", "ChatGPT User", "ai assistant"),
    ("claudebot", "ClaudeBot", "ai crawler"),
    ("claude-user", "Claude User", "ai assistant"),
    ("claude-searchbot", "Claude SearchBot", "ai crawler"),
    ("claude-web", "Claude Web", "ai assistant"),
    ("anthropic-ai", "Anthropic AI", "ai crawler"),
    ("perplexitybot", "PerplexityBot", "ai crawler"),
    ("perplexity-user", "Perplexity User", "ai assistant"),
    ("amazonbot", "Amazonbot", "ai crawler"),
    ("bytespider", "Bytespider", "ai crawler"),
    ("ccbot", "CCBot", "ai crawler"),
    ("youbot", "YouBot", "ai crawler"),
    ("cohere-ai", "Cohere AI", "ai crawler"),
    ("cohere-training-data-crawler", "Cohere", "ai crawler"),
    ("diffbot", "Diffbot", "ai crawler"),
    ("imagesiftbot", "ImageSift Bot", "ai crawler"),
    ("timpibot", "Timpibot", "ai crawler"),
    ("headlesschrome", "Headless Chrome", "automation"),
    ("phantomjs", "PhantomJS", "automation"),
    ("lighthouse", "Lighthouse", "site monitor"),
    ("uptimerobot", "UptimeRobot", "site monitor"),
    ("pingdom", "Pingdom", "site monitor"),
    ("statuscake", "StatusCake", "site monitor"),
    ("gtmetrix", "GTmetrix", "site monitor"),
];

/// Words the generic rule needs: its pattern ends every name with one.
pub(super) const GENERIC: [&str; 5] = ["bot", "crawler", "spider", "scraper", "slurp"];

/// One case-insensitive pass over the string finds every needle of
/// [`BOTS`] it contains, then the words of [`GENERIC`].
///
/// PHP lowercases the string (`strtolower()`, ASCII only) and tests each
/// needle with `str_contains()`; the needles are lowercase ASCII, so
/// matching them ignoring ASCII case is the same test.
static NEEDLES: LazyLock<AhoCorasick> = LazyLock::new(|| {
    let needles = BOTS.iter().map(|(needle, _, _)| *needle).chain(GENERIC);
    match AhoCorasick::builder().ascii_case_insensitive(true).build(needles) {
        Ok(needles) => needles,
        Err(e) => panic!("bot needles: {e}"),
    }
});

/// The bot behind a user-agent string: a known bot, or one with a token
/// ending in `bot`, `crawler`, `spider`, `scraper` or `slurp` (category
/// [`Bot::CRAWLER`]).
///
/// PHP: `BotDetector::detect()`.
pub fn bot(user_agent: &[u8]) -> Option<Bot<'_>> {
    if user_agent.is_empty() {
        return None;
    }
    // The first needle in table order wins, wherever it occurs; without
    // any, not even the generic rule can match.
    let first = NEEDLES.find_overlapping_iter(user_agent).map(|m| m.pattern().as_usize()).min()?;
    if let Some((_, name, category)) = BOTS.get(first) {
        return Some(Bot::with_category(name.as_bytes(), category.as_bytes()));
    }
    let name = token(user_agent)?;
    Some(Bot::new(Cow::Borrowed(string::trim(name, b"_-"))))
}

/// The first word ending in a [`GENERIC`] suffix, where a word is a run of
/// letters, digits, `_` and `-` that starts at the beginning, after a space,
/// `;`, `(`, `)`, `+`, `_` or `-`, and ends at the end or before one of those
/// or `/`. Each run is scanned once, so the cost stays linear in the length
/// of the user agent.
///
/// PHP: `BotDetector::token()` (private), on the ASCII-lowercased string.
fn token(user_agent: &[u8]) -> Option<&[u8]> {
    let length = user_agent.len();
    let mut position = 0;
    while position < length {
        if !is_word(user_agent[position]) {
            position += 1;
            continue;
        }
        let start = position;
        while position < length && is_word(user_agent[position]) {
            position += 1;
        }
        let Some(first) = first_start(user_agent, start, position) else {
            continue;
        };
        for end in (first + 1..=position).rev() {
            if !closes(user_agent, end, position) {
                continue;
            }
            for suffix in GENERIC {
                if let Some(offset) = end.checked_sub(suffix.len())
                    && offset >= first
                    && user_agent[offset..end].eq_ignore_ascii_case(suffix.as_bytes())
                {
                    return Some(&user_agent[first..end]);
                }
            }
        }
    }
    None
}

/// Where the earliest word inside the run from `start` to `end` may begin.
///
/// PHP: `BotDetector::firstStart()` (private).
fn first_start(s: &[u8], start: usize, end: usize) -> Option<usize> {
    if start == 0 || is_separator(s[start - 1]) {
        return Some(start);
    }
    (start..end.saturating_sub(1)).find(|&position| matches!(s[position], b'_' | b'-')).map(|position| position + 1)
}

/// Whether a word can end at `end` inside the run that stops at `run_end`.
///
/// PHP: `BotDetector::closes()` (private).
fn closes(s: &[u8], end: usize, run_end: usize) -> bool {
    if end < run_end {
        return matches!(s[end], b'_' | b'-');
    }
    end == s.len() || s[end] == b'/' || is_separator(s[end])
}

/// PHP: `ctype_alnum($c) || $c === '_' || $c === '-'`.
fn is_word(c: u8) -> bool {
    c.is_ascii_alphanumeric() || c == b'_' || c == b'-'
}

/// PHP: `ctype_space($c) || str_contains(';()+', $c)`.
fn is_separator(c: u8) -> bool {
    matches!(c, b' ' | b'\t' | b'\n' | b'\r' | b'\x0B' | b'\x0C' | b';' | b'(' | b')' | b'+')
}
