import { normalizeVisitorCountryCode } from '@/lib/visitor-country-shared'

/**
 * Suggest a Cloud region from country (and optional continent / timezone).
 *
 * We do not have the visitor's coordinates, so a distance check between nearby
 * regions (Toronto vs New York) would be a guess. Country is the discriminator:
 * a region in the visitor's country wins, and we never send a US visitor to
 * Toronto or a Canadian visitor to New York. Timezone is only used to split
 * countries that host more than one region (US → NYC or SFO) or when country
 * is missing.
 */

export const LIVE_PROJECT_REGIONS = [
  'fra',
  'nyc',
  'sfo',
  'sgp',
  'syd',
  'tor',
] as const

export type ProjectRegionId = (typeof LIVE_PROJECT_REGIONS)[number]

export type SuggestProjectRegionInput = {
  countryCode?: string | null
  continentCode?: string | null
  timeZone?: string | null
  availableRegionIds: readonly string[]
}

function codes(...groups: string[]): Set<string> {
  const set = new Set<string>()
  for (const group of groups) {
    for (const part of group.split(' ')) {
      if (part) set.add(part)
    }
  }
  return set
}

function normalizeRegionId(value: string | null | undefined): string | null {
  const normalized = value?.trim().toLowerCase()
  return normalized || null
}

function normalizeContinentCode(
  value: string | null | undefined,
): string | null {
  const normalized = value?.trim().toUpperCase()
  return normalized && normalized.length === 2 ? normalized : null
}

function normalizeTimeZone(value: string | null | undefined): string | null {
  const normalized = value?.trim()
  return normalized || null
}

/** Europe, Africa, Middle East, Central Asia. */
const FRA_COUNTRIES = codes(
  'AD AL AT AX BA BE BG BY CH CY CZ DE DK EE ES FI FO FR GB GG GI GR HR HU IE IM IS IT JE LI LT LU LV MC MD ME MK MT NL NO PL PT RO RS SE SI SK SM UA VA XK',
  'RU',
  'AO BF BI BJ BW CD CF CG CI CM CV DJ DZ EG EH ER ET GA GH GM GN GQ GW KE KM LR LS LY MA MG ML MR MU MW MZ NA NE NG RE RW SC SD SH SL SN SO SS ST SZ TD TG TN TZ UG YT ZA ZM ZW',
  'AE AM AZ BH GE IL IQ JO KW LB OM PS QA SA SY TR YE',
  'KG KZ TJ TM UZ',
  'AF IR',
)

/** South, South-East, and East Asia. */
const SGP_COUNTRIES = codes(
  'BD BT IN LK MV NP PK',
  'BN KH ID LA MM MY PH SG TH TL VN',
  'CN HK JP KP KR MO MN TW',
  'GU MP',
)

/** Australia, New Zealand, and the Pacific. */
const SYD_COUNTRIES = codes(
  'AU CC CX HM NF NZ',
  'FJ FM KI MH NC NR PG PW SB VU',
  'AS CK NU PF PN TK TO TV WF WS',
)

/**
 * Caribbean, Central America (except Mexico), South America, and the
 * remaining North-Atlantic territories. The US is handled separately so it
 * cannot land on Toronto.
 */
const NYC_COUNTRIES = codes(
  'AG AI AR AW BB BL BM BO BQ BR BS BZ CL CO CR CU CW DM DO EC FK GD GF GL GP GT GY HN HT JM KN KY LC MF MQ MS NI PA PE PM PR PY SR SV SX TC TT UY VC VE VG VI',
)

/** Prefer a local region when Cloud adds one; fall through to today's live set. */
const COUNTRY_PREFERENCES: Record<string, readonly string[]> = {
  CA: ['tor', 'nyc', 'sfo', 'fra', 'sgp', 'syd'],
  GB: ['lon', 'fra', 'nyc', 'sgp', 'syd', 'tor'],
  IE: ['lon', 'fra', 'nyc', 'sgp', 'syd', 'tor'],
  IM: ['lon', 'fra', 'nyc', 'sgp', 'syd', 'tor'],
  GG: ['lon', 'fra', 'nyc', 'sgp', 'syd', 'tor'],
  JE: ['lon', 'fra', 'nyc', 'sgp', 'syd', 'tor'],
  NL: ['ams', 'fra', 'nyc', 'sgp', 'syd', 'tor'],
  BE: ['ams', 'fra', 'nyc', 'sgp', 'syd', 'tor'],
  LU: ['ams', 'fra', 'nyc', 'sgp', 'syd', 'tor'],
  IN: ['blr', 'sgp', 'fra', 'syd', 'nyc', 'tor'],
  NP: ['blr', 'sgp', 'fra', 'syd', 'nyc', 'tor'],
  BD: ['blr', 'sgp', 'fra', 'syd', 'nyc', 'tor'],
  LK: ['blr', 'sgp', 'fra', 'syd', 'nyc', 'tor'],
  MV: ['blr', 'sgp', 'fra', 'syd', 'nyc', 'tor'],
  BT: ['blr', 'sgp', 'fra', 'syd', 'nyc', 'tor'],
  PK: ['blr', 'sgp', 'fra', 'syd', 'nyc', 'tor'],
  SG: ['sgp', 'syd', 'fra', 'nyc', 'sfo', 'tor'],
  AU: ['syd', 'sgp', 'sfo', 'fra', 'nyc', 'tor'],
  DE: ['fra', 'nyc', 'sgp', 'syd', 'tor'],
}

const CONTINENT_PREFERENCES: Record<string, readonly string[]> = {
  EU: ['fra', 'lon', 'ams', 'nyc', 'sgp', 'syd', 'tor'],
  AF: ['fra', 'sgp', 'nyc', 'syd', 'tor'],
  AS: ['sgp', 'blr', 'fra', 'syd', 'nyc', 'tor'],
  NA: ['nyc', 'tor', 'sfo', 'fra', 'sgp', 'syd'],
  SA: ['nyc', 'sfo', 'tor', 'fra', 'sgp', 'syd'],
  OC: ['syd', 'sgp', 'sfo', 'fra', 'nyc', 'tor'],
  AN: ['syd', 'fra', 'sfo', 'nyc', 'sgp', 'tor'],
}

/**
 * Pacific / Mountain / Alaska / Hawaii. Used to split the US (two regions in
 * one country) without pretending we know city-level distance.
 */
const WESTERN_AMERICA_TIMEZONES = new Set([
  'America/Adak',
  'America/Anchorage',
  'America/Boise',
  'America/Cambridge_Bay',
  'America/Chihuahua',
  'America/Ciudad_Juarez',
  'America/Creston',
  'America/Dawson',
  'America/Dawson_Creek',
  'America/Denver',
  'America/Edmonton',
  'America/Fort_Nelson',
  'America/Hermosillo',
  'America/Inuvik',
  'America/Juneau',
  'America/Los_Angeles',
  'America/Mazatlan',
  'America/Metlakatla',
  'America/Nome',
  'America/Ojinaga',
  'America/Phoenix',
  'America/Shiprock',
  'America/Sitka',
  'America/Tijuana',
  'America/Vancouver',
  'America/Whitehorse',
  'America/Yakutat',
  'America/Yellowknife',
  'MST7MDT',
  'Navajo',
  'PST8PDT',
  'Pacific/Honolulu',
  'Pacific/Johnston',
  'Pacific/Midway',
  'Pacific/Wake',
  'US/Alaska',
  'US/Aleutian',
  'US/Arizona',
  'US/Hawaii',
  'US/Mountain',
  'US/Pacific',
  'US/Pacific-New',
])

const EASTERN_MEXICO_TIMEZONES = new Set([
  'America/Cancun',
  'America/Matamoros',
])

const TORONTO_TIMEZONES = new Set([
  'America/Atikokan',
  'America/Blanc-Sablon',
  'America/Coral_Harbour',
  'America/Glace_Bay',
  'America/Goose_Bay',
  'America/Halifax',
  'America/Iqaluit',
  'America/Moncton',
  'America/Montreal',
  'America/Nipigon',
  'America/Pangnirtung',
  'America/Rainy_River',
  'America/Rankin_Inlet',
  'America/Regina',
  'America/Resolute',
  'America/St_Johns',
  'America/Swift_Current',
  'America/Thunder_Bay',
  'America/Toronto',
  'America/Winnipeg',
  'Canada/Atlantic',
  'Canada/Central',
  'Canada/Eastern',
  'Canada/Newfoundland',
  'Canada/Saskatchewan',
])

const MIDDLE_EAST_TIMEZONES = new Set([
  'Asia/Amman',
  'Asia/Baghdad',
  'Asia/Bahrain',
  'Asia/Baku',
  'Asia/Beirut',
  'Asia/Damascus',
  'Asia/Dubai',
  'Asia/Famagusta',
  'Asia/Gaza',
  'Asia/Hebron',
  'Asia/Istanbul',
  'Asia/Jerusalem',
  'Asia/Kuwait',
  'Asia/Muscat',
  'Asia/Nicosia',
  'Asia/Qatar',
  'Asia/Riyadh',
  'Asia/Tehran',
  'Asia/Tbilisi',
  'Asia/Yerevan',
  'Europe/Istanbul',
])

const FRA_FALLBACK = ['fra', 'nyc', 'sgp', 'syd', 'tor', 'sfo'] as const
const NYC_FALLBACK = ['nyc', 'tor', 'sfo', 'fra', 'sgp', 'syd'] as const
const SFO_FALLBACK = ['sfo', 'nyc', 'tor', 'sgp', 'syd', 'fra'] as const
const SGP_FALLBACK = ['sgp', 'syd', 'fra', 'nyc', 'tor', 'sfo'] as const
const SYD_FALLBACK = ['syd', 'sgp', 'sfo', 'fra', 'nyc', 'tor'] as const
const TOR_FALLBACK = ['tor', 'nyc', 'sfo', 'fra', 'sgp', 'syd'] as const

function usPreferences(timeZone: string | null): readonly string[] {
  if (timeZone && WESTERN_AMERICA_TIMEZONES.has(timeZone)) {
    return SFO_FALLBACK
  }
  return NYC_FALLBACK
}

function mxPreferences(timeZone: string | null): readonly string[] {
  if (timeZone && EASTERN_MEXICO_TIMEZONES.has(timeZone)) {
    return NYC_FALLBACK
  }
  return SFO_FALLBACK
}

function countryPreferences(
  country: string,
  timeZone: string | null,
): readonly string[] | null {
  if (country === 'US') return usPreferences(timeZone)
  if (country === 'MX') return mxPreferences(timeZone)

  const explicit = COUNTRY_PREFERENCES[country]
  if (explicit) return explicit

  if (FRA_COUNTRIES.has(country)) return FRA_FALLBACK
  if (SGP_COUNTRIES.has(country)) return SGP_FALLBACK
  if (SYD_COUNTRIES.has(country)) return SYD_FALLBACK
  if (NYC_COUNTRIES.has(country)) return NYC_FALLBACK
  return null
}

function timeZonePreferences(timeZone: string): readonly string[] | null {
  if (WESTERN_AMERICA_TIMEZONES.has(timeZone)) return SFO_FALLBACK
  if (TORONTO_TIMEZONES.has(timeZone)) return TOR_FALLBACK
  if (timeZone.startsWith('America/')) return NYC_FALLBACK
  if (timeZone.startsWith('US/')) return NYC_FALLBACK
  if (timeZone.startsWith('Canada/')) return TOR_FALLBACK
  if (MIDDLE_EAST_TIMEZONES.has(timeZone)) return FRA_FALLBACK
  if (timeZone.startsWith('Europe/') || timeZone.startsWith('Africa/')) {
    return FRA_FALLBACK
  }
  if (timeZone.startsWith('Australia/') || timeZone === 'Pacific/Auckland') {
    return SYD_FALLBACK
  }
  if (
    timeZone.startsWith('Pacific/Fiji') ||
    timeZone.startsWith('Pacific/Port_Moresby') ||
    timeZone.startsWith('Pacific/Guadalcanal') ||
    timeZone.startsWith('Pacific/Noumea') ||
    timeZone.startsWith('Pacific/Tongatapu') ||
    timeZone.startsWith('Pacific/Apia')
  ) {
    return SYD_FALLBACK
  }
  if (timeZone.startsWith('Asia/') || timeZone.startsWith('Pacific/')) {
    return SGP_FALLBACK
  }
  return null
}

function firstAvailable(
  preferences: readonly string[],
  available: ReadonlySet<string>,
): string | null {
  for (const region of preferences) {
    if (available.has(region)) return region
  }
  return null
}

/**
 * Best available region for the visitor. Returns null only when the available
 * list is empty.
 */
export function suggestProjectRegion(
  input: SuggestProjectRegionInput,
): string | null {
  const available = new Set(
    input.availableRegionIds
      .map((id) => normalizeRegionId(id))
      .filter((id): id is string => id !== null),
  )
  if (available.size === 0) return null

  const country = normalizeVisitorCountryCode(input.countryCode)
  const timeZone = normalizeTimeZone(input.timeZone)
  const continent = normalizeContinentCode(input.continentCode)

  const ranked: readonly string[] | null =
    (country ? countryPreferences(country, timeZone) : null) ??
    (continent ? (CONTINENT_PREFERENCES[continent] ?? null) : null) ??
    (timeZone ? timeZonePreferences(timeZone) : null)

  return (
    (ranked ? firstAvailable(ranked, available) : null) ??
    firstAvailable(LIVE_PROJECT_REGIONS, available) ??
    available.values().next().value ??
    null
  )
}
