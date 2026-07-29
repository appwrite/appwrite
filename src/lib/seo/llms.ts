/**
 * Shared llms.txt document builder following the llms.txt specification
 * (https://llmstxt.org). Used by both the runtime routes (Vite glob content)
 * and the build script (fs-based content), so the served and prebuilt files
 * always match.
 */

export const LLMS_TXT_PATH = '/llms.txt'
export const LLMS_FULL_TXT_PATH = '/llms-full.txt'

export const DEFAULT_LLMS_ORIGIN = 'https://appwrite.io'

export interface LlmsLink {
  title: string
  url: string
  description?: string
}

export interface LlmsSection {
  heading: string
  links: LlmsLink[]
}

/** Minimal meta shape shared by all content types listed in llms.txt. */
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

function formatLink(link: LlmsLink): string {
  const description = sanitizeDescription(link.description)
  const base = `- [${escapeLinkTitle(link.title)}](${link.url})`
  return description ? `${base}: ${description}` : base
}

export function buildDocsLlmsSection(
  pages: LlmsContentMeta[],
  origin: string,
): LlmsSection {
  return {
    heading: 'Docs',
    links: pages.map((page) => ({
      title: page.title,
      // The docs root has no .md variant; every other page does.
      url: page.slug ? `${origin}/docs/${page.slug}.md` : `${origin}/docs`,
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

export function buildAppwriteLlmsTxt(
  data: AppwriteLlmsTxtData,
  origin: string = DEFAULT_LLMS_ORIGIN,
): string {
  const sections: LlmsSection[] = [
    buildDocsLlmsSection(data.docs, origin),
    buildIntegrationsLlmsSection(data.integrations, origin),
    buildBlogLlmsSection(data.blog, origin),
    buildChangelogLlmsSection(data.changelog, origin),
    buildOptionalLlmsSection(origin),
  ].filter((section) => section.links.length > 0)

  const header = [
    '# Appwrite',
    '',
    '> Appwrite is an open-source backend platform with authentication, databases, storage, serverless functions, messaging, and web hosting, available as a managed cloud service or self-hosted.',
    '',
    `Every documentation page, blog post, changelog entry, and integration guide has a plain Markdown version: append \`.md\` to its URL. The complete documentation is also available as a single Markdown file at ${origin}${LLMS_FULL_TXT_PATH}.`,
  ].join('\n')

  const body = sections
    .map(
      (section) =>
        `## ${section.heading}\n\n${section.links.map(formatLink).join('\n')}`,
    )
    .join('\n\n')

  return `${header}\n\n${body}\n`
}
