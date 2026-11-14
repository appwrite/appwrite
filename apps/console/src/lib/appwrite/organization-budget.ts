/**
 * Budget alert recipients exist only in editions that add them: those
 * return `budgetNotify` on the organization and take `notify` on the budget
 * route. The generated `updateBudget` drops parameters it does not know, so
 * a change of recipients goes through `client.call` instead.
 */

import type { Client, Models } from '@appwrite.io/console'

export type BudgetUpdate = {
  organizationId: string
  budget?: number
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

export function updateBudgetRecipients(
  client: Client,
  params: BudgetUpdate & { notify: string[] },
): Promise<Models.Organization<Models.Preferences>> {
  const { organizationId, ...payload } = params
  const uri = new URL(
    `${client.config.endpoint}/organizations/${encodeURIComponent(organizationId)}/budget`,
  )
  return client.call(
    'patch',
    uri,
    {
      'X-Appwrite-Project': client.config.project,
      'content-type': 'application/json',
      accept: 'application/json',
    },
    payload,
  ) as Promise<Models.Organization<Models.Preferences>>
}
