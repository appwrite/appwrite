import { createFileRoute, redirect } from '@tanstack/react-router'
import { BuyDomainWizard } from '@/components/pages/organizations/$orgId/domains/_components/BuyDomainWizard'
import {
  buyDomainSearchSchema,
  type BuyDomainWizardSearch,
} from '@/lib/domains/buy-wizard'
import { isCloudProfile } from '@/lib/console-profiles'
import { pageTitle } from '@/lib/utils/page-title'
import {
  DOMAINS_DEFAULT_SORT_BY,
  DOMAINS_DEFAULT_SORT_ORDER,
  organizationDomainsQueryOptions,
  organizationPlanQueryOptions,
} from '@/lib/react-query/hooks'

export type { BuyDomainWizardSearch }

export const Route = createFileRoute(
  '/_public/organizations/$orgId/domains/buy',
)({
  head: () => ({ meta: [{ title: pageTitle('Buy domain', 'Domains') }] }),
  validateSearch: buyDomainSearchSchema,
  beforeLoad: ({ params }) => {
    if (!isCloudProfile()) {
      throw redirect({
        to: '/organizations/$orgId/domains',
        params: { orgId: params.orgId },
        replace: true,
      })
    }
  },
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
