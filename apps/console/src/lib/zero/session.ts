/** Label the server puts on the anonymous console user that owns a Zero project. */
export const ZERO_ACCOUNT_LABEL = 'zero'

export function isZeroAccount(
  account: { labels?: string[] } | null | undefined,
): boolean {
  return !!account?.labels?.includes(ZERO_ACCOUNT_LABEL)
}

/**
 * The transfer a Zero project's claim link points at. The link may name
 * another console host, so the console reads its params and opens its own
 * transfer page.
 */
export function getZeroTransferSearch(
  claimUrl: string | null | undefined,
): { transferId: string; secret: string } | null {
  if (!claimUrl) return null
  let url: URL
  try {
    url = new URL(claimUrl, 'http://localhost')
  } catch {
    return null
  }
  const transferId = url.searchParams.get('transferId')
  const secret = url.searchParams.get('secret')
  if (!transferId || !secret) return null
  return { transferId, secret }
}
