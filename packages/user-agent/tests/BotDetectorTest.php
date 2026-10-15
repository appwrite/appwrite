<?php

declare(strict_types=1);

namespace Utopia\UserAgent\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\UserAgent\UserAgent;

final class BotDetectorTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function knownBots(): array
    {
        return [
            'Bing' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', 'Bingbot'],
            'Facebook' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', 'Facebook External Hit'],
            'OpenAI' => ['Mozilla/5.0 AppleWebKit/537.36; compatible; GPTBot/1.2; +https://openai.com/gptbot', 'GPTBot'],
            'Anthropic' => ['ClaudeBot/1.0; +https://anthropic.com/claudebot', 'ClaudeBot'],
            'monitor' => ['UptimeRobot/2.0', 'UptimeRobot'],
            'Perplexity' => ['Mozilla/5.0 (compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)', 'PerplexityBot'],
            'Meta' => ['meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)', 'Meta External Agent'],
            'Yahoo' => ['Mozilla/5.0 (compatible; Yahoo! Slurp; http://help.yahoo.com/help/us/ysearch/slurp)', 'Yahoo! Slurp'],
            'Seznam' => ['Mozilla/5.0 (compatible; SeznamBot/4.0; +http://napoveda.seznam.cz/seznambot-intro/)', 'SeznamBot'],
            'GoogleOther' => ['Mozilla/5.0 (compatible; GoogleOther)', 'GoogleOther'],
            'CommonCrawl' => ['CCBot/2.0 (https://commoncrawl.org/faq/)', 'CCBot'],
            'Pinterest' => ['Pinterest/0.2 (+https://www.pinterest.com/bot.html)', 'Pinterest'],
            'Sogou spider' => ['Sogou web spider/4.0(+http://www.sogou.com/docs/help/webmasters.htm)', 'Sogou Spider'],
            'generic crawler' => ['Acme-Crawler/1.0', 'Acme-Crawler'],
            'generic bot suffix' => ['CustomBot/1.0', 'CustomBot'],
        ];
    }

    #[DataProvider('knownBots')]
    public function testKnownBots(string $userAgent, string $name): void
    {
        $bot = UserAgent::parse($userAgent)->bot();

        $this->assertNotNull($bot);
        $this->assertSame($name, $bot->name);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function humans(): array
    {
        return [
            'Chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0.0.0 Safari/537.36'],
            'robotics product' => ['AcmeRobotics/2.0'],
            'bot substring' => ['BottomNavigationClient/1.0'],
            'Sogou browser' => ['Mozilla/5.0 (Linux; Android 12) AppleWebKit/537.36 SogouMobileBrowser/5.28.0'],
            'Pinterest app' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) Pinterest/11.20 (iPhone; iOS 16.0)'],
            'WhatsApp in-app browser' => ['Mozilla/5.0 (Linux; Android 13; SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/116.0.0.0 Mobile Safari/537.36 WhatsApp/2.24.6'],
            'empty' => [''],
        ];
    }

    #[DataProvider('humans')]
    public function testHumans(string $userAgent): void
    {
        $this->assertFalse(UserAgent::parse($userAgent)->isBot());
    }
}
