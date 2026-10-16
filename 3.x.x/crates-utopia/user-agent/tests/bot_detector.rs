use utopia_user_agent::UserAgent;

#[test]
fn known_bots_match_php() {
    // Bing
    let bot =
        UserAgent::parse("Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)")
            .bot()
            .expect("bot");
    assert_eq!(bot.name, "Bingbot");

    // Facebook
    let bot = UserAgent::parse(
        "facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)",
    )
    .bot()
    .expect("bot");
    assert_eq!(bot.name, "Facebook External Hit");

    // OpenAI
    let bot = UserAgent::parse(
        "Mozilla/5.0 AppleWebKit/537.36; compatible; GPTBot/1.2; +https://openai.com/gptbot",
    )
    .bot()
    .expect("bot");
    assert_eq!(bot.name, "GPTBot");

    // Anthropic
    let bot = UserAgent::parse("ClaudeBot/1.0; +https://anthropic.com/claudebot")
        .bot()
        .expect("bot");
    assert_eq!(bot.name, "ClaudeBot");

    // monitor
    let bot = UserAgent::parse("UptimeRobot/2.0").bot().expect("bot");
    assert_eq!(bot.name, "UptimeRobot");

    // Perplexity
    let bot = UserAgent::parse(
        "Mozilla/5.0 (compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)",
    )
    .bot()
    .expect("bot");
    assert_eq!(bot.name, "PerplexityBot");

    // Meta
    let bot = UserAgent::parse(
        "meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)",
    )
    .bot()
    .expect("bot");
    assert_eq!(bot.name, "Meta External Agent");

    // Yahoo
    let bot = UserAgent::parse(
        "Mozilla/5.0 (compatible; Yahoo! Slurp; http://help.yahoo.com/help/us/ysearch/slurp)",
    )
    .bot()
    .expect("bot");
    assert_eq!(bot.name, "Yahoo! Slurp");

    // Seznam
    let bot = UserAgent::parse(
        "Mozilla/5.0 (compatible; SeznamBot/4.0; +http://napoveda.seznam.cz/seznambot-intro/)",
    )
    .bot()
    .expect("bot");
    assert_eq!(bot.name, "SeznamBot");

    // GoogleOther
    let bot = UserAgent::parse("Mozilla/5.0 (compatible; GoogleOther)")
        .bot()
        .expect("bot");
    assert_eq!(bot.name, "GoogleOther");

    // CommonCrawl
    let bot = UserAgent::parse("CCBot/2.0 (https://commoncrawl.org/faq/)")
        .bot()
        .expect("bot");
    assert_eq!(bot.name, "CCBot");

    // Pinterest
    let bot = UserAgent::parse("Pinterest/0.2 (+https://www.pinterest.com/bot.html)")
        .bot()
        .expect("bot");
    assert_eq!(bot.name, "Pinterest");

    // Sogou spider
    let bot =
        UserAgent::parse("Sogou web spider/4.0(+http://www.sogou.com/docs/help/webmasters.htm)")
            .bot()
            .expect("bot");
    assert_eq!(bot.name, "Sogou Spider");

    // generic crawler
    let bot = UserAgent::parse("Acme-Crawler/1.0").bot().expect("bot");
    assert_eq!(bot.name, "Acme-Crawler");

    // generic bot suffix
    let bot = UserAgent::parse("CustomBot/1.0").bot().expect("bot");
    assert_eq!(bot.name, "CustomBot");
}

#[test]
fn humans_are_not_bots() {
    // Chrome
    assert!(!UserAgent::parse(
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0.0.0 Safari/537.36"
    )
    .is_bot());

    // robotics product
    assert!(!UserAgent::parse("AcmeRobotics/2.0").is_bot());

    // bot substring
    assert!(!UserAgent::parse("BottomNavigationClient/1.0").is_bot());

    // Sogou browser
    assert!(!UserAgent::parse(
        "Mozilla/5.0 (Linux; Android 12) AppleWebKit/537.36 SogouMobileBrowser/5.28.0"
    )
    .is_bot());

    // Pinterest app
    assert!(!UserAgent::parse(
        "Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) Pinterest/11.20 (iPhone; iOS 16.0)"
    )
    .is_bot());

    // WhatsApp in-app browser
    assert!(!UserAgent::parse("Mozilla/5.0 (Linux; Android 13; SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/116.0.0.0 Mobile Safari/537.36 WhatsApp/2.24.6").is_bot());

    // empty
    assert!(!UserAgent::parse("").is_bot());
}
