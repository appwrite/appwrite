import type { Models } from '@appwrite.io/console'

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
    if (!code || !name) continue
    codeToName.set(code, name)
    nameToCode.set(name.toLowerCase(), code)
  }

  return { codeToName, nameToCode }
}

export function resolveCountryCode(
  label: string,
  lookups: CountryLookups,
): string | null {
  const trimmed = label.trim()
  if (!trimmed || trimmed === 'Unknown') return null
  if (/^[a-z]{2}$/i.test(trimmed)) return trimmed.toUpperCase()
  return lookups.nameToCode.get(trimmed.toLowerCase()) ?? null
}

export function resolveCountryDisplayName(
  label: string,
  lookups: CountryLookups,
): string {
  const code = resolveCountryCode(label, lookups)
  if (code) return lookups.codeToName.get(code) ?? label
  return label
}
