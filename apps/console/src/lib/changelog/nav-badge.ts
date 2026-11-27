/**
 * Counted from the entry file names, without loading the entries: the badge
 * sits in the header of every page, and the entries are hundreds of KB.
 * Matches `changelogCount` in `./content`, which loads every entry.
 */
const changelogCount = Object.keys(
  import.meta.glob('/src/content/changelog/entries/*.markdoc', {
    query: '?raw',
    import: 'default',
  }),
).length

export const CHANGELOG_SEEN_COUNT_KEY = 'console.changelogSeenCount'

export const CHANGELOG_SEEN_UPDATED_EVENT = 'changelog-seen-updated'

export function getStoredChangelogSeenCount(): number | null {
  if (typeof window === 'undefined') return null

  const raw = localStorage.getItem(CHANGELOG_SEEN_COUNT_KEY)
  if (raw === null) return null

  const parsed = Number(raw)
  return Number.isFinite(parsed) ? parsed : null
}

export function markChangelogSeen(count: number = changelogCount): void {
  if (typeof window === 'undefined') return

  localStorage.setItem(CHANGELOG_SEEN_COUNT_KEY, String(count))
  window.dispatchEvent(new Event(CHANGELOG_SEEN_UPDATED_EVENT))
}

export function isChangelogNavBadgeVisible(pathname: string): boolean {
  if (typeof window === 'undefined') return false
  if (pathname.includes('/changelog')) return false

  const seenCount = getStoredChangelogSeenCount()
  if (seenCount === null) return true

  return seenCount < changelogCount
}
