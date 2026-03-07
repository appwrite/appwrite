import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/projects/$projectId/sites/Deployments'
import {
  siteQueryOptions,
  siteDeploymentsQueryOptions,
  siteDeploymentQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { fetchVcsInstallations } from '@/lib/react-query/hooks/vcs'
import { Query } from '@appwrite.io/console'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { pageTitle } from '@/lib/utils/page-title'

const searchSchema = z.object({
  page: z.coerce.number().int().min(1).optional().catch(undefined),
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/deployments/',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(
          loaderData?.site?.name ?? loaderData?.site?.resourceId ?? 'Site',
          'Sites',
        ),
      },
    ],
  }),
  validateSearch: searchSchema,
  loader: async ({ params, context, location }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, siteId } = params
    const { queryClient } = context

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
    await queryClient.ensureQueryData(projectQueryOptions(projectId))

    // Parse page from URL search params
    const urlParams = new URLSearchParams(location.search)
    const pageParam = urlParams.get('page')
    const page = pageParam ? Math.max(1, parseInt(pageParam, 10)) : 1
    const pageIndex = page - 1

    // Fetch site - blocks navigation until ready
    const site = await queryClient.ensureQueryData(
      siteQueryOptions(projectId, siteId),
    )

    // Fetch critical data before rendering to prevent layout shifts
    await Promise.all([
      // Fetch deployments for the requested page - blocks navigation until ready
      queryClient.ensureQueryData(
        siteDeploymentsQueryOptions(
          projectId,
          siteId,
          pageIndex,
          DEFAULT_PAGE_SIZE,
          [
            Query.select([
              'buildSize',
              'sourceSize',
              'totalSize',
              'buildDuration',
              'status',
              'type',
              'resourceId',
              'providerRepositoryUrl',
              'providerRepositoryOwner',
              'providerRepositoryName',
              'providerBranchUrl',
              'providerBranch',
              'providerCommitMessage',
              'providerCommitHash',
              'providerCommitUrl',
            ]),
          ],
        ),
      ),
      // Fetch active deployment if available
      site.deploymentId
        ? queryClient.ensureQueryData(
            siteDeploymentQueryOptions(projectId, siteId, site.deploymentId),
          )
        : Promise.resolve(),
      // Fetch VCS installations (for deployment actions)
      queryClient.ensureQueryData({
        queryKey: ['vcs', 'installations', projectId, 0, 10],
        queryFn: () => fetchVcsInstallations(projectId, 0, 10),
        staleTime: 5 * 60 * 1000,
      }),
    ])
  },
  component: SiteDeploymentsPage,
})

function SiteDeploymentsPage() {
  return <View />
}
