import { AppwriteException, type Models } from '@appwrite.io/console'
import {
  findActiveOrPendingAddon,
  planSupportsAddon,
  type SupportedAddonKey,
} from '@/lib/billing/addons'

/**
 * Single sign-on is sold as an add-on: an organization's own Single sign-on
 * add-on (`ssoOrg`), for single sign-on into the Console, and a project's
 * (`sso`), for the teams in the project.
 *
 * - `active`: the add-on is on (`status` active, `currentValue` 1), single
 *   sign-on can be set up. One scheduled for removal (`nextValue` 0) stays on
 *   until the cycle ends.
 * - `pending`: the add-on waits for its payment to be confirmed. It does not
 *   count yet.
 * - `scheduled`: the add-on was enabled with nothing left to pay in this cycle,
 *   so it starts with the next one (`status` active, `currentValue` 0,
 *   `nextValue` 1). No payment is waiting, and it does not count yet.
 * - `available`: the plan sells the add-on, and it is not on.
 * - `unavailable`: the plan does not sell the add-on.
 *
 * Without an active add-on the API refuses to add or verify a domain, to add or
 * update an identity provider and to turn Require SSO on, signs nobody in and
 * requires nothing. Listing, deleting and turning Require SSO off still work,
 * so an organization can clean up.
 */
export type SSOAddonStatus =
  | 'active'
  | 'pending'
  | 'scheduled'
  | 'available'
  | 'unavailable'

export function ssoAddonStatus(
  plan: unknown,
  addons: Models.Addon[] | undefined,
  key: SupportedAddonKey,
): SSOAddonStatus {
  const addon = findActiveOrPendingAddon(addons, key)
  if (addon?.status === 'active' && addon.currentValue !== 0) return 'active'
  // Enabled for the next cycle; one removed again before it started is not on
  if (addon?.status === 'active') {
    if (addon.nextValue !== 0) return 'scheduled'
  } else if (addon) {
    return 'pending'
  }
  return planSupportsAddon(plan, key) ? 'available' : 'unavailable'
}

/**
 * What the project's Single sign-on add-on unlocks, in its card and its dialog:
 * the app's users sign in through the identity providers of the project's
 * teams, and each team sets that up on its Single sign-on tab.
 */
export const SSO_ADDON_DESCRIPTION =
  "With this add-on, your app's users sign in through their company's identity provider. Each team in this project can verify domains, connect identity providers and turn on Require SSO."

/**
 * What the organization's Single sign-on add-on unlocks, in its card and its
 * dialog: single sign-on into the Console.
 */
export const ORGANIZATION_SSO_ADDON_DESCRIPTION =
  "With this add-on, people on your organization's verified domains sign in to the Console through its identity providers."

/** The API's answer to an organization's setup request without the add-on. */
export const SSO_ADDON_REQUIRED_MESSAGE =
  "Single sign-on for an organization needs the Single sign-on add-on. Enable it in the organization's settings."

/** The API's answer to a setup request for a team of a project without the add-on. */
export const PROJECT_SSO_ADDON_REQUIRED_MESSAGE =
  "Single sign-on in a project needs the Single sign-on add-on. Enable it in the project's settings."

export function isSSOAddonError(error: unknown): boolean {
  return (
    error instanceof AppwriteException && error.type === 'sso_addon_required'
  )
}
