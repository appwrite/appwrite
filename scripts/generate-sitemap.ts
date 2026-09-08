/**
 * Generates sitemap.xml (index) and section sitemaps under public/sitemap/.
 * Run manually when content changes: bun run generate:sitemap
 */
import { mkdir, writeFile } from 'node:fs/promises'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import {
  NEWS_SITEMAP_SECTION_ID,
  generateSitemapFiles,
  registerSitemapSection,
} from '../src/lib/sitemap/index.ts'
import { fetchThreadsSitemapEntries } from '../src/lib/threads/sitemap.ts'

const __dirname = dirname(fileURLToPath(import.meta.url))
const VIBES_ROOT = join(__dirname, '..')
const PUBLIC_DIR = join(VIBES_ROOT, 'public')
const SITEMAP_DIR = join(PUBLIC_DIR, 'sitemap')

async function main() {
  const threadsEntries = await fetchThreadsSitemapEntries()
  registerSitemapSection({
    id: 'threads',
    getEntries: () => threadsEntries,
  })

  const { indexXml, sectionFiles, totalUrls } = generateSitemapFiles()

  await mkdir(SITEMAP_DIR, { recursive: true })

  await writeFile(join(PUBLIC_DIR, 'sitemap.xml'), indexXml, 'utf-8')

  await Promise.all(
    Object.entries(sectionFiles)
      .filter(([sectionId]) => sectionId !== NEWS_SITEMAP_SECTION_ID)
      .map(([sectionId, xml]) =>
        writeFile(join(SITEMAP_DIR, `${sectionId}.xml`), xml, 'utf-8'),
      ),
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
