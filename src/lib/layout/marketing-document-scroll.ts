export const MARKETING_DOCUMENT_SCROLL_CLASS = 'marketing-document-scroll'

export function isMarketingDocumentScroll(): boolean {
  return (
    typeof document !== 'undefined' &&
    document.documentElement.classList.contains(MARKETING_DOCUMENT_SCROLL_CLASS)
  )
}

export function setMarketingDocumentScroll(enabled: boolean) {
  if (typeof document === 'undefined') return
  document.documentElement.classList.toggle(
    MARKETING_DOCUMENT_SCROLL_CLASS,
    enabled,
  )
}

/** Offset of `el` from the top of the surface Plausible (and hash scroll) use. */
export function getPageSurfaceOffsetTop(el: HTMLElement): number {
  if (isMarketingDocumentScroll()) {
    return window.scrollY + el.getBoundingClientRect().top
  }

  const main = document.getElementById('main-content')
  if (!main) return window.scrollY + el.getBoundingClientRect().top

  const mainRect = main.getBoundingClientRect()
  const elRect = el.getBoundingClientRect()
  return main.scrollTop + elRect.top - mainRect.top
}

export function scrollPageSurfaceTo(
  top: number,
  behavior: ScrollBehavior = 'auto',
) {
  if (typeof window === 'undefined') return

  const nextTop = Math.max(0, top)
  if (isMarketingDocumentScroll()) {
    window.scrollTo({ top: nextTop, behavior })
    return
  }

  const main = document.getElementById('main-content')
  if (main) {
    main.scrollTo({ top: nextTop, behavior })
    return
  }

  window.scrollTo({ top: nextTop, behavior })
}

export function resetPageSurfaceScroll(behavior: ScrollBehavior = 'auto') {
  scrollPageSurfaceTo(0, behavior)
}
