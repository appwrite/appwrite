import { collectSitemapSections } from './collect'
import { getSitemapSiteOrigin } from './config'
import { renderSitemapIndexXml, renderUrlsetXml } from './xml'

export type GeneratedSitemapFiles = {
  indexXml: string
  sectionFiles: Record<string, string>
  totalUrls: number
}

export function generateSitemapFiles(): GeneratedSitemapFiles {
  const origin = getSitemapSiteOrigin()
  const { sections, totalUrls } = collectSitemapSections()
  const buildDate = new Date().toISOString().slice(0, 10)

  const sectionFiles = Object.fromEntries(
    sections.map((section) => [
      section.id,
      renderUrlsetXml(origin, section.entries),
    ]),
  )

  return {
    indexXml: renderSitemapIndexXml(origin, sections, buildDate),
    sectionFiles,
    totalUrls,
  }
}

export { renderSitemapIndexXml, renderUrlsetXml } from './xml'
export { collectSitemapSections, getSitemapSection, registerSitemapSection } from './collect'
export { getSitemapSiteOrigin, DEFAULT_SITE_ORIGIN } from './config'
export { isExcludedFromSitemap } from './excluded-paths'
