/**
 * Navigate to the fullscreen change-plan wizard at `/upgrade`.
 * Pass `orgId` when known so the wizard is scoped to that organization.
 */
export function navigateToUpgradeWizard(
  navigate: (opts: {
    to: '/upgrade'
    search?: { orgId: string }
  }) => void,
  orgId?: string | null,
): void {
  if (orgId) {
    navigate({ to: '/upgrade', search: { orgId } })
    return
  }
  navigate({ to: '/upgrade' })
}

/** Resolve organization id from `/organizations/:orgId/...` in the current path. */
export function getOrgIdFromPathname(
  pathname = typeof window !== 'undefined' ? window.location.pathname : '',
): string | null {
  const pathParts = pathname.split('/').filter(Boolean)
  const orgIndex = pathParts.findIndex((part) => part === 'organizations')
  if (orgIndex >= 0 && pathParts[orgIndex + 1]) {
    return pathParts[orgIndex + 1]
  }
  return null
}
