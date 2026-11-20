import { AppwriteException } from '@appwrite.io/console'

/**
 * Single sign-on is part of Pro and the plans built on it. The organization's
 * plan says so with `supportsSSO`; an older API leaves it out, which counts as
 * not supported. Without it the API refuses to add or verify a domain, to add
 * or update an identity provider and to turn Require SSO on, signs nobody in,
 * and requires nothing. Listing, deleting and turning Require SSO off still
 * work, so an organization that left Pro can clean up.
 */
export function planSupportsSSO(plan: unknown): boolean {
  return (
    !!plan &&
    typeof plan === 'object' &&
    (plan as { supportsSSO?: unknown }).supportsSSO === true
  )
}

/** The API's answer to a setup request on a plan without single sign-on. */
export const SSO_PLAN_REQUIRED_MESSAGE =
  'Single sign-on requires a Pro plan or higher.'

export function isSSOPlanError(error: unknown): boolean {
  return (
    error instanceof AppwriteException &&
    error.type === 'billing_plan_not_allowed'
  )
}
