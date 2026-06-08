import { companyPageSections } from '@/lib/company/sections'

/** Matches company section `scroll-mt-28` offset (px). */
export const COMPANY_SECTION_SCROLL_OFFSET_PX = 112

export function scrollToCompanySection(sectionId: string) {
  if (typeof document === 'undefined') return

  const main = document.getElementById('main-content')
  const el = document.getElementById(sectionId)
  if (!main || !el) return

  const mainRect = main.getBoundingClientRect()
  const elRect = el.getBoundingClientRect()
  const targetTop =
    main.scrollTop +
    elRect.top -
    mainRect.top -
    COMPANY_SECTION_SCROLL_OFFSET_PX

  main.scrollTo({ top: Math.max(0, targetTop), behavior: 'smooth' })

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
