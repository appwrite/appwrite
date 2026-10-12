import { COVER_LUCIDE_ICON_PREFIX, COVER_LUCIDE_POPULAR_ICONS } from '@/lib/cover-generator/lucide-icons'

export const COVER_LUCIDE_ICON_NAME_PATTERN = /^[a-z0-9]+(?:-[a-z0-9]+)*$/

export function isCoverLucideIconValue(value: string | undefined): value is string {
  return value?.startsWith(COVER_LUCIDE_ICON_PREFIX) ?? false
}

export function parseCoverLucideIconName(value: string | undefined): string | null {
  if (!isCoverLucideIconValue(value)) return null

  const name = value.slice(COVER_LUCIDE_ICON_PREFIX.length).trim()
  if (!name || !COVER_LUCIDE_ICON_NAME_PATTERN.test(name)) return null

  return name
}

export function formatCoverLucideIconValue(name: string): string {
  return `${COVER_LUCIDE_ICON_PREFIX}${name}`
}

export function formatCoverLucideIconLabel(name: string): string {
  return name
    .split('-')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ')
}

export const COVER_LUCIDE_SEARCH_LIMIT = 100

export function searchCoverLucideIcons(
  query: string,
  iconNames: readonly string[] | null,
): string[] {
  const normalized = query.trim().toLowerCase()
  if (!normalized) {
    return [...COVER_LUCIDE_POPULAR_ICONS]
  }

  const library = iconNames ?? [...COVER_LUCIDE_POPULAR_ICONS]
  return library.filter((name) => name.includes(normalized)).slice(0, COVER_LUCIDE_SEARCH_LIMIT)
}

export function getCoverLucideIconSearchMeta(
  query: string,
  iconNames: readonly string[] | null,
): {
  isLimited: boolean
  totalMatches: number
  isLibraryLoaded: boolean
} {
  const normalized = query.trim().toLowerCase()
  if (!normalized) {
    return {
      isLimited: false,
      totalMatches: COVER_LUCIDE_POPULAR_ICONS.length,
      isLibraryLoaded: iconNames != null,
    }
  }

  if (!iconNames) {
    const popularMatches = COVER_LUCIDE_POPULAR_ICONS.filter((name) =>
      name.includes(normalized),
    )
    return {
      isLimited: false,
      totalMatches: popularMatches.length,
      isLibraryLoaded: false,
    }
  }

  const totalMatches = iconNames.filter((name) => name.includes(normalized)).length

  return {
    isLimited: totalMatches > COVER_LUCIDE_SEARCH_LIMIT,
    totalMatches,
    isLibraryLoaded: true,
  }
}
