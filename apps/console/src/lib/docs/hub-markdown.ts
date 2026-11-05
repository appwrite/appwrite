/**
 * Markdown exports for docs hubs rendered by React routes (no markdoc source),
 * so `/docs/quick-starts.md` and `/docs/tutorials.md` resolve for agents.
 */

import {
  buildConnectMcpPrompt,
  DOCS_CONNECT_PROMPT_INTRO,
} from '@/lib/mcp-adoption'
import { AGENT_SETUP_ORIGIN } from '@/lib/seo/agent-setup'
import {
  DOCS_AGENT_CAPABILITIES,
  DOCS_FIRST_PROMPTS,
  DOCS_ONBOARDING_AGENTS,
} from './agent-onboarding'
import { QUICK_STARTS_HUB_CATEGORIES } from './quick-starts-hub'
import { getTutorialsHubCategories } from './tutorials-hub'

function mdUrl(href: string, origin: string): string {
  return href.startsWith('/docs') ? `${origin}${href}.md` : href
}

function buildQuickStartsMarkdown(origin: string): string {
  const prompt = buildConnectMcpPrompt({
    origin,
    intro: DOCS_CONNECT_PROMPT_INTRO,
  }).trimEnd()
  const lines: string[] = [
    '# Quick start',
    '',
    '> Set up Appwrite from your coding agent with one prompt, or follow a framework guide and write the code yourself.',
    '',
    '## Set up with your agent',
    '',
    `If you are a coding agent, fetch ${origin}/setup.md and follow it end to end. It connects the Appwrite MCP server, installs the latest CLI and the matching SDK, and links or creates a project. The user does not need a project or an API key first.`,
    '',
    'People paste this prompt into their agent:',
    '',
    '```',
    prompt,
    '```',
    '',
    'The user then approves two browser sign-ins: the Appwrite MCP server and `appwrite login`.',
    '',
    '### First prompts',
    '',
    ...DOCS_FIRST_PROMPTS.map((item) => `- **${item.title}**: ${item.prompt}`),
    '',
    '### What the agent sets up',
    '',
    ...DOCS_AGENT_CAPABILITIES.map(
      (item) =>
        `- [${item.title}](${mdUrl(item.href, origin)}): ${item.description}`,
    ),
    '',
    '## Set up by hand',
    '',
  ]

  for (const agent of DOCS_ONBOARDING_AGENTS) {
    lines.push(
      `### ${agent.name === 'Other' ? 'Other agents' : agent.name}`,
      '',
    )
    agent.manualSteps.forEach((step, index) => {
      lines.push(
        `${index + 1}. **${step.title}**${step.description ? `: ${step.description}` : ''}`,
      )
      if (step.link) lines.push(`   [${step.link.label}](${step.link.href})`)
      if (step.code) {
        lines.push('', `   \`\`\`${step.language ?? 'bash'}`)
        lines.push(...step.code.split('\n').map((codeLine) => `   ${codeLine}`))
        lines.push('   ```', '')
      }
    })
    lines.push('')
  }

  lines.push('## Write the code yourself', '')
  for (const category of QUICK_STARTS_HUB_CATEGORIES) {
    lines.push(`### ${category.title}`, '')
    lines.push(
      ...category.items.map(
        (item) => `- [${item.title}](${mdUrl(item.href, origin)})`,
      ),
      '',
    )
  }

  return `${lines
    .join('\n')
    .replace(/\n{3,}/g, '\n\n')
    .trimEnd()}\n`
}

function buildTutorialsMarkdown(origin: string): string {
  const lines: string[] = [
    '# Tutorials',
    '',
    '> Build complete apps step by step, grouped by framework and product.',
    '',
  ]
  for (const category of getTutorialsHubCategories()) {
    const tutorials = category.tutorials.filter((tutorial) => !tutorial.draft)
    if (tutorials.length === 0) continue
    lines.push(`## ${category.title}`, '')
    lines.push(
      ...tutorials.map(
        (tutorial) =>
          `- [${tutorial.title}](${mdUrl(tutorial.href, origin)})${tutorial.framework ? `: ${tutorial.framework}` : ''}`,
      ),
      '',
    )
  }
  return `${lines.join('\n').trimEnd()}\n`
}

const HUB_BUILDERS: Record<string, (origin: string) => string> = {
  'quick-starts': buildQuickStartsMarkdown,
  tutorials: buildTutorialsMarkdown,
}

export function getDocsHubMarkdown(
  slug: string,
  origin: string = AGENT_SETUP_ORIGIN,
): string | null {
  const builder = HUB_BUILDERS[slug]
  return builder ? builder(origin) : null
}
