import { DOCS_PAGES } from './generated/manifest'
import { getDocsPage } from './content'
import { stripFrontmatter } from './frontmatter'

function toSummary(text: string, maxWords = 18): string {
  let out = stripFrontmatter(text)
  out = out.replace(/```[\s\S]*?```/g, '')
  out = out.replace(/`[^`]*`/g, '')
  out = out.replace(/!\[[^\]]*\]\([^)]*\)/g, '')
  out = out.replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
  out = out.replace(/\{%[\s\S]*?%\}/g, '')
  out = out.replace(/<[^>]+>/g, '')
  out = out.replace(/[\t ]+/g, ' ').trim()
  const words = out.split(/\s+/).filter(Boolean)
  if (words.length <= maxWords) return words.join(' ')
  return words.slice(0, maxWords).join(' ') + '…'
}

function demoteHeadings(text: string, levels = 2): string {
  return text.replace(/^(#{1,6})\s/gm, (_, hashes: string) => {
    const next = Math.min(hashes.length + levels, 6)
    return '#'.repeat(next) + ' '
  })
}

function stripFirstH1(text: string): string {
  return text.replace(/^#\s+.+\n+/, '')
}

export function generateLlmsTxt(): string {
  const base = 'https://appwrite.io'
  const lines = DOCS_PAGES.map((page) => {
    const href = page.slug ? `${base}/docs/${page.slug}` : `${base}/docs`
    const description = page.description || toSummary(page.title)
    const title = page.title.replace(/\\/g, '\\\\').replace(/\[/g, '\\[').replace(/\]/g, '\\]')
    return `- [${title}](${href}): ${description}`
  })

  return `# Appwrite\n\n${lines.join('\n')}\n`
}

export async function generateLlmsFullTxt(): Promise<string> {
  const base = 'https://appwrite.io'
  const sections = await Promise.all(
    DOCS_PAGES.map(async (page) => {
      const pageData = await getDocsPage(page.slug)
      if (!pageData) return null

      const href = page.slug ? `${base}/docs/${page.slug}` : `${base}/docs`
      let body = stripFrontmatter(pageData.rawContent)
      body = stripFirstH1(body)
      body = demoteHeadings(body)

      return `## ${page.title}\n\nURL: ${href}\n\n${body.trim()}`
    }),
  )

  return sections.filter(Boolean).join('\n\n---\n\n') + '\n'
}
