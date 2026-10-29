import type { Models } from '@appwrite.io/console'

/** Sentinel used by Appwrite locale/session APIs when country is unknown. */
export const UNKNOWN_COUNTRY_CODE = '--'

export type CountryLookups = {
  codeToName: Map<string, string>
  nameToCode: Map<string, string>
}

export function buildCountryLookups(
  countries: Models.Country[] | undefined,
): CountryLookups {
  const codeToName = new Map<string, string>()
  const nameToCode = new Map<string, string>()

  for (const country of countries ?? []) {
    const code = country.code.trim().toUpperCase()
    const name = country.name.trim()
    if (!code || !name || code === UNKNOWN_COUNTRY_CODE) continue
    codeToName.set(code, name)
    nameToCode.set(name.toLowerCase(), code)
  }

  return { codeToName, nameToCode }
}

/**
 * Normalize a value to an ISO-3166-1 alpha-2 country code (uppercase).
 * Returns null for empty values, `--`, or non-code labels.
 */
export function normalizeCountryCode(
  value: string | null | undefined,
): string | null {
  const trimmed = value?.trim()
  if (!trimmed || trimmed === UNKNOWN_COUNTRY_CODE) return null
  if (/^[a-z]{2}$/i.test(trimmed)) return trimmed.toUpperCase()
  return null
}

/**
 * Resolve a country code or display name to an uppercase ISO code using the
 * locale countries list. Accepts 2-letter codes directly.
 */
export function resolveCountryCode(
  label: string,
  lookups: CountryLookups,
): string | null {
  const trimmed = label.trim()
  if (!trimmed || trimmed === UNKNOWN_COUNTRY_CODE || trimmed === 'Unknown') {
    return null
  }

  const asCode = normalizeCountryCode(trimmed)
  if (asCode) return asCode

  return lookups.nameToCode.get(trimmed.toLowerCase()) ?? null
}

/**
 * Resolve a country code or label to the locale API display name.
 * Falls back to the original label when the countries list has no match.
 */
export function resolveCountryDisplayName(
  label: string,
  lookups: CountryLookups,
): string {
  const code = resolveCountryCode(label, lookups)
  if (code) return lookups.codeToName.get(code) ?? label
  return label
}

/**
 * Parse a country code (or name) to a human-readable country name from the
 * locale countries list. Returns null for unknown/empty codes (`--`).
 */
export function getCountryDisplayName(
  codeOrLabel: string | null | undefined,
  lookups?: CountryLookups | null,
): string | null {
  const trimmed = codeOrLabel?.trim()
  if (!trimmed || trimmed === UNKNOWN_COUNTRY_CODE) return null

  if (lookups) {
    const name = resolveCountryDisplayName(trimmed, lookups)
    return name.trim() ? name : null
  }

  return normalizeCountryCode(trimmed) ?? trimmed
}
