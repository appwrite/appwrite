/** Matches explorer service pages at /docs/references/{version}/{platform}/{service}. */
export function isApiReferenceExplorerPath(pathname: string): boolean {
  const normalized = pathname.replace(/\/+$/, '') || '/'
  return /^\/docs\/references\/[^/]+\/(client-|server-)[^/]+\/[^/]+$/.test(
    normalized,
  )
}
