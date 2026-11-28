/**
 * Whether code reviews may buy extra credits past the plan's allowance.
 * Cloud returns `reviewCreditsOverage` on the organization and takes it on
 * `organizations.updateReviewCredits`.
 */

/**
 * Whether reviews keep running past the included credits. Editions without
 * the switch, and organizations that never chose, read as true.
 */
export function allowsReviewCreditsOverage(
  organization: object | null | undefined,
): boolean {
  const value = (
    organization as { reviewCreditsOverage?: unknown } | null | undefined
  )?.reviewCreditsOverage
  return value !== false
}
