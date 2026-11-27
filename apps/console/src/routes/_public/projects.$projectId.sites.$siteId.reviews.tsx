import { createFileRoute, redirect } from '@tanstack/react-router'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  consoleVariablesQueryOptions,
  isReviewsEnabled,
  prefetchRepositoryReviews,
  projectQueryOptions,
  siteQueryOptions,
  useProjectSite,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'
import { ResourceReviews } from '@/components/pages/projects/$projectId/reviews/_components/ResourceReviews'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/reviews',
)({
  beforeLoad: ({ params }) => {
    // Feature overrides live in localStorage, so only the client can resolve them.
    if (typeof window === 'undefined') return
    if (!getActiveProfileFeatures().reviews) {
      throw redirect({
        to: '/projects/$projectId/sites/$siteId',
        params,
        replace: true,
      })
    }
  },
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId, siteId } = params
    const { queryClient } = context

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
    const project = await queryClient.ensureQueryData(
      projectQueryOptions(projectId),
    )
    // Self-hosted instances run the review service only when they opt in.
    const [variables, site] = await Promise.all([
      queryClient
        .ensureQueryData(consoleVariablesQueryOptions(project?.region))
        .catch(() => undefined),
      queryClient.ensureQueryData(siteQueryOptions(projectId, siteId)),
    ])
    if (!isReviewsEnabled(variables)) {
      throw redirect({
        to: '/projects/$projectId/sites/$siteId',
        params,
        replace: true,
      })
    }
    await prefetchRepositoryReviews(
      queryClient,
      projectId,
      site.installationId,
      site.providerRepositoryId,
    )
    return { site }
  },
  head: ({ loaderData }) => ({
    meta: [{ title: pageTitle(loaderData?.site?.name ?? 'Site', 'Reviews') }],
  }),
  component: SiteReviewsPage,
})

function SiteReviewsPage() {
  const { projectId, siteId } = Route.useParams()
  const { data: site } = useProjectSite(projectId, siteId)
  if (!site) return null
  return (
    <ResourceReviews
      projectId={projectId}
      resource={{ type: 'site', id: siteId }}
      installationId={site.installationId}
      providerRepositoryId={site.providerRepositoryId}
      providerRootDirectory={site.providerRootDirectory}
    />
  )
}
