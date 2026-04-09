/**
 * Starts the same create-organization flow as the header "New Organization" action:
 * optional in-app callback (org overview), else navigate with `createOrg` search param.
 */
export function openCreateOrganizationFlow(
  navigate: (opts: {
    to: '/organizations/$orgId' | '/'
    params?: { orgId: string }
    search?: { createOrg: boolean }
  }) => void,
  options: {
    onCreateOrganization?: () => void
    orgId: string | undefined
  },
): void {
  if (options.onCreateOrganization) {
    options.onCreateOrganization()
    return
  }
  if (options.orgId) {
    navigate({
      to: '/organizations/$orgId',
      params: { orgId: options.orgId },
      search: { createOrg: true },
    })
  } else {
    navigate({
      to: '/',
      search: { createOrg: true },
    })
  }
}
