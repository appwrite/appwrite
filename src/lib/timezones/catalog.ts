import { POPULAR_TECH_TIMEZONE_IDS, TIMEZONE_SEARCH_ALIASES } from './aliases'
import { countryForTimeZone } from './countries'
import {
  displayNameForTimeZone,
  formatTimeZoneOffset,
  getUserTimeZone,
  isValidTimeZone,
} from './zoned-time'

export type TimezoneOption = {
  id: string
  city: string
  country: string | null
  offset: string
  label: string
  description: string | null
  searchText: string
  popular: boolean
}

function listIanaTimeZones(): string[] {
  const fromIntl =
    typeof Intl.supportedValuesOf === 'function'
      ? Intl.supportedValuesOf('timeZone')
      : []
  const ids = new Set<string>(fromIntl)
  ids.add('UTC')
  return [...ids].filter((id) => id === 'UTC' || isValidTimeZone(id))
}

function genericNames(id: string, at: Date): string[] {
  try {
    const long = new Intl.DateTimeFormat('en-US', {
      timeZone: id,
      timeZoneName: 'long',
    })
      .formatToParts(at)
      .find((part) => part.type === 'timeZoneName')?.value
    const short = new Intl.DateTimeFormat('en-US', {
      timeZone: id,
      timeZoneName: 'short',
    })
      .formatToParts(at)
      .find((part) => part.type === 'timeZoneName')?.value
    return [long, short].filter((value): value is string => Boolean(value))
  } catch {
    return []
  }
}

function aliasesForZone(id: string): string[] {
  const direct = TIMEZONE_SEARCH_ALIASES[id] ?? []
  if (id === 'Asia/Tel_Aviv') {
    return [...direct, ...(TIMEZONE_SEARCH_ALIASES['Asia/Jerusalem'] ?? [])]
  }
  if (id === 'Asia/Kolkata') {
    return [...direct, ...(TIMEZONE_SEARCH_ALIASES['Asia/Calcutta'] ?? [])]
  }
  if (id === 'Asia/Calcutta') {
    return [...direct, ...(TIMEZONE_SEARCH_ALIASES['Asia/Kolkata'] ?? [])]
  }
  return direct
}

function normalizeSearch(value: string): string {
  return value
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, ' ')
    .trim()
}

function buildSearchText(option: {
  id: string
  city: string
  country: string | null
  offset: string
  aliases: string[]
  generic: string[]
}): string {
  return normalizeSearch(
    [
      option.id,
      option.id.replace(/[_/]/g, ' '),
      option.city,
      option.country ?? '',
      option.offset,
      ...option.aliases,
      ...option.generic,
    ].join(' '),
  )
}

export function listTimezoneOptions(at: Date = new Date()): TimezoneOption[] {
  const validPopular = POPULAR_TECH_TIMEZONE_IDS.filter(
    (id) => id === 'UTC' || isValidTimeZone(id),
  )
  const local = getUserTimeZone()
  const popularOrder: string[] = []
  if (validPopular.includes('UTC')) popularOrder.push('UTC')
  if (local && local !== 'UTC' && isValidTimeZone(local)) {
    popularOrder.push(local)
  }
  for (const id of validPopular) {
    if (id !== 'UTC' && id !== local) popularOrder.push(id)
  }
  const popularSet = new Set(popularOrder)

  return listIanaTimeZones()
    .map((id) => {
      const city = displayNameForTimeZone(id)
      const country = countryForTimeZone(id)
      const offset = formatTimeZoneOffset(id, at)
      const aliases = aliasesForZone(id)
      const generic = genericNames(id, at)
      return {
        id,
        city,
        country,
        offset,
        label: `(${offset}) ${city}`,
        description: country && country !== 'UTC' ? country : null,
        searchText: buildSearchText({
          id,
          city,
          country,
          offset,
          aliases,
          generic,
        }),
        popular: popularSet.has(id),
      }
    })
    .sort((left, right) => {
      const leftRank = popularOrder.indexOf(left.id)
      const rightRank = popularOrder.indexOf(right.id)
      const leftPopular = leftRank === -1 ? Number.MAX_SAFE_INTEGER : leftRank
      const rightPopular =
        rightRank === -1 ? Number.MAX_SAFE_INTEGER : rightRank
      if (left.popular && right.popular && leftPopular !== rightPopular) {
        return leftPopular - rightPopular
      }
      if (left.popular !== right.popular) return left.popular ? -1 : 1
      return left.label.localeCompare(right.label)
    })
}

export function timezoneMatchesQuery(
  option: TimezoneOption,
  query: string,
): boolean {
  const normalized = normalizeSearch(query)
  if (!normalized) return true
  if (option.searchText.includes(normalized)) return true
  const tokens = normalized.split(' ').filter(Boolean)
  return tokens.every((token) =>
    option.searchText.split(' ').includes(token),
  )
}

export { getUserTimeZone }
