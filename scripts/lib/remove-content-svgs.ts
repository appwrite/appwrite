import { readdir, rm, stat } from 'node:fs/promises'
import { join } from 'node:path'
import {
  CONTENT_IMAGE_SECTIONS,
  getPublicImagesDir,
  type ContentImageSection,
} from './content-image-paths.ts'

async function exists(path: string): Promise<boolean> {
  try {
    await stat(path)
    return true
  } catch {
    return false
  }
}

async function removeSvgFilesInDir(dir: string): Promise<number> {
  if (!(await exists(dir))) return 0

  let removed = 0
  const entries = await readdir(dir, { withFileTypes: true })

  for (const entry of entries) {
    const entryPath = join(dir, entry.name)
    if (entry.isDirectory()) {
      removed += await removeSvgFilesInDir(entryPath)
      continue
    }

    if (!entry.name.toLowerCase().endsWith('.svg')) continue
    await rm(entryPath, { force: true })
    removed++
  }

  return removed
}

/** Deletes any SVG files under imported content image trees (docs, blog, etc.). */
export async function removeImportedContentSvgs(
  sections: readonly ContentImageSection[] = CONTENT_IMAGE_SECTIONS,
): Promise<number> {
  let removed = 0
  for (const section of sections) {
    removed += await removeSvgFilesInDir(getPublicImagesDir(section))
  }
  return removed
}
