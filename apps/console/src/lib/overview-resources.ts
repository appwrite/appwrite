/** Services the dashboard always gives a card, in priority order. */
export const OVERVIEW_PRIMARY_RESOURCES = [
  'databases',
  'sites',
  'functions',
] as const

/** Services that get the remaining cards, in priority order. */
export const OVERVIEW_SECONDARY_RESOURCES = [
  'storage',
  'auth',
  'messaging',
] as const

export type OverviewResourceId =
  | (typeof OVERVIEW_PRIMARY_RESOURCES)[number]
  | (typeof OVERVIEW_SECONDARY_RESOURCES)[number]

/** Cards in the dashboard resources grid. */
export const OVERVIEW_RESOURCE_CARD_LIMIT = 6

/** Items a resource card lists before its "View all" footer. */
export const OVERVIEW_RESOURCE_ITEM_LIMIT = 3

const OVERVIEW_RESOURCE_ORDER: OverviewResourceId[] = [
  ...OVERVIEW_PRIMARY_RESOURCES,
  ...OVERVIEW_SECONDARY_RESOURCES,
]

/**
 * Picks the services the dashboard resources grid shows, one card each.
 *
 * Every visible primary service gets a card. Secondary services fill the
 * cards left, those with items first. When there are more services than
 * cards, an empty primary hands its card to a secondary service that has
 * items, starting from the lowest priority primary so the most important
 * get-started cards stay. A missing total counts as empty; `null` means the
 * list failed to load: the card shows the error, but never takes the place
 * of another service.
 * Cards with items (or an error) come first, each group in service order.
 */
export function pickOverviewResources(
  totals: Partial<Record<OverviewResourceId, number | null>>,
  visible: readonly OverviewResourceId[],
  limit: number = OVERVIEW_RESOURCE_CARD_LIMIT,
): OverviewResourceId[] {
  const isVisible = (id: OverviewResourceId) => visible.includes(id)
  const total = (id: OverviewResourceId) => {
    const value = totals[id]
    return value === undefined ? 0 : value
  }
  const hasItems = (id: OverviewResourceId) => (total(id) ?? 0) > 0
  const rank = (id: OverviewResourceId) =>
    hasItems(id) ? 0 : total(id) === null ? 1 : 2

  const slots: OverviewResourceId[] = OVERVIEW_PRIMARY_RESOURCES.filter(
    isVisible,
  ).slice(0, limit)
  const extras = OVERVIEW_SECONDARY_RESOURCES.filter(isVisible).sort(
    (a, b) => rank(a) - rank(b),
  )
  while (slots.length < limit && extras.length > 0) {
    slots.push(extras.shift()!)
  }

  const replacements = extras.filter(hasItems)
  for (let index = slots.length - 1; index >= 0; index--) {
    if (replacements.length === 0) break
    const id = slots[index]
    if (total(id) !== 0) continue
    slots[index] = replacements.shift()!
  }

  const order = (id: OverviewResourceId) => OVERVIEW_RESOURCE_ORDER.indexOf(id)
  const filled = slots
    .filter((id) => total(id) !== 0)
    .sort((a, b) => order(a) - order(b))
  const empty = slots
    .filter((id) => total(id) === 0)
    .sort((a, b) => order(a) - order(b))
  return [...filled, ...empty]
}
