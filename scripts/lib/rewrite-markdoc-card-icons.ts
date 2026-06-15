import { readdir, readFile, stat, writeFile } from 'node:fs/promises'
import { join } from 'node:path'

const DOCS_SVG_IMAGE_ATTR = /\simage="(\/images\/docs\/[^"]+\.svg)"/gi

/** Basename from a legacy docs logo SVG path (e.g. dark/claude.svg → claude). */
export function docsSvgPathToIconName(path: string): string | null {
  const filename = path.split('/').pop()?.replace(/\.svg$/i, '')
  return filename || null
}

/** Replace legacy card `image="/images/docs/.../*.svg"` with `icon="…"` using /icons/ lookup. */
export function rewriteDocsSvgCardImages(content: string): string {
  return content.replace(DOCS_SVG_IMAGE_ATTR, (_match, svgPath: string) => {
    const iconName = docsSvgPathToIconName(svgPath)
    if (!iconName) return ''
    return ` icon="${iconName}"`
  })
}

async function exists(path: string): Promise<boolean> {
  try {
    await stat(path)
    return true
  } catch {
    return false
  }
}

async function rewriteMarkdocFilesInDir(dir: string): Promise<number> {
  if (!(await exists(dir))) return 0

  let updated = 0
  const entries = await readdir(dir, { withFileTypes: true })

  for (const entry of entries) {
    const entryPath = join(dir, entry.name)
    if (entry.isDirectory()) {
      updated += await rewriteMarkdocFilesInDir(entryPath)
      continue
    }

    if (!entry.name.endsWith('.markdoc') && !entry.name.endsWith('.md')) continue

    const original = await readFile(entryPath, 'utf8')
    const next = rewriteDocsSvgCardImages(original)
    if (next === original) continue

    await writeFile(entryPath, next, 'utf8')
    updated++
  }

  return updated
}

/** Rewrites imported docs/partials so cards use `icon` instead of website SVG image paths. */
export async function rewriteImportedDocsCardIcons(
  docsRoot: string,
  partialsRoot: string,
): Promise<number> {
  const [docsCount, partialsCount] = await Promise.all([
    rewriteMarkdocFilesInDir(docsRoot),
    rewriteMarkdocFilesInDir(partialsRoot),
  ])
  return docsCount + partialsCount
}
