import { cp, mkdir, readdir, rm, stat } from 'node:fs/promises'
import { dirname, join } from 'node:path'
import {
  CONTENT_IMAGE_SECTIONS,
  type ContentImageSection,
  getPublicImagesDir,
  getWebsiteImagesDir,
} from './content-image-paths.ts'

async function exists(path: string): Promise<boolean> {
  try {
    await stat(path)
    return true
  } catch {
    return false
  }
}

/** Never copy SVGs from the website content image trees; use public/icons/ instead. */
const SKIP_EXTENSIONS = ['.svg'] as const

function shouldSkipFile(filename: string): boolean {
  const ext = filename.slice(filename.lastIndexOf('.')).toLowerCase()
  return SKIP_EXTENSIONS.includes(ext as (typeof SKIP_EXTENSIONS)[number])
}

async function copyDirectoryFiltered(
  srcDir: string,
  destDir: string,
  section: ContentImageSection,
): Promise<void> {
  await mkdir(destDir, { recursive: true })
  const entries = await readdir(srcDir, { withFileTypes: true })

  for (const entry of entries) {
    const srcPath = join(srcDir, entry.name)
    const destPath = join(destDir, entry.name)

    if (entry.isDirectory()) {
      await copyDirectoryFiltered(srcPath, destPath, section)
      continue
    }

    if (shouldSkipFile(entry.name)) continue
    await cp(srcPath, destPath)
  }
}

export async function copyContentImagesFromWebsite(
  sections: readonly ContentImageSection[] = CONTENT_IMAGE_SECTIONS,
): Promise<{ section: ContentImageSection; copied: boolean }[]> {
  const results: { section: ContentImageSection; copied: boolean }[] = []

  for (const section of sections) {
    const src = getWebsiteImagesDir(section)
    const dest = getPublicImagesDir(section)

    if (!(await exists(src))) {
      console.warn(`Skipping missing image source: ${src}`)
      results.push({ section, copied: false })
      continue
    }

    await rm(dest, { recursive: true, force: true })
    await copyDirectoryFiltered(src, dest, section)
    results.push({ section, copied: true })
  }

  return results
}
