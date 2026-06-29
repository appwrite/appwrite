/**
 * Generates static docs exports for SEO and LLM crawlers (llms.txt, llms-full.txt).
 * Sitemaps are generated via scripts/generate-sitemap.ts.
 * Run manually when docs change: bun run generate:docs-exports
 */
import { mkdir, readFile, readdir, writeFile } from 'node:fs/promises'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import { DOCS_PAGES } from '../src/lib/docs/generated/manifest'

const __dirname = dirname(fileURLToPath(import.meta.url))
const VIBES_ROOT = join(__dirname, '..')
const PUBLIC_DIR = join(VIBES_ROOT, 'public')
const DOCS_DIR = join(VIBES_ROOT, 'src', 'content', 'docs')

const SITE_ORIGIN = process.env.VITE_SITE_ORIGIN ?? 'https://appwrite.io'

function stripFrontmatter(text: string): string {
  return text.replace(/^---[\s\S]*?---\s*/m, '')
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

function generateLlmsTxt(): string {
  const lines = DOCS_PAGES.map((page) => {
    const href = page.slug ? `${SITE_ORIGIN}/docs/${page.slug}` : `${SITE_ORIGIN}/docs`
    const title = page.title.replace(/\\/g, '\\\\').replace(/\[/g, '\\[').replace(/\]/g, '\\]')
    return `- [${title}](${href}): ${page.description}`
  })

  return `# Appwrite\n\n${lines.join('\n')}\n`
}

async function walkMarkdocFiles(dir: string): Promise<string[]> {
  const files: string[] = []
  const entries = await readdir(dir, { withFileTypes: true })

  for (const entry of entries) {
    const fullPath = join(dir, entry.name)
    if (entry.isDirectory()) {
      files.push(...(await walkMarkdocFiles(fullPath)))
    } else if (entry.name === 'index.markdoc') {
      files.push(fullPath)
    }
  }

  return files
}

function slugFromPath(filePath: string): string {
  const rel = relative(DOCS_DIR, dirname(filePath))
  return rel === '' ? '' : rel.replace(/\\/g, '/')
}

async function generateLlmsFullTxt(): Promise<string> {
  const files = await walkMarkdocFiles(DOCS_DIR)
  const contentBySlug = new Map<string, string>()

  for (const filePath of files) {
    contentBySlug.set(slugFromPath(filePath), await readFile(filePath, 'utf-8'))
  }

  const sections = DOCS_PAGES.map((page) => {
    const raw = contentBySlug.get(page.slug)
    if (!raw) return null

    const href = page.slug ? `${SITE_ORIGIN}/docs/${page.slug}` : `${SITE_ORIGIN}/docs`
    let body = stripFrontmatter(raw)
    body = stripFirstH1(body)
    body = demoteHeadings(body)

    return `## ${page.title}\n\nURL: ${href}\n\n${body.trim()}`
  }).filter(Boolean)

  return sections.join('\n\n---\n\n') + '\n'
}

async function main() {
  await mkdir(PUBLIC_DIR, { recursive: true })

  const llmsFullTxt = await generateLlmsFullTxt()

  await Promise.all([
    writeFile(join(PUBLIC_DIR, 'llms.txt'), generateLlmsTxt(), 'utf-8'),
    writeFile(join(PUBLIC_DIR, 'llms-full.txt'), llmsFullTxt, 'utf-8'),
  ])

  console.log('Generated llms.txt and llms-full.txt')
}

main().catch((err) => {
  console.error(err)
  process.exit(1)
})
