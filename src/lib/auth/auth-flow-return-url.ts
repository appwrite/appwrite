/** Current auth page URL (path + query + hash) for return-after-sign-out flows. */
export function getAuthFlowReturnUrl(): string {
  if (typeof window === 'undefined') return '/'
  return `${window.location.pathname}${window.location.search}${window.location.hash}`
}

export function isDebugAuthPreviewPath(pathname: string): boolean {
  return pathname.startsWith('/debug/')
}

export function resolveAuthSignInPath(
  pathname?: string,
): '/sign-in' | '/debug/sign-in-preview' {
  const path =
    pathname ?? (typeof window !== 'undefined' ? window.location.pathname : '')
  return isDebugAuthPreviewPath(path) ? '/debug/sign-in-preview' : '/sign-in'
}
