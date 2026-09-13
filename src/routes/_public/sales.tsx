import { createFileRoute } from '@tanstack/react-router'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { SalesWizardFullscreen } from '@/components/pages/sales/SalesWizardFullscreen'
import { preferredOrganizationId } from '@/lib/assistant/agent-paths'
import {
  ensureConsoleAccountQueryData,
  organizationQueryOptions,
  organizationsQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/sales')({
  head: () => ({ meta: [{ title: pageTitle('Contact sales') }] }),
  loader: async ({ context }) => {
    if (typeof window === 'undefined') {
      return
    }

    const { queryClient } = context
    const account = await ensureConsoleAccountQueryData(queryClient)

    await queryClient.ensureQueryData(organizationsQueryOptions())

    const preferredOrgId = preferredOrganizationId(
      account?.prefs as Record<string, unknown> | undefined,
    )
    if (preferredOrgId) {
      await queryClient
        .ensureQueryData(organizationQueryOptions(preferredOrgId))
        .catch(() => {})
    }
  },
  component: SalesPage,
})

function SalesPage() {
  return (
    <RequireAuth>
      <SalesWizardFullscreen />
    </RequireAuth>
  )
}
