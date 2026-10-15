/** Label the server puts on the anonymous console user that owns a Zero project. */
export const ZERO_ACCOUNT_LABEL = 'zero'

export function isZeroAccount(
  account: { labels?: string[] } | null | undefined,
): boolean {
  return !!account?.labels?.includes(ZERO_ACCOUNT_LABEL)
}

/** Claim page path for a Zero project; the secret travels in the query. */
export function getZeroClaimPath(zeroId: string, secret: string): string {
  return `/zero/${encodeURIComponent(zeroId)}?secret=${encodeURIComponent(secret)}`
}
