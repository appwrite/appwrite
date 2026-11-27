/**
 * Budget alert recipients exist only in editions that add them: those
 * return `budgetNotify` on the organization and take `notify` on the budget
 * route, so `notify` is only sent when the recipients change.
 */

export type BudgetUpdate = {
  organizationId: string
  /** null removes the cap; 0 is a $0 cap. Always sent: the SDK requires it. */
  budget: number | null
  alerts?: number[]
  /** Organization roles emailed when a budget alert fires. */
  notify?: string[]
}

/** The roles budget alerts reach, or null when the edition has none. */
export function budgetRecipients(
  organization: object | null | undefined,
): string[] | null {
  const value = (organization as { budgetNotify?: unknown } | null | undefined)
    ?.budgetNotify
  return Array.isArray(value) ? value.map(String) : null
}

/**
 * The recipients to send after a change, or null when there is nothing to
 * send: the selection is unchanged, or empty, which would email no one.
 */
export function changedBudgetRecipients(
  current: readonly string[],
  next: readonly string[],
): string[] | null {
  if (next.length === 0) return null
  const unchanged =
    next.length === current.length &&
    next.every((role) => current.includes(role))
  return unchanged ? null : [...next]
}
