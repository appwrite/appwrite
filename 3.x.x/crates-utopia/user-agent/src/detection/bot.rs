use std::sync::OnceLock;

use regex::Regex;

use crate::bot::Bot;

const BOTS: [(&str, &str, &str); 69] = [
    ("googlebot", "Googlebot", "search crawler"),
    (
        "google-inspectiontool",
        "Google Inspection Tool",
        "search crawler",
    ),
    ("googleother", "GoogleOther", "search crawler"),
    ("google-extended", "Google Extended", "ai crawler"),
    ("storebot-google", "Google StoreBot", "search crawler"),
    ("adsbot-google", "Google AdsBot", "advertising crawler"),
    (
        "mediapartners-google",
        "Google AdSense",
        "advertising crawler",
    ),
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
    (
        "facebookexternalhit",
        "Facebook External Hit",
        "social preview",
    ),
    ("facebookbot", "Facebook Bot", "social preview"),
    ("facebot", "Facebook Bot", "social preview"),
    ("meta-externalagent", "Meta External Agent", "ai crawler"),
    (
        "meta-externalfetcher",
        "Meta External Fetcher",
        "social preview",
    ),
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
    (
        "screaming frog",
        "Screaming Frog SEO Spider",
        "site crawler",
    ),
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

static RE_GENERIC_BOT: OnceLock<Regex> = OnceLock::new();

fn re_generic_bot() -> &'static Regex {
    RE_GENERIC_BOT.get_or_init(|| {
        Regex::new(
            r"(?i)(?:^|[\s;()+_-])([a-z0-9_-]*(?:bot|crawler|spider|scraper|slurp))(?:[/\s;()+_-]|$)",
        )
        .unwrap()
    })
}

/// Detect a bot from a user-agent string.
pub fn detect(user_agent: &str) -> Option<Bot> {
    if user_agent.is_empty() {
        return None;
    }

    let lower = user_agent.to_ascii_lowercase();
    for (needle, name, category) in BOTS {
        if lower.contains(needle) {
            return Some(Bot::new(name, category));
        }
    }

    if let Some(caps) = re_generic_bot().captures(user_agent) {
        let name = caps.get(1).expect("group 1").as_str();
        let trimmed = trim_bot_name(name);
        return Some(Bot::new(&trimmed, "crawler"));
    }

    None
}

fn trim_bot_name(name: &str) -> String {
    name.trim_matches(&['_', '-'][..]).to_string()
}
