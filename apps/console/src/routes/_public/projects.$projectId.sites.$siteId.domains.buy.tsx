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
  siteQueryOptions,
} from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/domains/buy',
)({
  head: () => ({ meta: [{ title: pageTitle('Buy domain', 'Sites') }] }),
  validateSearch: buyDomainSearchSchema,
  beforeLoad: ({ params }) => {
    if (!isCloudProfile()) {
      throw redirect({
        to: '/projects/$projectId/sites/$siteId/domains',
        params: { projectId: params.projectId, siteId: params.siteId },
        replace: true,
      })
    }
  },
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, siteId } = params
    const { queryClient } = context
    const project = await queryClient.ensureQueryData(
      projectQueryOptions(projectId),
    )
    const orgId = project?.teamId

    await Promise.all([
      queryClient.ensureQueryData(siteQueryOptions(projectId, siteId)),
      orgId
        ? queryClient.ensureQueryData(organizationPlanQueryOptions(orgId))
        : Promise.resolve(),
      orgId
        ? queryClient.ensureQueryData(
            organizationDomainsQueryOptions(
              orgId,
              0,
              1,
              undefined,
              undefined,
              DOMAINS_DEFAULT_SORT_BY,
              DOMAINS_DEFAULT_SORT_ORDER,
            ),
          )
        : Promise.resolve(),
    ])
  },
  component: BuySiteDomainPage,
})

function BuySiteDomainPage() {
  const { projectId, siteId } = Route.useParams()
  const routeSearch = Route.useSearch()
  return (
    <BuyDomainForResourceWizard
      resourceType="site"
      fallbackPath={`/projects/${projectId}/sites/${siteId}/domains`}
      routeSearch={routeSearch}
    />
  )
}
