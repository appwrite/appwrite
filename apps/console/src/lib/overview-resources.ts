/** Services the dashboard always gives a card, in priority order. */
export const OVERVIEW_PRIMARY_RESOURCES = [
  'databases',
  'sites',
  'functions',
] as const

/** Services that take over an empty primary card when they have items. */
export const OVERVIEW_SECONDARY_RESOURCES = [
  'storage',
  'auth',
  'messaging',
] as const

export type OverviewResourceId =
  | (typeof OVERVIEW_PRIMARY_RESOURCES)[number]
  | (typeof OVERVIEW_SECONDARY_RESOURCES)[number]

/** Items a resource card lists before its "View all" footer. */
export const OVERVIEW_RESOURCE_ITEM_LIMIT = 3

const OVERVIEW_RESOURCE_ORDER: OverviewResourceId[] = [
  ...OVERVIEW_PRIMARY_RESOURCES,
  ...OVERVIEW_SECONDARY_RESOURCES,
]

/**
 * Picks one card per slot for the dashboard resources row.
 *
 * Every visible primary service gets a slot. An empty primary hands its slot
 * to a secondary service that has items, starting from the lowest priority
 * primary so the most important get-started cards stay. A total of `null`
 * means the list failed to load: a primary keeps its slot, a secondary is
 * skipped. Cards with items come first, each group in service order.
 */
export function pickOverviewResources(
  totals: Partial<Record<OverviewResourceId, number | null>>,
  visible: readonly OverviewResourceId[],
): OverviewResourceId[] {
  const isVisible = (id: OverviewResourceId) => visible.includes(id)
  const hasItems = (id: OverviewResourceId) => (totals[id] ?? 0) > 0

  const slots: OverviewResourceId[] =
    OVERVIEW_PRIMARY_RESOURCES.filter(isVisible)
  const replacements = OVERVIEW_SECONDARY_RESOURCES.filter(
    (id) => isVisible(id) && hasItems(id),
  )

  for (let index = slots.length - 1; index >= 0; index--) {
    if (replacements.length === 0) break
    const id = slots[index]
    if (totals[id] === null || hasItems(id)) continue
    slots[index] = replacements.shift()!
  }

  const order = (id: OverviewResourceId) => OVERVIEW_RESOURCE_ORDER.indexOf(id)
  const filled = slots
    .filter((id) => totals[id] !== 0)
    .sort((a, b) => order(a) - order(b))
  const empty = slots.filter((id) => totals[id] === 0)
  return [...filled, ...empty]
}

/** True when an empty primary card could go to a secondary service. */
export function needsOverviewSecondaryResources(
  totals: Partial<Record<OverviewResourceId, number | null>>,
  visible: readonly OverviewResourceId[],
): boolean {
  return OVERVIEW_PRIMARY_RESOURCES.some(
    (id) => visible.includes(id) && totals[id] === 0,
  )
}
