/**
 * Generates docs section sub-navigation from the Appwrite website repo layouts.
 * Run: bun run generate:docs-nav
 */
import { readdir, readFile, writeFile, mkdir } from 'node:fs/promises'
import { basename, dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import matter from 'gray-matter'

const __dirname = dirname(fileURLToPath(import.meta.url))
const VIBES_ROOT = join(__dirname, '..')
const WEBSITE_DOCS = join(VIBES_ROOT, '..', 'website', 'src', 'routes', 'docs')
const DOCS_CONTENT = join(VIBES_ROOT, 'src', 'content', 'docs')
const OUTPUT_FILE = join(VIBES_ROOT, 'src', 'lib', 'docs', 'navigation', 'sections.ts')

type NavLink = {
  label: string
  href: string
  new?: boolean
  collapsible?: boolean
  initiallyCollapsed?: boolean
}

type NavGroup = {
  label?: string
  items: NavLink[]
  collapsible?: boolean
  initiallyCollapsed?: boolean
}

type SectionConfig = {
  prefix: string
  parent: { href: string; label: string }
  navigation: NavGroup[]
}

function extractBalanced(
  source: string,
  openChar: '[' | '{',
  startIndex: number,
): string | null {
  const closeChar = openChar === '[' ? ']' : '}'
  let depth = 0
  let inString: '"' | "'" | '`' | null = null
  let escaped = false

  for (let i = startIndex; i < source.length; i++) {
    const char = source[i]

    if (inString) {
      if (escaped) {
        escaped = false
        continue
      }
      if (char === '\\') {
        escaped = true
        continue
      }
      if (char === inString) inString = null
      continue
    }

    if (char === '"' || char === "'" || char === '`') {
      inString = char
      continue
    }

    if (char === openChar) depth++
    if (char === closeChar) {
      depth--
      if (depth === 0) return source.slice(startIndex, i + 1)
    }
  }

  return null
}

function sanitizeObjectLiteral(raw: string): string {
  return raw
    .replace(/\$\{[^}]+\}/g, "'__DYNAMIC__'")
    .replace(/new:\s*isNewUntil\([^)]*\),?/g, '')
    .replace(/\bnew:\s*isNewUntil\([^)]*\)\s*,/g, '')
    .replace(/icon:\s*(?:'[^']*'|"[^"]*"),?/g, '')
    .replace(/openInNewTab:\s*true,?/g, '')
    .replace(/isParent:\s*true,?/g, '')
    .replace(/,(\s*[\]}])/g, '$1')
    .replace(/href:\s*'([^']*)\/'/g, (_, path: string) =>
      path === '/docs' ? "href: '/docs'" : `href: '${path}'`,
    )
}

function parseObjectLiteral<T>(raw: string): T {
  const sanitized = sanitizeObjectLiteral(raw)
  // eslint-disable-next-line no-new-func
  return new Function(`return (${sanitized})`)() as T
}

function normalizeHref(href: string): string {
  if (href === '__DYNAMIC__' || href.includes('${')) return href
  if (href.endsWith('/') && href !== '/docs/') {
    return href.replace(/\/+$/, '')
  }
  return href
}

function cleanNavigation(groups: NavGroup[]): NavGroup[] {
  return groups
    .map((group) => ({
      ...group,
      items: group.items
        .filter((item) => !item.href.includes('__DYNAMIC__'))
        .map((item) => ({
          label: item.label,
          href: normalizeHref(item.href),
          ...(item.new ? { new: true } : {}),
        })),
    }))
    .filter((group) => group.items.length > 0)
}

/**
 * Nav data sources, in priority order for a given directory:
 * 1. +layout.svelte / +layout@*.svelte (e.g. oauth-server uses a layout reset)
 * 2. Route-local Sidebar.svelte shared by sibling layouts (e.g. docs/apis)
 */
function isNavSourceFile(name: string): boolean {
  return /^\+layout(@.+)?\.svelte$/.test(name) || name === 'Sidebar.svelte'
}

function isLayoutFile(path: string): boolean {
  return basename(path).startsWith('+layout')
}

async function walkLayoutFiles(dir: string): Promise<string[]> {
  const files: string[] = []
  const entries = await readdir(dir, { withFileTypes: true })

  for (const entry of entries) {
    const fullPath = join(dir, entry.name)
    if (entry.isDirectory()) {
      if (entry.name.startsWith('[')) continue
      files.push(...(await walkLayoutFiles(fullPath)))
      continue
    }
    if (isNavSourceFile(entry.name)) files.push(fullPath)
  }

  return files
}

function parseLayoutFile(content: string): {
  parent: { href: string; label: string }
  navigation: NavGroup[]
} | null {
  if (!content.includes('const navigation: NavTree = [')) return null
  if (content.includes('const navigation: NavTree = $derived')) return null

  const parentMatch = content.match(/const parent:\s*NavParent\s*=\s*\{/)
  if (!parentMatch?.index) return null
  const parentRaw = extractBalanced(content, '{', parentMatch.index + parentMatch[0].length - 1)
  if (!parentRaw) return null

  const navMarker = 'const navigation: NavTree = '
  const navIndex = content.indexOf(navMarker)
  if (navIndex < 0) return null
  const arrayStart = content.indexOf('[', navIndex)
  if (arrayStart < 0) return null
  const navRaw = extractBalanced(content, '[', arrayStart)
  if (!navRaw) return null

  const parent = parseObjectLiteral<{ href: string; label: string }>(parentRaw)
  const navigation = parseObjectLiteral<NavGroup[]>(navRaw)

  return {
    parent: {
      href: normalizeHref(parent.href),
      label: parent.label,
    },
    navigation: cleanNavigation(navigation),
  }
}

async function parseQuickStartsNav(): Promise<SectionConfig | null> {
  const pagePath = join(WEBSITE_DOCS, 'quick-starts', '+page.svelte')
  let content: string
  try {
    content = await readFile(pagePath, 'utf8')
  } catch {
    return null
  }

  const marker = 'const quickStarts: QuickStarts = '
  const start = content.indexOf('[', content.indexOf(marker))
  const raw = start >= 0 ? extractBalanced(content, '[', start) : null
  if (!raw) return null

  type QuickStartCategory = {
    title: string
    quickStarts: Array<{ title: string; href: string }>
  }

  const categories = parseObjectLiteral<QuickStartCategory[]>(raw)

  return {
    prefix: 'quick-starts',
    parent: { href: '/docs', label: 'Quick start' },
    navigation: categories.map((category) => ({
      label: category.title,
      items: category.quickStarts.map((item) => ({
        label: item.title,
        href: `/docs/quick-starts/${item.href}`,
      })),
    })),
  }
}

async function buildTutorialSections(): Promise<SectionConfig[]> {
  const tutorialsDir = join(DOCS_CONTENT, 'tutorials')
  const entries = await readdir(tutorialsDir, { withFileTypes: true })
  const sections: SectionConfig[] = []

  for (const entry of entries) {
    if (!entry.isDirectory()) continue

    const tutorialSlug = `tutorials/${entry.name}`
    const tutorialDir = join(tutorialsDir, entry.name)
    const stepDirs = (await readdir(tutorialDir, { withFileTypes: true }))
      .filter((d) => d.isDirectory() && d.name.startsWith('step-'))
      .sort((a, b) => {
        const aNum = Number(a.name.replace('step-', ''))
        const bNum = Number(b.name.replace('step-', ''))
        return aNum - bNum
      })

    if (stepDirs.length === 0) continue

    const steps: NavLink[] = []
    let frameworkLabel = entry.name.replace(/-/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())

    for (const stepDir of stepDirs) {
      const filePath = join(tutorialDir, stepDir.name, 'index.markdoc')
      let title = stepDir.name
      let step = Number(stepDir.name.replace('step-', ''))

      try {
        const raw = await readFile(filePath, 'utf8')
        const { data } = matter(raw)
        if (typeof data.title === 'string') title = data.title
        if (typeof data.step === 'number') step = data.step
        if (step === 1 && typeof data.framework === 'string') {
          frameworkLabel = data.framework
        }
      } catch {
        // use defaults
      }

      steps.push({
        label: step === 1 ? 'Introduction' : title,
        href: `/docs/${tutorialSlug}/${stepDir.name}`,
      })
    }

    sections.push({
      prefix: tutorialSlug,
      parent: { href: '/docs/tutorials', label: frameworkLabel },
      navigation: [{ label: 'Steps', items: steps }],
    })
  }

  return sections.sort((a, b) => a.prefix.localeCompare(b.prefix))
}

function buildReferencesSection(): SectionConfig {
  return {
    prefix: 'references',
    parent: { href: '/docs', label: 'API references' },
    navigation: [],
  }
}

function toTsValue(value: unknown, indent = 0): string {
  const pad = '  '.repeat(indent)
  const padIn = '  '.repeat(indent + 1)

  if (Array.isArray(value)) {
    if (value.length === 0) return '[]'
    return `[\n${value.map((item) => `${padIn}${toTsValue(item, indent + 1)},`).join('\n')}\n${pad}]`
  }

  if (value && typeof value === 'object') {
    const entries = Object.entries(value as Record<string, unknown>).filter(
      ([, v]) => v !== undefined,
    )
    if (entries.length === 0) return '{}'
    return `{\n${entries
      .map(([key, v]) => `${padIn}${key}: ${toTsValue(v, indent + 1)},`)
      .join('\n')}\n${pad}}`
  }

  if (typeof value === 'string') return JSON.stringify(value)
  if (typeof value === 'boolean') return String(value)
  return String(value)
}

async function main() {
  // Layouts take priority over route-local Sidebar.svelte files for the same prefix.
  const layoutFiles = (await walkLayoutFiles(WEBSITE_DOCS)).sort(
    (a, b) => Number(isLayoutFile(b)) - Number(isLayoutFile(a)),
  )
  const sectionsByPrefix = new Map<string, SectionConfig>()

  for (const filePath of layoutFiles) {
    // Strip SvelteKit route groups like (overview): they don't appear in URLs,
    // so their layout applies to the parent path (e.g. products/databases).
    const rel = relative(WEBSITE_DOCS, dirname(filePath))
      .replace(/\\/g, '/')
      .split('/')
      .filter((segment) => !/^\(.+\)$/.test(segment))
      .join('/')
    if (rel === '.' || rel === '' || rel.includes('[')) continue
    if (sectionsByPrefix.has(rel)) continue

    const content = await readFile(filePath, 'utf8')
    const parsed = parseLayoutFile(content)
    if (!parsed) continue

    sectionsByPrefix.set(rel, {
      prefix: rel,
      parent: parsed.parent,
      navigation: parsed.navigation,
    })
  }

  const sections: SectionConfig[] = Array.from(sectionsByPrefix.values())

  const quickStarts = await parseQuickStartsNav()
  if (quickStarts) sections.push(quickStarts)

  sections.push(buildReferencesSection())
  sections.push(...(await buildTutorialSections()))

  sections.sort((a, b) => a.prefix.localeCompare(b.prefix))

  for (const section of sections) {
    for (const node of section.navigation) {
      if ('label' in node && node.label === 'Journeys') {
        node.label = 'Guides'
      }
    }
  }

  const fileContent = `import type { DocsNavParent, DocsNavTree } from '../types'

export type DocsSectionNavConfig = {
  prefix: string
  parent: DocsNavParent
  navigation: DocsNavTree
}

/** Generated from ../website layout files. Run: bun run generate:docs-nav */
export const DOCS_SECTION_NAVS: DocsSectionNavConfig[] = ${toTsValue(sections, 1)}
`

  await mkdir(dirname(OUTPUT_FILE), { recursive: true })
  await writeFile(OUTPUT_FILE, fileContent)
  console.log(`Wrote ${sections.length} section navigations to ${OUTPUT_FILE}`)
}

main().catch((error) => {
  console.error(error)
  process.exit(1)
})
