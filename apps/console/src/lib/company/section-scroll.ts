import { companyPageSections } from '@/lib/company/sections'
import {
  getPageSurfaceOffsetTop,
  scrollPageSurfaceTo,
} from '@/lib/layout/marketing-document-scroll'

/** Matches company section `scroll-mt-28` offset (px). */
export const COMPANY_SECTION_SCROLL_OFFSET_PX = 112

export function scrollToCompanySection(sectionId: string) {
  if (typeof document === 'undefined') return

  const el = document.getElementById(sectionId)
  if (!el) return

  const targetTop =
    getPageSurfaceOffsetTop(el) - COMPANY_SECTION_SCROLL_OFFSET_PX
  scrollPageSurfaceTo(targetTop, 'smooth')

  if (typeof window !== 'undefined') {
    window.history.replaceState(null, '', `#${sectionId}`)
  }
}

export function scrollToCompanySectionFromHash() {
  if (typeof window === 'undefined') return

  const hash = window.location.hash.slice(1)
  if (!companyPageSections.some((section) => section.id === hash)) return

  scrollToCompanySection(hash)
}
