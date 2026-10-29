<?php

declare(strict_types=1);

namespace Utopia\UserAgent\Detection;

use Utopia\UserAgent\Bot;

final class BotDetector
{
    /** @var array<string, array{string, string}> */
    private const array BOTS = [
        'googlebot' => ['Googlebot', 'search crawler'],
        'google-inspectiontool' => ['Google Inspection Tool', 'search crawler'],
        'googleother' => ['GoogleOther', 'search crawler'],
        'google-extended' => ['Google Extended', 'ai crawler'],
        'storebot-google' => ['Google StoreBot', 'search crawler'],
        'adsbot-google' => ['Google AdsBot', 'advertising crawler'],
        'mediapartners-google' => ['Google AdSense', 'advertising crawler'],
        'bingbot' => ['Bingbot', 'search crawler'],
        'bingpreview' => ['Bing Preview', 'search crawler'],
        'duckduckbot' => ['DuckDuckBot', 'search crawler'],
        'duckassistbot' => ['DuckAssistBot', 'ai crawler'],
        'baiduspider' => ['Baiduspider', 'search crawler'],
        'yandexbot' => ['YandexBot', 'search crawler'],
        'yandeximages' => ['YandexImages', 'search crawler'],
        'slurp' => ['Yahoo! Slurp', 'search crawler'],
        'seznambot' => ['SeznamBot', 'search crawler'],
        'sogou web spider' => ['Sogou Spider', 'search crawler'],
        'exabot' => ['Exabot', 'search crawler'],
        'yeti' => ['Naver Bot', 'search crawler'],
        'yisouspider' => ['YisouSpider', 'search crawler'],
        'applebot-extended' => ['Applebot Extended', 'ai crawler'],
        'applebot' => ['Applebot', 'search crawler'],
        'petalbot' => ['PetalBot', 'search crawler'],
        'facebookexternalhit' => ['Facebook External Hit', 'social preview'],
        'facebookbot' => ['Facebook Bot', 'social preview'],
        'facebot' => ['Facebook Bot', 'social preview'],
        'meta-externalagent' => ['Meta External Agent', 'ai crawler'],
        'meta-externalfetcher' => ['Meta External Fetcher', 'social preview'],
        'twitterbot' => ['Twitterbot', 'social preview'],
        'linkedinbot' => ['LinkedInBot', 'social preview'],
        'slackbot' => ['Slackbot', 'social preview'],
        'discordbot' => ['Discordbot', 'social preview'],
        'telegrambot' => ['TelegramBot', 'social preview'],
        'pinterestbot' => ['Pinterestbot', 'social preview'],
        'pinterest/0.' => ['Pinterest', 'social preview'],
        'redditbot' => ['Redditbot', 'social preview'],
        'ahrefsbot' => ['AhrefsBot', 'site crawler'],
        'semrushbot' => ['SemrushBot', 'site crawler'],
        'mj12bot' => ['MJ12bot', 'site crawler'],
        'dotbot' => ['DotBot', 'site crawler'],
        'dataforseobot' => ['DataForSeoBot', 'site crawler'],
        'blexbot' => ['BLEXBot', 'site crawler'],
        'screaming frog' => ['Screaming Frog SEO Spider', 'site crawler'],
        'gptbot' => ['GPTBot', 'ai crawler'],
        'oai-searchbot' => ['OAI SearchBot', 'ai crawler'],
        'chatgpt-user' => ['ChatGPT User', 'ai assistant'],
        'claudebot' => ['ClaudeBot', 'ai crawler'],
        'claude-user' => ['Claude User', 'ai assistant'],
        'claude-searchbot' => ['Claude SearchBot', 'ai crawler'],
        'claude-web' => ['Claude Web', 'ai assistant'],
        'anthropic-ai' => ['Anthropic AI', 'ai crawler'],
        'perplexitybot' => ['PerplexityBot', 'ai crawler'],
        'perplexity-user' => ['Perplexity User', 'ai assistant'],
        'amazonbot' => ['Amazonbot', 'ai crawler'],
        'bytespider' => ['Bytespider', 'ai crawler'],
        'ccbot' => ['CCBot', 'ai crawler'],
        'youbot' => ['YouBot', 'ai crawler'],
        'cohere-ai' => ['Cohere AI', 'ai crawler'],
        'cohere-training-data-crawler' => ['Cohere', 'ai crawler'],
        'diffbot' => ['Diffbot', 'ai crawler'],
        'imagesiftbot' => ['ImageSift Bot', 'ai crawler'],
        'timpibot' => ['Timpibot', 'ai crawler'],
        'headlesschrome' => ['Headless Chrome', 'automation'],
        'phantomjs' => ['PhantomJS', 'automation'],
        'lighthouse' => ['Lighthouse', 'site monitor'],
        'uptimerobot' => ['UptimeRobot', 'site monitor'],
        'pingdom' => ['Pingdom', 'site monitor'],
        'statuscake' => ['StatusCake', 'site monitor'],
        'gtmetrix' => ['GTmetrix', 'site monitor'],
    ];

    /** @var list<string> */
    private const array SUFFIXES = ['bot', 'crawler', 'spider', 'scraper', 'slurp'];

    public static function detect(string $userAgent): ?Bot
    {
        if ($userAgent === '') {
            return null;
        }

        $lower = strtolower($userAgent);
        foreach (self::BOTS as $needle => [$name, $category]) {
            if (str_contains($lower, $needle)) {
                return new Bot($name, $category);
            }
        }

        $token = self::token($userAgent, $lower);
        if ($token !== null) {
            return new Bot(trim($token, '_-'), 'crawler');
        }

        return null;
    }

    /**
     * The first word ending in a bot suffix, where a word is a run of letters, digits, `_` and `-` that starts at the
     * beginning, after a space, `;`, `(`, `)`, `+`, `_` or `-`, and ends at the end or before one of those or `/`.
     * Each run is scanned once, so the cost stays linear in the length of the user agent.
     */
    private static function token(string $userAgent, string $lower): ?string
    {
        $length = \strlen($lower);
        $position = 0;
        while ($position < $length) {
            if (!self::isWord($lower[$position])) {
                $position++;
                continue;
            }

            $start = $position;
            while ($position < $length && self::isWord($lower[$position])) {
                $position++;
            }

            $first = self::firstStart($lower, $start, $position);
            if ($first === null) {
                continue;
            }

            for ($end = $position; $end > $first; $end--) {
                if (!self::closes($lower, $end, $position)) {
                    continue;
                }
                foreach (self::SUFFIXES as $suffix) {
                    $offset = $end - \strlen($suffix);
                    if ($offset >= $first && substr_compare($lower, $suffix, $offset, \strlen($suffix)) === 0) {
                        return substr($userAgent, $first, $end - $first);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Where the earliest word inside the run from $start to $end may begin.
     */
    private static function firstStart(string $lower, int $start, int $end): ?int
    {
        if ($start === 0 || self::isSeparator($lower[$start - 1])) {
            return $start;
        }

        for ($position = $start; $position < $end - 1; $position++) {
            if ($lower[$position] === '_' || $lower[$position] === '-') {
                return $position + 1;
            }
        }

        return null;
    }

    /**
     * Whether a word can end at $end inside the run that stops at $runEnd.
     */
    private static function closes(string $lower, int $end, int $runEnd): bool
    {
        if ($end < $runEnd) {
            return $lower[$end] === '_' || $lower[$end] === '-';
        }

        return $end === \strlen($lower) || $lower[$end] === '/' || self::isSeparator($lower[$end]);
    }

    private static function isWord(string $character): bool
    {
        return ctype_alnum($character) || $character === '_' || $character === '-';
    }

    private static function isSeparator(string $character): bool
    {
        return ctype_space($character) || str_contains(';()+', $character);
    }
}
