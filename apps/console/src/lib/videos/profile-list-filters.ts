import type { Models } from '@appwrite.io/console'

/** Flat record for client-side FiltersPopover matching. */
export function profileToFilterRecord(
  profile: Models.VideoProfile,
): Record<string, unknown> {
  return { ...profile }
}

/** Sort keys match `videoProfilesFilterColumns` ids so the Filters popover can offer them. */
export const PROFILES_DEFAULT_SORT_BY = 'height'
export const PROFILES_DEFAULT_SORT_ORDER = 'desc' as const

/** Client-side sort for profiles (API returns an unsorted list). */
export function compareVideoProfiles(
  a: Models.VideoProfile,
  b: Models.VideoProfile,
  sortBy: string,
  sortOrder: 'asc' | 'desc',
): number {
  const direction = sortOrder === 'asc' ? 1 : -1
  switch (sortBy) {
    case '$id':
      return direction * a.$id.localeCompare(b.$id)
    case 'name':
      return direction * a.name.localeCompare(b.name)
    case 'width':
      return direction * (a.width - b.width || a.height - b.height)
    case 'videoBitRate':
      return direction * (a.videoBitRate - b.videoBitRate)
    case 'audioBitRate':
      return direction * (a.audioBitRate - b.audioBitRate)
    case '$createdAt':
      return direction * a.$createdAt.localeCompare(b.$createdAt)
    case '$updatedAt':
      return direction * a.$updatedAt.localeCompare(b.$updatedAt)
    case 'height':
    default:
      return direction * (a.height - b.height || a.width - b.width)
  }
}
