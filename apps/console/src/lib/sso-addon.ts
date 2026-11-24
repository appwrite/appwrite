import { AppwriteException, type Models } from '@appwrite.io/console'
import {
  findActiveOrPendingAddon,
  planSupportsAddon,
  type SupportedAddonKey,
} from '@/lib/billing/addons'

/**
 * Single sign-on is sold as an add-on: for an organization it is part of the
 * HIPAA BAA add-on (`baa`), for the teams in a project it is the Single sign-on
 * add-on (`sso`).
 *
 * - `active`: the add-on is on (`status` active, `currentValue` 1), single
 *   sign-on can be set up. One scheduled for removal (`nextValue` 0) stays on
 *   until the cycle ends.
 * - `pending`: the add-on waits for its payment to be confirmed, or starts with
 *   the next cycle (`currentValue` 0). It does not count yet.
 * - `available`: the plan sells the add-on, and it is not on.
 * - `unavailable`: the plan does not sell the add-on.
 *
 * Without an active add-on the API refuses to add or verify a domain, to add or
 * update an identity provider and to turn Require SSO on, signs nobody in and
 * requires nothing. Listing, deleting and turning Require SSO off still work,
 * so an organization can clean up.
 */
export type SSOAddonStatus = 'active' | 'pending' | 'available' | 'unavailable'

export function ssoAddonStatus(
  plan: unknown,
  addons: Models.Addon[] | undefined,
  key: SupportedAddonKey,
): SSOAddonStatus {
  const addon = findActiveOrPendingAddon(addons, key)
  if (addon?.status === 'active' && addon.currentValue !== 0) return 'active'
  if (addon) return 'pending'
  return planSupportsAddon(plan, key) ? 'available' : 'unavailable'
}

/** The API's answer to an organization's setup request without the add-on. */
export const SSO_ADDON_REQUIRED_MESSAGE =
  "Single sign-on is part of the HIPAA BAA add-on. Enable it in the organization's settings."

export function isSSOAddonError(error: unknown): boolean {
  return (
    error instanceof AppwriteException && error.type === 'sso_addon_required'
  )
}
