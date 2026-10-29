export type SettingsSearchFields = {
  title: string
  description?: string
  keywords?: string[]
}

export type SettingsCardIndexEntry = SettingsSearchFields & {
  sectionId: string
}

function normalizeQuery(query: string): string {
  return query.trim().toLowerCase()
}

function searchableText(fields: SettingsSearchFields): string {
  const parts = [
    fields.title,
    fields.description ?? '',
    ...(fields.keywords ?? []),
  ]
  return parts.join(' ').toLowerCase()
}

/** Returns true when query is empty or any searchable field contains the query. */
export function matchesSettingsSearch(
  query: string,
  fields: SettingsSearchFields,
): boolean {
  const q = normalizeQuery(query)
  if (!q) return true
  const haystack = searchableText(fields)
  if (haystack.includes(q)) return true
  return q
    .split(/\s+/)
    .filter(Boolean)
    .every((token) => haystack.includes(token))
}

export function sectionHasMatchingCards(
  query: string,
  sectionId: string,
  cardIndex: SettingsCardIndexEntry[],
): boolean {
  const q = normalizeQuery(query)
  if (!q) return true
  return cardIndex.some(
    (card) => card.sectionId === sectionId && matchesSettingsSearch(q, card),
  )
}

export function firstSectionWithMatchingCards(
  query: string,
  sectionIds: string[],
  cardIndex: SettingsCardIndexEntry[],
): string | undefined {
  const q = normalizeQuery(query)
  if (!q) return undefined
  return sectionIds.find((id) => sectionHasMatchingCards(q, id, cardIndex))
}
