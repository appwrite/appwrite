import {
  existsSync,
  readFileSync,
  readdirSync,
  statSync,
  unlinkSync,
  writeFileSync,
} from 'node:fs'
import { join, relative, sep } from 'node:path'
import sharp from 'sharp'
import {
  CONTENT_IMAGE_SECTIONS,
  PUBLIC_IMAGES_ROOT,
  type ContentImageSection,
  VIBES_ROOT,
} from './content-image-paths.ts'

/** Raster extensions converted to AVIF (includes GIF for animated demos). */
export const CONVERTIBLE_RASTER_EXTENSIONS = new Set([
  '.png',
  '.jpg',
  '.jpeg',
  '.webp',
  '.gif',
])

const SKIP_DIR_NAMES = new Set(['node_modules', '.git'])

/**
 * Images that stay in their original format. Paths are relative to
 * `public/images/`. Re-encoding these animated demos to AVIF drops most of
 * their frames (the encoder falls back to 15 of several hundred), so they are
 * left as GIFs and their content references are never rewritten.
 */
export const CONVERSION_EXCLUDED_PATHS = new Set([
  'blog/rewriting-the-appwrite-cli-in-go/footprint.gif',
  'blog/rewriting-the-appwrite-cli-in-go/install.gif',
  'blog/rewriting-the-appwrite-cli-in-go/startup.gif',
])

function isExcluded(absPath: string): boolean {
  return CONVERSION_EXCLUDED_PATHS.has(
    toPosix(relative(PUBLIC_IMAGES_ROOT, absPath)),
  )
}

const TEXT_EXTENSIONS = new Set(['.markdoc', '.md', '.mdx'])

const CONTENT_REFERENCE_ROOTS = [
  join(VIBES_ROOT, 'src', 'content', 'docs'),
  join(VIBES_ROOT, 'src', 'content', 'docs-partials'),
  join(VIBES_ROOT, 'src', 'content', 'blog'),
  join(VIBES_ROOT, 'src', 'content', 'changelog'),
  join(VIBES_ROOT, 'src', 'content', 'integrations'),
]

/** Default cap for content rasters. */
const MAX_IMAGE_EDGE = 1280

/**
 * Screenshots are captured at a 1460x822 viewport with a device scale factor of
 * 2. Sources with exactly these pixel dimensions keep their full resolution;
 * every other raster is capped at MAX_IMAGE_EDGE as before.
 */
const HIGH_DPI_SCREENSHOT = { width: 2920, height: 1644 }

function resizeConfigFor(meta: sharp.Metadata): sharp.ResizeOptions {
  const isHighDpiScreenshot =
    meta.width === HIGH_DPI_SCREENSHOT.width &&
    meta.height === HIGH_DPI_SCREENSHOT.height
  const edge = isHighDpiScreenshot ? HIGH_DPI_SCREENSHOT.width : MAX_IMAGE_EDGE
  return {
    width: edge,
    height: edge,
    fit: sharp.fit.inside,
    withoutEnlargement: true,
  }
}

const avifOptions: sharp.AvifOptions = {
  quality: 82,
  effort: 4,
  chromaSubsampling: '4:4:4',
}

export type ConvertImagesToAvifResult = {
  converted: number
  skippedExisting: number
  referencesUpdated: number
}

function toPosix(path: string): string {
  return path.split(sep).join('/')
}

function walkRasterFiles(root: string): string[] {
  const files: string[] = []

  function walk(dir: string) {
    let entries: string[]
    try {
      entries = readdirSync(dir)
    } catch {
      return
    }

    for (const name of entries) {
      if (SKIP_DIR_NAMES.has(name)) continue
      const full = join(dir, name)
      let st: ReturnType<typeof statSync>
      try {
        st = statSync(full)
      } catch {
        continue
      }

      if (st.isDirectory()) {
        walk(full)
        continue
      }

      const ext = full.slice(full.lastIndexOf('.')).toLowerCase()
      if (CONVERTIBLE_RASTER_EXTENSIONS.has(ext)) {
        files.push(full)
      }
    }
  }

  walk(root)
  return files
}

function registerReplacements(
  map: Map<string, string>,
  oldAbs: string,
  newAbs: string,
) {
  const rel = toPosix(relative(PUBLIC_IMAGES_ROOT, oldAbs))
  const relNew = toPosix(relative(PUBLIC_IMAGES_ROOT, newAbs))
  const pubOld = `/images/${rel}`
  const pubNew = `/images/${relNew}`
  map.set(pubOld, pubNew)
}

function splitJoinAll(content: string, from: string, to: string): string {
  return content.split(from).join(to)
}

function updateContentReferences(replacements: Map<string, string>): number {
  const sortedPairs = [...replacements.entries()].sort(
    (a, b) => b[0].length - a[0].length,
  )

  const filesToScan: string[] = []

  function walkText(dir: string) {
    if (!existsSync(dir)) return
    for (const name of readdirSync(dir)) {
      const full = join(dir, name)
      const st = statSync(full)
      if (st.isDirectory()) {
        walkText(full)
        continue
      }
      const ext = name.includes('.')
        ? name.slice(name.lastIndexOf('.')).toLowerCase()
        : ''
      if (TEXT_EXTENSIONS.has(ext)) filesToScan.push(full)
    }
  }

  for (const root of CONTENT_REFERENCE_ROOTS) {
    walkText(root)
  }

  let touched = 0
  for (const file of filesToScan) {
    let content = readFileSync(file, 'utf-8')
    const original = content
    for (const [from, to] of sortedPairs) {
      if (from === to || !content.includes(from)) continue
      content = splitJoinAll(content, from, to)
    }
    if (content !== original) {
      writeFileSync(file, content, 'utf-8')
      touched++
    }
  }

  return touched
}

async function convertOne(oldAbs: string, newAbs: string): Promise<void> {
  const inputOptions: sharp.SharpOptions = {
    animated: true,
    limitInputPixels: false,
  }

  const meta = await sharp(oldAbs, inputOptions).metadata()
  const isAnimated = (meta.pages ?? 1) > 1

  if (isAnimated) {
    await convertAnimatedToAvif(oldAbs, newAbs, meta)
    unlinkSync(oldAbs)
    return
  }

  let pipeline = sharp(oldAbs, inputOptions)
  if (meta.width && meta.height) {
    pipeline = pipeline.resize(resizeConfigFor(meta))
  }

  await pipeline.avif(avifOptions).toFile(newAbs)
  unlinkSync(oldAbs)
}

async function convertAnimatedToAvif(
  oldAbs: string,
  newAbs: string,
  meta: sharp.Metadata,
): Promise<void> {
  const pageWidth = meta.width ?? 1280
  const pageHeight = meta.pageHeight ?? meta.height ?? 720
  const totalPages = meta.pages ?? 1

  const pageLimits = [
    totalPages,
    120,
    90,
    60,
    45,
    30,
    15,
  ].filter((limit, index, limits) => limit <= totalPages && limits.indexOf(limit) === index)

  const maxEdges = [1280, 960, 720, 480]

  let lastError: unknown
  for (const maxEdge of maxEdges) {
    const scale = Math.min(1, maxEdge / Math.max(pageWidth, pageHeight))
    const targetWidth = Math.round(pageWidth * scale)
    const targetHeight = Math.round(pageHeight * scale)

    for (const pages of pageLimits) {
      try {
        await sharp(oldAbs, {
          animated: true,
          limitInputPixels: false,
          pages,
        })
          .resize({
            width: targetWidth,
            height: targetHeight,
            fit: 'inside',
            withoutEnlargement: true,
          })
          .avif(avifOptions)
          .toFile(newAbs)

        if (pages < totalPages || maxEdge < 1280) {
          console.warn(
            `  Used ${pages}/${totalPages} frames at ${targetWidth}x${targetHeight} for AVIF output`,
          )
        }
        return
      } catch (error) {
        lastError = error
      }
    }
  }

  throw lastError
}

export async function convertImagesToAvif(options?: {
  sections?: readonly ContentImageSection[]
}): Promise<ConvertImagesToAvifResult> {
  const sections = options?.sections ?? CONTENT_IMAGE_SECTIONS
  const replacements = new Map<string, string>()
  let converted = 0
  let skippedExisting = 0

  for (const section of sections) {
    const sectionRoot = join(PUBLIC_IMAGES_ROOT, section)
    if (!existsSync(sectionRoot)) continue

    for (const oldAbs of walkRasterFiles(sectionRoot)) {
      if (isExcluded(oldAbs)) continue

      const ext = oldAbs.slice(oldAbs.lastIndexOf('.')).toLowerCase()
      const newAbs = `${oldAbs.slice(0, -ext.length)}.avif`

      if (ext === '.avif') {
        skippedExisting++
        continue
      }

      if (existsSync(newAbs)) {
        registerReplacements(replacements, oldAbs, newAbs)
        unlinkSync(oldAbs)
        skippedExisting++
        continue
      }

      const rel = toPosix(relative(VIBES_ROOT, oldAbs))
      console.log(`Converting ${rel}`)
      await convertOne(oldAbs, newAbs)
      registerReplacements(replacements, oldAbs, newAbs)
      converted++
    }
  }

  const referencesUpdated =
    replacements.size > 0 ? updateContentReferences(replacements) : 0

  return { converted, skippedExisting, referencesUpdated }
}
