/**
 * Shared llms.txt / markdown-index builders following the llms.txt specification
 * (https://llmstxt.org), shaped for agent discovery (curated hub + section indexes).
 *
 * Used by both the runtime routes (Vite glob content) and the build script
 * (fs-based content), so the served and prebuilt files always match.
 */

import {
  MCP_SELF_HOSTED_DOCS_URL,
  MCP_SERVER_URL,
} from '@/lib/config/mcp'
import { getAllDocsSectionNavs } from '@/lib/docs/navigation/section-navs'
import { isDocsNavGroup } from '@/lib/docs/navigation'
import type { DocsNavLink, DocsNavTree, DocsPageMeta } from '@/lib/docs/types'
import {
  APPWRITE_AGENT_SKILLS,
  APPWRITE_AGENT_SKILLS_DISCOVERY_PATH,
  APPWRITE_AGENT_SKILLS_INSTALL,
  APPWRITE_AGENT_SKILLS_REPO,
  APPWRITE_AI_CATALOG_PATH,
  APPWRITE_MCP_DOCS_PATH,
  APPWRITE_MCP_SERVER_CARD_PATH,
} from './agent-discovery'

export const LLMS_TXT_PATH = '/llms.txt'
export const LLMS_FULL_TXT_PATH = '/llms-full.txt'
export const DOCS_LLMS_TXT_PATH = '/docs/llms.txt'
export const DOCS_MD_PATH = '/docs.md'
export const BLOG_MD_PATH = '/blog.md'
export const CHANGELOG_MD_PATH = '/changelog.md'
export const INTEGRATIONS_MD_PATH = '/integrations.md'

export const DEFAULT_LLMS_ORIGIN = 'https://appwrite.io'

/** Stable example post for the curated hub (must exist in content). */
export const LLMS_BLOG_EXAMPLE_SLUG = 'appwrite-realtime'
export const LLMS_CHANGELOG_EXAMPLE_SLUG = '2023-08-30'
export const LLMS_INTEGRATION_EXAMPLE_SLUG = 'ai-openai'

export interface LlmsLink {
  title: string
  url: string
  description?: string
}

export interface LlmsSection {
  heading: string
  intro?: string
  links: LlmsLink[]
}

/** Minimal meta shape shared by all content types listed in indexes. */
export interface LlmsContentMeta {
  slug: string
  title: string
  description?: string
}

function escapeLinkTitle(title: string): string {
  return title.replace(/\\/g, '\\\\').replace(/\[/g, '\\[').replace(/\]/g, '\\]')
}

function sanitizeDescription(description: string | undefined): string | undefined {
  const collapsed = description?.replace(/\s+/g, ' ').trim()
  return collapsed || undefined
}

function formatLink(link: LlmsLink, indent = ''): string {
  const description = sanitizeDescription(link.description)
  const base = `${indent}- [${escapeLinkTitle(link.title)}](${link.url})`
  return description ? `${base}: ${description}` : base
}

function formatSection(section: LlmsSection): string {
  const parts = [`## ${section.heading}`, '']
  if (section.intro) {
    parts.push(section.intro, '')
  }
  parts.push(section.links.map((link) => formatLink(link)).join('\n'))
  return parts.join('\n')
}

export function docsSlugToMarkdownUrl(
  slug: string,
  origin: string,
): string {
  return slug ? `${origin}/docs/${slug}.md` : `${origin}/docs`
}

export function docsHrefToMarkdownUrl(
  href: string,
  origin: string,
): string | null {
  if (!href.startsWith('/docs')) return null
  const slug = href.replace(/^\/docs\/?/, '').replace(/\/+$/, '')
  return docsSlugToMarkdownUrl(slug, origin)
}

export function buildDocsLlmsSection(
  pages: LlmsContentMeta[],
  origin: string,
): LlmsSection {
  return {
    heading: 'Docs',
    links: pages.map((page) => ({
      title: page.title,
      url: docsSlugToMarkdownUrl(page.slug, origin),
      description: page.description,
    })),
  }
}

export function buildIntegrationsLlmsSection(
  integrations: LlmsContentMeta[],
  origin: string,
): LlmsSection {
  return {
    heading: 'Integrations',
    links: integrations.map((integration) => ({
      title: integration.title,
      url: `${origin}/integrations/${integration.slug}.md`,
      description: integration.description,
    })),
  }
}

export function buildBlogLlmsSection(
  posts: LlmsContentMeta[],
  origin: string,
): LlmsSection {
  return {
    heading: 'Blog',
    links: posts.map((post) => ({
      title: post.title,
      url: `${origin}/blog/post/${post.slug}.md`,
      description: post.description,
    })),
  }
}

export function buildChangelogLlmsSection(
  entries: LlmsContentMeta[],
  origin: string,
): LlmsSection {
  return {
    heading: 'Changelog',
    links: entries.map((entry) => ({
      title: entry.title,
      url: `${origin}/changelog/entry/${entry.slug}.md`,
      description: entry.description,
    })),
  }
}

export function buildOptionalLlmsSection(origin: string): LlmsSection {
  return {
    heading: 'Optional',
    links: [
      {
        title: 'Pricing',
        url: `${origin}/pricing`,
        description: 'Appwrite Cloud plans and pricing.',
      },
      {
        title: 'Auth',
        url: `${origin}/products/auth`,
        description: 'Secure authentication with multiple sign-in methods.',
      },
      {
        title: 'Databases',
        url: `${origin}/products/databases`,
        description: 'Scalable and robust databases.',
      },
      {
        title: 'Storage',
        url: `${origin}/products/storage`,
        description: 'Securely store files with advanced compression and encryption.',
      },
      {
        title: 'Functions',
        url: `${origin}/products/functions`,
        description: 'Deploy and scale serverless functions.',
      },
      {
        title: 'Messaging',
        url: `${origin}/products/messaging`,
        description: 'Set up push notifications, emails, and SMS.',
      },
      {
        title: 'Sites',
        url: `${origin}/products/sites`,
        description: 'Deploy and host static and server-side rendered websites.',
      },
    ],
  }
}

export interface AppwriteLlmsTxtData {
  docs: LlmsContentMeta[]
  integrations: LlmsContentMeta[]
  blog: LlmsContentMeta[]
  changelog: LlmsContentMeta[]
}

function findMeta(
  items: LlmsContentMeta[],
  slug: string,
): LlmsContentMeta | undefined {
  return items.find((item) => item.slug === slug)
}

/**
 * Curated root `/llms.txt` hub for agents (CLI, MCP, skills first; indexes for
 * deep content). Inspired by Resend / Supabase / Netlify patterns.
 */
export function buildAppwriteLlmsTxt(
  data: AppwriteLlmsTxtData,
  origin: string = DEFAULT_LLMS_ORIGIN,
): string {
  const blogExample =
    findMeta(data.blog, LLMS_BLOG_EXAMPLE_SLUG) ?? data.blog[0]
  const changelogExample =
    findMeta(data.changelog, LLMS_CHANGELOG_EXAMPLE_SLUG) ?? data.changelog[0]
  const integrationExample =
    findMeta(data.integrations, LLMS_INTEGRATION_EXAMPLE_SLUG) ??
    data.integrations[0]

  const sections: LlmsSection[] = [
    {
      heading: 'MCP Server',
      intro:
        'Add Appwrite to Cursor, Claude, and other MCP clients so agents can call the Appwrite API and search the docs.',
      links: [
        {
          title: 'Remote MCP Server',
          url: MCP_SERVER_URL,
          description: 'Hosted streamable HTTP MCP endpoint.',
        },
        {
          title: 'Self-hosted MCP Server',
          url: MCP_SELF_HOSTED_DOCS_URL,
          description: 'Run the MCP server locally with uvx and a project API key.',
        },
        {
          title: 'MCP Server Card',
          url: `${origin}${APPWRITE_MCP_SERVER_CARD_PATH}`,
          description:
            'SEP-1649 MCP Server Card for pre-connection discovery (/.well-known/mcp/server-card.json).',
        },
        {
          title: 'AI Catalog',
          url: `${origin}${APPWRITE_AI_CATALOG_PATH}`,
          description:
            'Domain AI Catalog listing Appwrite MCP Server Cards (/.well-known/ai-catalog.json).',
        },
        {
          title: 'MCP documentation',
          url: `${origin}${APPWRITE_MCP_DOCS_PATH}.md`,
          description: 'Setup guides for the API and docs MCP servers.',
        },
      ],
    },
    {
      heading: 'Skills',
      intro: `Best practices for building with Appwrite in coding agents. Install with \`${APPWRITE_AGENT_SKILLS_INSTALL}\`.`,
      links: [
        {
          title: 'Skills Discovery',
          url: `${origin}${APPWRITE_AGENT_SKILLS_DISCOVERY_PATH}`,
          description: 'Machine-readable list of Appwrite agent skills.',
        },
        {
          title: 'Skills repository',
          url: APPWRITE_AGENT_SKILLS_REPO,
          description: 'Source for all Appwrite agent skills.',
        },
        ...APPWRITE_AGENT_SKILLS.map((skill) => ({
          title: skill.name,
          url: skill.url,
          description: skill.description,
        })),
        {
          title: 'Skills documentation',
          url: `${origin}/docs/tooling/ai/skills.md`,
          description: 'How to install and use Appwrite agent skills.',
        },
      ],
    },
    {
      heading: 'Command line',
      intro: 'Install and use the Appwrite CLI from the terminal.',
      links: [
        {
          title: 'CLI installation',
          url: `${origin}/docs/tooling/command-line/installation.md`,
          description: 'Install the Appwrite CLI with npm, Homebrew, or a standalone binary.',
        },
        {
          title: 'CLI commands',
          url: `${origin}/docs/tooling/command-line/commands.md`,
          description: 'Reference for Appwrite CLI commands.',
        },
        {
          title: 'Non-interactive mode',
          url: `${origin}/docs/tooling/command-line/non-interactive.md`,
          description: 'Use the CLI in CI/CD pipelines.',
        },
      ],
    },
    {
      heading: 'Documentation',
      intro:
        'Every documentation page has a plain Markdown version: append `.md` to its URL.',
      links: [
        {
          title: 'Docs index (Markdown)',
          url: `${origin}${DOCS_MD_PATH}`,
          description: 'Top-level documentation sections for agents.',
        },
        {
          title: 'Full documentation index',
          url: `${origin}${DOCS_LLMS_TXT_PATH}`,
          description: 'Nested link index of every documentation page.',
        },
        {
          title: 'Complete documentation (single file)',
          url: `${origin}${LLMS_FULL_TXT_PATH}`,
          description: 'All documentation pages concatenated as one Markdown file.',
        },
        {
          title: 'Docs as Markdown',
          url: `${origin}/docs/tooling/ai/docs-as-markdown.md`,
          description: 'How Appwrite exposes documentation for AI tools.',
        },
        {
          title: 'API references',
          url: `${origin}/docs/references.md`,
          description: 'REST, GraphQL, and SDK API references.',
        },
        {
          title: 'SDKs',
          url: `${origin}/docs/sdks.md`,
          description: 'Official Appwrite SDKs for every major platform.',
        },
      ],
    },
    {
      heading: 'Blog',
      intro:
        'Articles on product updates and developer guides. The index enumerates every post. Append `.md` to any post URL for the raw Markdown source.',
      links: [
        {
          title: 'Blog index (Markdown)',
          url: `${origin}${BLOG_MD_PATH}`,
          description: 'Flat list of every public blog post.',
        },
        ...(blogExample
          ? [
              {
                title: `Example: ${blogExample.title}`,
                url: `${origin}/blog/post/${blogExample.slug}.md`,
                description: blogExample.description,
              },
            ]
          : []),
      ],
    },
    {
      heading: 'Changelog',
      intro:
        'Product updates and releases. Append `.md` to any entry URL for the raw Markdown source.',
      links: [
        {
          title: 'Changelog index (Markdown)',
          url: `${origin}${CHANGELOG_MD_PATH}`,
          description: 'Flat list of every changelog entry.',
        },
        ...(changelogExample
          ? [
              {
                title: `Example: ${changelogExample.title}`,
                url: `${origin}/changelog/entry/${changelogExample.slug}.md`,
                description: changelogExample.description,
              },
            ]
          : []),
      ],
    },
    {
      heading: 'Integrations',
      intro:
        'Connect Appwrite to third-party tools. Append `.md` to any integration URL for the raw Markdown source.',
      links: [
        {
          title: 'Integrations index (Markdown)',
          url: `${origin}${INTEGRATIONS_MD_PATH}`,
          description: 'Flat list of every integration guide.',
        },
        ...(integrationExample
          ? [
              {
                title: `Example: ${integrationExample.title}`,
                url: `${origin}/integrations/${integrationExample.slug}.md`,
                description: integrationExample.description,
              },
            ]
          : []),
      ],
    },
    buildOptionalLlmsSection(origin),
  ]

  const header = [
    '# Appwrite',
    '',
    '> Appwrite is an open-source backend platform with authentication, databases, storage, serverless functions, messaging, and web hosting, available as a managed cloud service or self-hosted.',
    '',
    'For AI agents and automation, use the tools below.',
  ].join('\n')

  const body = sections.map(formatSection).join('\n\n')

  return `${header}\n\n${body}\n`
}

function pageMetaBySlug(
  pages: DocsPageMeta[] | LlmsContentMeta[],
): Map<string, LlmsContentMeta> {
  return new Map(
    pages.map((page) => [
      page.slug,
      {
        slug: page.slug,
        title: page.title,
        description: page.description,
      },
    ]),
  )
}

function linkFromDocsNav(
  item: DocsNavLink,
  origin: string,
  metaBySlug: Map<string, LlmsContentMeta>,
): LlmsLink | null {
  const url = docsHrefToMarkdownUrl(item.href, origin)
  if (!url) return null
  const slug = item.href.replace(/^\/docs\/?/, '').replace(/\/+$/, '')
  const meta = metaBySlug.get(slug)
  return {
    title: item.label,
    url,
    description: meta?.description,
  }
}

function formatDocsNavTree(
  navigation: DocsNavTree,
  origin: string,
  metaBySlug: Map<string, LlmsContentMeta>,
  seen: Set<string>,
): string[] {
  const lines: string[] = []

  for (const node of navigation) {
    if (isDocsNavGroup(node)) {
      if (node.label) {
        lines.push('', `### ${node.label}`, '')
      }
      for (const item of node.items) {
        const link = linkFromDocsNav(item, origin, metaBySlug)
        if (!link) continue
        const slug = item.href.replace(/^\/docs\/?/, '').replace(/\/+$/, '')
        if (slug) seen.add(slug)
        else seen.add('')
        lines.push(formatLink(link))
      }
      continue
    }

    const link = linkFromDocsNav(node, origin, metaBySlug)
    if (!link) continue
    const slug = node.href.replace(/^\/docs\/?/, '').replace(/\/+$/, '')
    if (slug) seen.add(slug)
    else seen.add('')
    lines.push(formatLink(link))
  }

  return lines
}

/**
 * Exhaustive nested documentation index for `/docs/llms.txt`, structured from
 * the docs section navigation (Vercel-style hierarchy).
 */
export function buildDocsLlmsTxt(
  pages: DocsPageMeta[] | LlmsContentMeta[],
  origin: string = DEFAULT_LLMS_ORIGIN,
): string {
  const metaBySlug = pageMetaBySlug(pages)
  const seen = new Set<string>()
  const parts: string[] = [
    '# Appwrite Docs',
    '',
    '> Appwrite documentation covering products, APIs, SDKs, tooling, self-hosting, and platform guides.',
    '',
    `Full documentation content (single file): ${origin}${LLMS_FULL_TXT_PATH}`,
    '',
    `Top-level docs index: ${origin}${DOCS_MD_PATH}`,
    '',
    'Every page below is also available as Markdown by appending `.md` to its URL.',
  ]

  for (const section of getAllDocsSectionNavs()) {
    const sectionLines = formatDocsNavTree(
      section.navigation,
      origin,
      metaBySlug,
      seen,
    )
    if (sectionLines.every((line) => line === '' || line.startsWith('###'))) {
      continue
    }

    parts.push('', `## ${section.parent.label}`, ...sectionLines)
  }

  const orphans = pages.filter((page) => !seen.has(page.slug))
  if (orphans.length > 0) {
    parts.push('', '## Other')
    for (const page of orphans) {
      parts.push(
        formatLink({
          title: page.title,
          url: docsSlugToMarkdownUrl(page.slug, origin),
          description: page.description,
        }),
      )
    }
  }

  return `${parts.join('\n').replace(/\n{3,}/g, '\n\n')}\n`
}

/** Curated top-level docs map for `/docs.md` (Supabase-style guide hubs). */
export function buildDocsMarkdownIndex(
  pages: DocsPageMeta[] | LlmsContentMeta[],
  origin: string = DEFAULT_LLMS_ORIGIN,
): string {
  const metaBySlug = pageMetaBySlug(pages)

  const hubs: Array<{ slug: string; title: string }> = [
    { slug: 'quick-starts', title: 'Quick starts' },
    { slug: 'tutorials', title: 'Tutorials' },
    { slug: 'sdks', title: 'SDKs' },
    { slug: 'products/auth', title: 'Auth' },
    { slug: 'products/databases', title: 'Databases' },
    { slug: 'products/storage', title: 'Storage' },
    { slug: 'products/functions', title: 'Functions' },
    { slug: 'products/messaging', title: 'Messaging' },
    { slug: 'products/sites', title: 'Sites' },
    { slug: 'products/domains', title: 'Domains' },
    { slug: 'apis', title: 'APIs' },
    { slug: 'tooling/ai', title: 'AI tooling' },
    { slug: 'tooling/command-line/installation', title: 'CLI' },
    { slug: 'advanced/platform', title: 'Platform' },
    { slug: 'advanced/migrations', title: 'Migrations' },
    { slug: 'advanced/security', title: 'Security' },
    { slug: 'advanced/self-hosting', title: 'Self-hosting' },
    { slug: 'advanced/billing', title: 'Billing' },
    { slug: 'references', title: 'API references' },
  ]

  const links = hubs.flatMap((hub) => {
    const meta = metaBySlug.get(hub.slug)
    if (!meta) return []
    return [
      {
        title: hub.title,
        url: docsSlugToMarkdownUrl(hub.slug, origin),
        description: meta.description,
      },
    ]
  })

  const header = [
    '# Appwrite Docs',
    '',
    '> Top-level documentation sections. For the full nested page index, see the documentation llms.txt. For the complete concatenated docs, see llms-full.txt.',
    '',
    `- [Full documentation index](${origin}${DOCS_LLMS_TXT_PATH}): Nested link index of every documentation page.`,
    `- [Complete documentation (single file)](${origin}${LLMS_FULL_TXT_PATH}): All documentation pages concatenated as one Markdown file.`,
    '',
    '## Guides',
    '',
    links.map((link) => formatLink(link)).join('\n'),
  ]

  return `${header.join('\n')}\n`
}

function buildContentIndexMarkdown(options: {
  title: string
  description: string
  pathHint: string
  section: LlmsSection
}): string {
  const { title, description, pathHint, section } = options
  return [
    `# ${title}`,
    '',
    `> ${description}`,
    '',
    `Append \`.md\` to any ${pathHint} URL, or open the links below, to receive the raw Markdown source.`,
    '',
    section.links.map((link) => formatLink(link)).join('\n'),
    '',
  ].join('\n')
}

export function buildBlogMarkdownIndex(
  posts: LlmsContentMeta[],
  origin: string = DEFAULT_LLMS_ORIGIN,
): string {
  return buildContentIndexMarkdown({
    title: 'Appwrite Blog',
    description:
      'Articles on Appwrite product updates, engineering, and developer guides.',
    pathHint: 'blog post',
    section: buildBlogLlmsSection(posts, origin),
  })
}

export function buildChangelogMarkdownIndex(
  entries: LlmsContentMeta[],
  origin: string = DEFAULT_LLMS_ORIGIN,
): string {
  return buildContentIndexMarkdown({
    title: 'Appwrite Changelog',
    description: 'Appwrite product updates and release notes.',
    pathHint: 'changelog entry',
    section: buildChangelogLlmsSection(entries, origin),
  })
}

export function buildIntegrationsMarkdownIndex(
  integrations: LlmsContentMeta[],
  origin: string = DEFAULT_LLMS_ORIGIN,
): string {
  return buildContentIndexMarkdown({
    title: 'Appwrite Integrations',
    description:
      'Guides for connecting Appwrite to third-party tools and platforms.',
    pathHint: 'integration',
    section: buildIntegrationsLlmsSection(integrations, origin),
  })
}
