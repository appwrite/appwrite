import { markdocToMarkdown } from '@/lib/seo/markdoc-to-markdown'
import { DOCS_PAGES } from './generated/manifest'
import { getDocsPage } from './content'
import { stripFrontmatter } from './frontmatter'

function demoteHeadings(text: string, levels = 2): string {
  return text.replace(/^(#{1,6})\s/gm, (_, hashes: string) => {
    const next = Math.min(hashes.length + levels, 6)
    return '#'.repeat(next) + ' '
  })
}

function stripFirstH1(text: string): string {
  return text.replace(/^#\s+.+\n+/, '')
}

export async function generateLlmsFullTxt(): Promise<string> {
  const base = 'https://appwrite.io'
  const sections = await Promise.all(
    DOCS_PAGES.map(async (page) => {
      const pageData = await getDocsPage(page.slug)
      if (!pageData) return null

      const href = page.slug ? `${base}/docs/${page.slug}` : `${base}/docs`
      let body = markdocToMarkdown(stripFrontmatter(pageData.rawContent))
      body = stripFirstH1(body)
      body = demoteHeadings(body)

      return `## ${page.title}\n\nURL: ${href}\n\n${body.trim()}`
    }),
  )

  return sections.filter(Boolean).join('\n\n---\n\n') + '\n'
}
