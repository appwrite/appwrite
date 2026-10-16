/**
 * Scroll the element with `data-card-id="<id>"` into view when the URL
 * hash starts with `#card-<id>`. Briefly highlight the matched card so
 * users can spot it after navigating from the Command Center.
 *
 * Mount this hook once on any page that hosts cards which should be
 * navigable from search. The hook is a no-op when there is no matching
 * hash, so it is safe to add globally.
 */

import { useEffect } from 'react'

const HIGHLIGHT_CLASSES = [
  'ring-2',
  'ring-foreground/30',
  'ring-offset-2',
  'ring-offset-background',
  'transition-all',
  'duration-700',
] as const

const HIGHLIGHT_DURATION_MS = 1600
const HASH_PREFIX = '#card-'

function findCard(id: string): HTMLElement | null {
  return document.querySelector<HTMLElement>(`[data-card-id="${CSS.escape(id)}"]`)
}

function highlight(el: HTMLElement) {
  el.scrollIntoView({ behavior: 'smooth', block: 'start' })
  el.classList.add(...HIGHLIGHT_CLASSES)
  window.setTimeout(() => {
    el.classList.remove(...HIGHLIGHT_CLASSES)
  }, HIGHLIGHT_DURATION_MS)
}

export function useScrollToCard(): void {
  useEffect(() => {
    if (typeof window === 'undefined') return

    const apply = () => {
      const hash = window.location.hash
      if (!hash.startsWith(HASH_PREFIX)) return
      const id = hash.slice(HASH_PREFIX.length)
      if (!id) return
      // The host content might still be mounting; retry a few times.
      let attempts = 0
      const tick = () => {
        const el = findCard(id)
        if (el) {
          highlight(el)
          return
        }
        if (attempts++ < 10) {
          window.setTimeout(tick, 80)
        }
      }
      tick()
    }

    apply()
    window.addEventListener('hashchange', apply)
    return () => window.removeEventListener('hashchange', apply)
  }, [])
}
