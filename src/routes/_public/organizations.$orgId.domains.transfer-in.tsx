import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { TransferDomainInWizard } from '@/components/pages/organizations/$orgId/domains/_components/TransferDomainInWizard'
import { pageTitle } from '@/lib/utils/page-title'
import {
  DOMAINS_DEFAULT_SORT_BY,
  DOMAINS_DEFAULT_SORT_ORDER,
  organizationDomainsQueryOptions,
  organizationPlanQueryOptions,
} from '@/lib/react-query/hooks'

export const transferInSearchSchema = z.object({
  payment: z.enum(['transfer_in']).optional(),
  invoiceId: z.string().optional(),
})

export type TransferInSearch = z.infer<typeof transferInSearchSchema>

export const Route = createFileRoute(
  '/_public/organizations/$orgId/domains/transfer-in',
)({
  head: () => ({
    meta: [{ title: pageTitle('Transfer domain in', 'Domains') }],
  }),
  validateSearch: transferInSearchSchema,
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { orgId } = params
    const { queryClient } = context
    if (!orgId) return

    await Promise.all([
      queryClient.ensureQueryData(organizationPlanQueryOptions(orgId)),
      queryClient.ensureQueryData(
        organizationDomainsQueryOptions(
          orgId,
          0,
          1,
          undefined,
          undefined,
          DOMAINS_DEFAULT_SORT_BY,
          DOMAINS_DEFAULT_SORT_ORDER,
        ),
      ),
    ])
  },
  component: TransferDomainInPage,
})

function TransferDomainInPage() {
  const search = Route.useSearch()
  return <TransferDomainInWizard search={search} />
}
