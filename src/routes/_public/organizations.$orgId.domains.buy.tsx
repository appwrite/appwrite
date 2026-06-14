import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { BuyDomainWizard } from '@/components/pages/organizations/$orgId/domains/_components/BuyDomainWizard'
import { pageTitle } from '@/lib/utils/page-title'
import {
  DOMAINS_DEFAULT_SORT_BY,
  DOMAINS_DEFAULT_SORT_ORDER,
  organizationDomainsQueryOptions,
  organizationPlanQueryOptions,
} from '@/lib/react-query/hooks'

export const buyDomainSearchSchema = z.object({
  payment: z.enum(['purchase']).optional(),
  invoiceId: z.string().optional(),
  domain: z.string().optional(),
  stage: z.enum(['checkout']).optional(),
})

export type BuyDomainWizardSearch = z.infer<typeof buyDomainSearchSchema>

export const Route = createFileRoute(
  '/_public/organizations/$orgId/domains/buy',
)({
  head: () => ({ meta: [{ title: pageTitle('Buy domain', 'Domains') }] }),
  validateSearch: buyDomainSearchSchema,
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
  component: BuyDomainWizardPage,
})

function BuyDomainWizardPage() {
  const routeSearch = Route.useSearch()
  return <BuyDomainWizard routeSearch={routeSearch} />
}
