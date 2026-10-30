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
  projectFunctionQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/domains/buy',
)({
  head: () => ({ meta: [{ title: pageTitle('Buy domain', 'Functions') }] }),
  validateSearch: buyDomainSearchSchema,
  beforeLoad: ({ params }) => {
    if (!isCloudProfile()) {
      throw redirect({
        to: '/projects/$projectId/functions/$functionId/domains',
        params: {
          projectId: params.projectId,
          functionId: params.functionId,
        },
        replace: true,
      })
    }
  },
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, functionId } = params
    const { queryClient } = context
    const project = await queryClient.ensureQueryData(
      projectQueryOptions(projectId),
    )
    const orgId = project?.teamId

    await Promise.all([
      queryClient.ensureQueryData(
        projectFunctionQueryOptions(projectId, functionId),
      ),
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
  component: BuyFunctionDomainPage,
})

function BuyFunctionDomainPage() {
  const { projectId, functionId } = Route.useParams()
  const routeSearch = Route.useSearch()
  return (
    <BuyDomainForResourceWizard
      resourceType="function"
      fallbackPath={`/projects/${projectId}/functions/${functionId}/domains`}
      routeSearch={routeSearch}
    />
  )
}
