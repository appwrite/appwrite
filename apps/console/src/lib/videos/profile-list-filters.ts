import type { Models } from '@appwrite.io/console'

/** Flat record for client-side FiltersPopover matching. */
export function profileToFilterRecord(
  profile: Models.VideoProfile,
): Record<string, unknown> {
  return { ...profile }
}

export const PROFILES_DEFAULT_SORT_BY = 'resolution'
export const PROFILES_DEFAULT_SORT_ORDER = 'desc' as const

export type ProfileSortColumn =
  | 'name'
  | 'resolution'
  | 'videoBitRate'
  | 'audioBitRate'
  | '$createdAt'

/** Client-side sort for profiles (API returns an unsorted list). */
export function compareVideoProfiles(
  a: Models.VideoProfile,
  b: Models.VideoProfile,
  sortBy: string,
  sortOrder: 'asc' | 'desc',
): number {
  const direction = sortOrder === 'asc' ? 1 : -1
  switch (sortBy) {
    case 'name':
      return direction * a.name.localeCompare(b.name)
    case 'videoBitRate':
      return direction * (a.videoBitRate - b.videoBitRate)
    case 'audioBitRate':
      return direction * (a.audioBitRate - b.audioBitRate)
    case '$createdAt':
      return direction * a.$createdAt.localeCompare(b.$createdAt)
    case 'resolution':
    default:
      return direction * (a.width * a.height - b.width * b.height)
  }
}
