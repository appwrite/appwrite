/**
 * Generates sitemap.xml (index) and section sitemaps under public/sitemap/.
 * Run manually when content changes: bun run generate:sitemap
 *
 * This script runs after `vite build` (which copies public/ into dist/client),
 * so write into dist/client too; otherwise a clean build never contains the
 * gitignored sitemap files and production 404s /sitemap.xml.
 */
import { existsSync } from 'node:fs'
import { mkdir, writeFile } from 'node:fs/promises'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import {
  NEWS_SITEMAP_SECTION_ID,
  generateSitemapFiles,
} from '../src/lib/sitemap/index.ts'

const __dirname = dirname(fileURLToPath(import.meta.url))
const VIBES_ROOT = join(__dirname, '..')
const PUBLIC_DIR = join(VIBES_ROOT, 'public')

async function writeSitemapOutputs(
  outputDir: string,
  indexXml: string,
  sectionFiles: Record<string, string>,
) {
  const sitemapDir = join(outputDir, 'sitemap')
  await mkdir(sitemapDir, { recursive: true })

  await writeFile(join(outputDir, 'sitemap.xml'), indexXml, 'utf-8')

  await Promise.all(
    Object.entries(sectionFiles)
      .filter(([sectionId]) => sectionId !== NEWS_SITEMAP_SECTION_ID)
      .map(([sectionId, xml]) =>
        writeFile(join(sitemapDir, `${sectionId}.xml`), xml, 'utf-8'),
      ),
  )
}

async function main() {
  const { indexXml, sectionFiles, totalUrls } = await generateSitemapFiles()

  const outputDirs = [PUBLIC_DIR]
  const clientDir = join(VIBES_ROOT, 'dist', 'client')
  if (existsSync(clientDir)) outputDirs.push(clientDir)

  await Promise.all(
    outputDirs.map((dir) => writeSitemapOutputs(dir, indexXml, sectionFiles)),
  )

  const sectionSummary = Object.entries(sectionFiles)
    .map(([id, xml]) => {
      const urlCount = (xml.match(/<url>/g) ?? []).length
      return `${id}=${urlCount}`
    })
    .join(', ')

  console.log(
    `Generated sitemap index and ${Object.keys(sectionFiles).length} section files (${totalUrls} URLs: ${sectionSummary})`,
  )
}

main().catch((err) => {
  console.error(err)
  process.exit(1)
})
