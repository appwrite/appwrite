//! `Utopia\UserAgent\Detection\BotDetector`.

use std::borrow::Cow;

use php_std::string;

use crate::pattern::pattern;
use crate::values::Bot;

/// Known bots: a lowercase needle, the bot's name and its category. The
/// first needle the lowercased user-agent contains wins, so more specific
/// needles come first (`applebot-extended` before `applebot`).
const BOTS: [(&str, &str, &str); 69] = [
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

/// `BotDetector::detect()`: a known bot, or a token ending in `bot`,
/// `crawler`, `spider`, `scraper` or `slurp` (category [`Bot::CRAWLER`]).
pub fn bot(user_agent: &[u8]) -> Option<Bot<'_>> {
    if user_agent.is_empty() {
        return None;
    }
    let lower = string::strtolower(user_agent);
    if let Some((_, name, category)) =
        BOTS.iter().find(|(needle, _, _)| string::str_contains(&lower, needle.as_bytes()))
    {
        return Some(Bot::with_category(name.as_bytes(), category.as_bytes()));
    }
    let generic = pattern!(r"/(?:^|[\s;()+_-])([a-z0-9_-]*(?:bot|crawler|spider|scraper|slurp))(?:[\/\s;()+_-]|$)/i");
    let name = generic.captures(user_agent)?.get(1)?;
    Some(Bot::new(Cow::Borrowed(string::trim(name, b"_-"))))
}
