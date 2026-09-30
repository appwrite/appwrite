import { describe, expect, test } from 'bun:test'
import {
  classifyAgentUserAgent,
  classifyAiReferrer,
} from '@/lib/agent-user-agent'
import { getProductionRobotsTxt } from '@/lib/seo/robots'
import { buildAppwriteLlmsTxt, LLMS_BLOG_EXAMPLE_SLUG } from '@/lib/seo/llms'
import { buildForAgentsMarkdown } from '@/lib/for-agents/content'

describe('agent user-agent classification', () => {
  test('classifies ChatGPT retrieval and GPTBot', () => {
    expect(classifyAgentUserAgent('ChatGPT-User/1.0')).toEqual({
      kind: 'agent',
      agent: 'chatgpt-user',
    })
    expect(classifyAgentUserAgent('Mozilla/5.0 GPTBot/1.2')).toEqual({
      kind: 'agent',
      agent: 'gptbot',
    })
    expect(classifyAgentUserAgent('OAI-SearchBot/1.0')).toEqual({
      kind: 'agent',
      agent: 'oai-search',
    })
  })

  test('classifies coding agents and generic bots', () => {
    expect(classifyAgentUserAgent('Cursor/1.0')).toEqual({
      kind: 'agent',
      agent: 'cursor',
    })
    expect(classifyAgentUserAgent('Googlebot/2.1')).toEqual({
      kind: 'bot',
      agent: 'googlebot',
    })
    expect(classifyAgentUserAgent('Mozilla/5.0 Chrome/120')).toEqual({
      kind: 'human',
      agent: 'none',
    })
  })

  test('classifies AI referrers', () => {
    expect(classifyAiReferrer('https://chatgpt.com/c/abc')).toBe('chatgpt.com')
    expect(classifyAiReferrer('https://www.perplexity.ai/search')).toBe(
      'perplexity.ai',
    )
    expect(classifyAiReferrer('https://github.com')).toBe('none')
  })
})

describe('llms.txt decision card', () => {
  test('leads with when-to-use copy and install commands', () => {
    const txt = buildAppwriteLlmsTxt({
      docs: [],
      integrations: [],
      blog: [
        {
          slug: LLMS_BLOG_EXAMPLE_SLUG,
          title: 'Appwrite vs Supabase for AI app builders',
          description: 'Compare backends for agent-written apps.',
        },
      ],
      changelog: [],
    })

    expect(txt).toContain('Use Appwrite when a coding agent is writing an app')
    expect(txt).toContain('npx skills add appwrite/skills')
    expect(txt).toContain('https://mcp.appwrite.io')
    expect(txt).toContain('/for-agents')
    expect(txt).toContain('/docs/tooling/ai/agents/chatgpt.md')
    expect(txt).toContain(LLMS_BLOG_EXAMPLE_SLUG)
  })
})

describe('for-agents markdown', () => {
  test('includes the install card and comparison table', () => {
    const md = buildForAgentsMarkdown('https://appwrite.io')
    expect(md).toContain('npx skills add appwrite/skills')
    expect(md).toContain('https://mcp.appwrite.io')
    expect(md).toContain('| Auth, databases, storage, functions, and hosting from one agent | Appwrite |')
    expect(md).toContain('/docs/tooling/ai/agents/chatgpt.md')
  })
})

describe('robots.txt retrieval bots', () => {
  test('names retrieval crawlers explicitly', () => {
    const robots = getProductionRobotsTxt()
    expect(robots).toContain('User-agent: OAI-SearchBot')
    expect(robots).toContain('User-agent: ChatGPT-User')
    expect(robots).toContain('User-agent: Claude-SearchBot')
    expect(robots).toContain('User-agent: PerplexityBot')
    expect(robots).toContain('User-agent: Google-Extended')
    expect(robots).toContain('Sitemap: https://appwrite.io/sitemap.xml')
  })
})
