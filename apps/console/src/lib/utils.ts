import { clsx, type ClassValue } from 'clsx'
import { twMerge } from 'tailwind-merge'

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

const ELLIPSIS = '...'

/**
 * Truncate a string in the middle when it exceeds maxLength.
 * e.g. truncateMiddle('Very Long Organization Name', 18) → 'Very Lo...on Name'
 */
export function truncateMiddle(str: string, maxLength: number): string {
  if (typeof str !== 'string' || str.length <= maxLength) return str
  const take = maxLength - ELLIPSIS.length
  const half = Math.floor(take / 2)
  const first = str.slice(0, half)
  const last = str.slice(-(take - half))
  return `${first}${ELLIPSIS}${last}`
}

/**
 * Truncate a string at the start when it exceeds maxLength, keeping the end visible.
 * e.g. truncateStart('/tablesdb/{databaseId}/tables/{tableId}/rows', 20) → '.../tables/{tableId}/rows'
 */
export function truncateStart(str: string, maxLength: number): string {
  if (typeof str !== 'string' || str.length <= maxLength) return str
  const take = maxLength - ELLIPSIS.length
  return `${ELLIPSIS}${str.slice(-take)}`
}

/**
 * Reset document and `.root-container` scroll. The app shell is `position: fixed`;
 * native hash navigation can scroll this container and push the header off-screen.
 */
export function resetConsoleShellDocumentScroll() {
  if (typeof window === 'undefined' || typeof document === 'undefined') return

  window.scrollTo(0, 0)
  document.documentElement.scrollTop = 0
  document.body.scrollTop = 0

  const root = document.querySelector('.root-container')
  if (root instanceof HTMLElement) {
    root.scrollTop = 0
  }
}

/**
 * Scroll the console shell main region to top. List views use `#main-content`
 * (overflow-y-auto); window/document scroll is often zero, so pagination must
 * target this element.
 */
/** Nearest ancestor that scrolls vertically (e.g. `#main-content`). */
export function findScrollParent(el: HTMLElement | null): HTMLElement | null {
  if (!el) return null
  let parent = el.parentElement
  while (parent) {
    const { overflowY } = getComputedStyle(parent)
    if (
      /(auto|scroll|overlay)/.test(overflowY) &&
      parent.scrollHeight > parent.clientHeight
    ) {
      return parent
    }
    parent = parent.parentElement
  }
  return null
}

export function scrollConsoleMainToTop() {
  setTimeout(() => {
    if (typeof document === 'undefined') return
    const main = document.getElementById('main-content')
    if (main) {
      main.scrollTo({ top: 0, behavior: 'smooth' })
      return
    }
    if (document.documentElement.scrollTop > 0) {
      document.documentElement.scrollTo({ top: 0, behavior: 'smooth' })
    }
    if (typeof window !== 'undefined' && window.scrollY > 0) {
      window.scrollTo({ top: 0, behavior: 'smooth' })
    }
  }, 150)
}
