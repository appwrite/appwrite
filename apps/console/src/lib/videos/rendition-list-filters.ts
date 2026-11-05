import type { Models, VideoOutput, VideoRenditionStatus } from '@appwrite.io/console'
import type { CompactFilterKey } from '@/lib/table-filters'

/** Flat record for client-side FiltersPopover matching. */
export function renditionToFilterRecord(
  rendition: Models.VideoRendition,
  profileName?: string,
): Record<string, unknown> {
  return {
    ...rendition,
    profileName: profileName ?? '',
  }
}

/**
 * When the URL filter map is a single equality on `output` or `status`, the API
 * can narrow the list. Any other filter shape falls back to a full list fetch.
 */
export function apiRenditionListParams(
  filterMap: Map<CompactFilterKey, string>,
): { output?: VideoOutput; status?: VideoRenditionStatus } | undefined {
  if (filterMap.size === 0) return undefined

  let output: VideoOutput | undefined
  let status: VideoRenditionStatus | undefined
  let otherFilters = 0

  for (const key of filterMap.keys()) {
    if (key.o !== 'equal' || key.v == null || key.v === '') {
      otherFilters += 1
      continue
    }
    if (key.c === 'output') {
      output = String(key.v) as VideoOutput
    } else if (key.c === 'status') {
      status = String(key.v) as VideoRenditionStatus
    } else {
      otherFilters += 1
    }
  }

  if (otherFilters > 0) return undefined
  if (!output && !status) return undefined
  return { output, status }
}

export const RENDITIONS_DEFAULT_SORT_BY = 'output'
export const RENDITIONS_DEFAULT_SORT_ORDER = 'asc' as const

/** Spreadsheet column ids used in the renditions sort URL param. */
export type RenditionSortColumn =
  | 'rendition'
  | 'profile'
  | 'output'
  | 'resolution'
  | 'bitrate'
  | 'segmentLength'
  | 'status'
  | 'encodingTime'
  | 'created'

function encodingDurationMs(rendition: Models.VideoRendition): number {
  if (!rendition.startedAt) return -1
  const endIso = rendition.endedAt ?? rendition.$updatedAt
  return new Date(endIso).getTime() - new Date(rendition.startedAt).getTime()
}

/** Client-side sort (`listRenditions` has no sort query params). */
export function compareVideoRenditions(
  a: Models.VideoRendition,
  b: Models.VideoRendition,
  sortBy: string,
  sortOrder: 'asc' | 'desc',
  profileNames?: Map<string, string>,
): number {
  const direction = sortOrder === 'asc' ? 1 : -1

  let cmp = 0
  switch (sortBy) {
    case 'rendition':
      cmp = a.name.localeCompare(b.name)
      break
    case 'profile': {
      const aName = profileNames?.get(a.profileId) ?? a.profileId
      const bName = profileNames?.get(b.profileId) ?? b.profileId
      cmp = aName.localeCompare(bName)
      break
    }
    case 'output':
      cmp = a.output.localeCompare(b.output)
      break
    case 'resolution':
      cmp = a.width * a.height - b.width * b.height
      break
    case 'bitrate':
      cmp =
        a.videoBitRate - b.videoBitRate ||
        a.audioBitRate - b.audioBitRate
      break
    case 'segmentLength':
      cmp = Number(a.targetDuration) - Number(b.targetDuration)
      break
    case 'status':
      cmp = a.status.localeCompare(b.status)
      break
    case 'encodingTime':
      cmp = encodingDurationMs(a) - encodingDurationMs(b)
      break
    case 'created':
      cmp = a.$createdAt.localeCompare(b.$createdAt)
      break
    default:
      cmp = a.output.localeCompare(b.output)
  }

  if (cmp !== 0) return direction * cmp
  return (
    a.output.localeCompare(b.output) ||
    b.width * b.height - a.width * a.height
  )
}
