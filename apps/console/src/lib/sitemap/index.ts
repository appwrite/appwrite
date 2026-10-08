import { collectSitemapSections } from './collect'
import { getSitemapSiteOrigin } from './config'
import { NEWS_SITEMAP_SECTION_ID } from './news'
import { renderSitemapIndexXml, renderUrlsetXml } from './xml'

export type GeneratedSitemapFiles = {
  indexXml: string
  sectionFiles: Record<string, string>
  totalUrls: number
}

export async function generateSitemapFiles(): Promise<GeneratedSitemapFiles> {
  const origin = getSitemapSiteOrigin()
  const { sections, totalUrls } = await collectSitemapSections()
  const buildDate = new Date().toISOString().slice(0, 10)

  const sectionFiles = Object.fromEntries(
    sections.map((section) => [
      section.id,
      renderUrlsetXml(origin, section.entries),
    ]),
  )

  const indexSections = [
    ...sections,
    { id: NEWS_SITEMAP_SECTION_ID, entries: [] },
  ]

  return {
    indexXml: renderSitemapIndexXml(origin, indexSections, buildDate),
    sectionFiles,
    totalUrls,
  }
}

export { renderSitemapIndexXml, renderUrlsetXml } from './xml'
export {
  collectSitemapSections,
  getSitemapSection,
  isSitemapFileSectionId,
  registerSitemapSection,
  SITEMAP_FILE_SECTION_IDS,
} from './collect'
export { getSitemapSiteOrigin, DEFAULT_SITE_ORIGIN } from './config'
export { isExcludedFromSitemap } from './excluded-paths'
export {
  NEWS_SITEMAP_PATH,
  NEWS_SITEMAP_SECTION_ID,
  buildNewsSitemapXml,
} from './news'
