/**
 * Classify User-Agent strings from coding agents, answer engines, and
 * retrieval crawlers. Used on server pageviews for markdown / llms.txt so
 * Plausible can separate agent fetches from human HTML visits.
 *
 * Keep this module free of Vite path aliases. `server-analytics.ts` is loaded
 * raw by Bun from `server.ts`.
 */

export type AgentUserAgentKind = 'agent' | 'bot' | 'human'

export type ClassifiedAgentUserAgent = {
  kind: AgentUserAgentKind
  /** Short stable label for Plausible props. `none` when unclassified. */
  agent: string
}

const AGENT_PATTERNS: Array<{ agent: string; pattern: RegExp }> = [
  { agent: 'chatgpt-user', pattern: /chatgpt-user/i },
  { agent: 'oai-search', pattern: /oai-searchbot/i },
  { agent: 'gptbot', pattern: /gptbot/i },
  { agent: 'claude-user', pattern: /claude-user/i },
  { agent: 'claude-search', pattern: /claude-searchbot/i },
  { agent: 'claudebot', pattern: /claudebot/i },
  { agent: 'cursor', pattern: /\bcursor\b/i },
  { agent: 'codex', pattern: /\bcodex\b/i },
  { agent: 'perplexity', pattern: /perplexity/i },
  { agent: 'gemini', pattern: /google-extended|\bgemini\b/i },
  { agent: 'copilot', pattern: /github-copilot|\bcopilot\b/i },
  { agent: 'devin', pattern: /\bdevin\b/i },
  { agent: 'opencode', pattern: /opencode/i },
  { agent: 'windsurf', pattern: /windsurf/i },
  { agent: 'grok', pattern: /\bgrok\b/i },
]

const BOT_PATTERNS: Array<{ agent: string; pattern: RegExp }> = [
  { agent: 'bingbot', pattern: /bingbot/i },
  { agent: 'googlebot', pattern: /googlebot/i },
  { agent: 'applebot', pattern: /applebot/i },
  { agent: 'bytespider', pattern: /bytespider/i },
  { agent: 'ccbot', pattern: /ccbot/i },
  { agent: 'other-bot', pattern: /\bbot\b|\bcrawler\b|\bspider\b/i },
]

export function classifyAgentUserAgent(
  userAgent: string | null | undefined,
): ClassifiedAgentUserAgent {
  const ua = userAgent?.trim() ?? ''
  if (!ua) {
    return { kind: 'human', agent: 'none' }
  }

  for (const entry of AGENT_PATTERNS) {
    if (entry.pattern.test(ua)) {
      return { kind: 'agent', agent: entry.agent }
    }
  }

  for (const entry of BOT_PATTERNS) {
    if (entry.pattern.test(ua)) {
      return { kind: 'bot', agent: entry.agent }
    }
  }

  return { kind: 'human', agent: 'none' }
}

const AI_REFERRER_HOSTS = [
  'chatgpt.com',
  'chat.openai.com',
  'claude.ai',
  'perplexity.ai',
  'gemini.google.com',
  'copilot.microsoft.com',
  'cursor.com',
] as const

export function classifyAiReferrer(
  referrer: string | null | undefined,
): string {
  if (!referrer) return 'none'
  try {
    const host = new URL(referrer).hostname.replace(/^www\./, '').toLowerCase()
    const match = AI_REFERRER_HOSTS.find(
      (candidate) => host === candidate || host.endsWith(`.${candidate}`),
    )
    return match ?? 'none'
  } catch {
    return 'none'
  }
}
