import type { ChangelogEntryMeta } from './types'

/** Newest first; same-day entries by slug suffix, so `-2` precedes `-1`. */
export function compareChangelogEntries(
  a: Pick<ChangelogEntryMeta, 'date' | 'slug'>,
  b: Pick<ChangelogEntryMeta, 'date' | 'slug'>,
): number {
  return (
    new Date(b.date).getTime() - new Date(a.date).getTime() ||
    b.slug.localeCompare(a.slug, 'en', { numeric: true })
  )
}
