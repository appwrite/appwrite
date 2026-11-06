import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { SalesWizardFullscreen } from '@/components/pages/sales/SalesWizardFullscreen'
import { preferredOrganizationId } from '@/lib/assistant/agent-paths'
import {
  ensureConsoleAccountQueryData,
  organizationQueryOptions,
  organizationsQueryOptions,
} from '@/lib/react-query/hooks'
import { SALES_SOURCES } from '@/lib/sales/contact-sales'
import { searchParamsFromRouterLocation } from '@/lib/table-filters'
import { pageTitle } from '@/lib/utils/page-title'

const salesSearchSchema = z.object({
  source: z.enum(SALES_SOURCES).optional().catch(undefined),
  orgId: z.string().optional().catch(undefined),
})

export const Route = createFileRoute('/_public/sales')({
  validateSearch: salesSearchSchema,
  head: () => ({ meta: [{ title: pageTitle('Contact sales') }] }),
  loader: async ({ context, location }) => {
    if (typeof window === 'undefined') {
      return
    }

    const { queryClient } = context
    const account = await ensureConsoleAccountQueryData(queryClient)

    await queryClient.ensureQueryData(organizationsQueryOptions())

    const orgId =
      searchParamsFromRouterLocation(location).get('orgId') ??
      preferredOrganizationId(
        account?.prefs as Record<string, unknown> | undefined,
      )
    if (orgId) {
      await queryClient
        .ensureQueryData(organizationQueryOptions(orgId))
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
