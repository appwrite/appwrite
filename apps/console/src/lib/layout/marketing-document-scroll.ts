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

/** Current scroll offset and viewport height of the active page surface. */
export function getPageSurfaceScrollState(): {
  scrollTop: number
  viewportHeight: number
} {
  if (typeof window === 'undefined') {
    return { scrollTop: 0, viewportHeight: 0 }
  }

  if (isMarketingDocumentScroll()) {
    return {
      scrollTop: window.scrollY || document.documentElement.scrollTop,
      viewportHeight: window.innerHeight,
    }
  }

  const main = document.getElementById('main-content')
  if (main) {
    return { scrollTop: main.scrollTop, viewportHeight: main.clientHeight }
  }

  return {
    scrollTop: window.scrollY || document.documentElement.scrollTop,
    viewportHeight: window.innerHeight,
  }
}

/**
 * Subscribe to the active page surface. Marketing pages scroll the window;
 * console pages scroll `#main-content`. Listen to both so a late switch to
 * document-scroll still delivers events.
 */
export function subscribePageSurfaceScroll(onScroll: () => void): () => void {
  if (typeof window === 'undefined') return () => {}

  window.addEventListener('scroll', onScroll, { passive: true })
  const main = document.getElementById('main-content')
  main?.addEventListener('scroll', onScroll, { passive: true })

  return () => {
    window.removeEventListener('scroll', onScroll)
    main?.removeEventListener('scroll', onScroll)
  }
}
