import { createFileRoute, redirect } from '@tanstack/react-router'
import { BuyDomainForResourceWizard } from '@/components/pages/projects/$projectId/shared/BuyDomainForResourceWizard'
import { buyDomainSearchSchema } from '@/lib/domains/buy-wizard'
import { isCloudProfile } from '@/lib/console-profiles'
import { pageTitle } from '@/lib/utils/page-title'
import {
  DOMAINS_DEFAULT_SORT_BY,
  DOMAINS_DEFAULT_SORT_ORDER,
  organizationDomainsQueryOptions,
  organizationPlanQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/settings/domains/buy',
)({
  head: () => ({ meta: [{ title: pageTitle('Buy domain', 'Settings') }] }),
  validateSearch: buyDomainSearchSchema,
  beforeLoad: ({ params }) => {
    if (!isCloudProfile()) {
      throw redirect({
        to: '/projects/$projectId/settings/domains',
        params: { projectId: params.projectId },
        replace: true,
      })
    }
  },
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId } = params
    const { queryClient } = context
    const project = await queryClient.ensureQueryData(
      projectQueryOptions(projectId),
    )
    const orgId = project?.teamId
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
  component: BuyProjectDomainPage,
})

function BuyProjectDomainPage() {
  const { projectId } = Route.useParams()
  const routeSearch = Route.useSearch()
  return (
    <BuyDomainForResourceWizard
      resourceType="api"
      fallbackPath={`/projects/${projectId}/settings/domains`}
      routeSearch={routeSearch}
    />
  )
}
