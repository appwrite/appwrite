import { createFileRoute, redirect } from '@tanstack/react-router'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  consoleVariablesQueryOptions,
  isReviewsEnabled,
  prefetchRepositoryReviews,
  projectFunctionQueryOptions,
  projectQueryOptions,
  useProjectFunction,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'
import { ResourceReviews } from '@/components/pages/projects/$projectId/reviews/_components/ResourceReviews'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/reviews',
)({
  beforeLoad: ({ params }) => {
    // Feature overrides live in localStorage, so only the client can resolve them.
    if (typeof window === 'undefined') return
    if (!getActiveProfileFeatures().reviews) {
      throw redirect({
        to: '/projects/$projectId/functions/$functionId',
        params,
        replace: true,
      })
    }
  },
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId, functionId } = params
    const { queryClient } = context

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
    const project = await queryClient.ensureQueryData(
      projectQueryOptions(projectId),
    )
    // Self-hosted instances run the review service only when they opt in.
    const [variables, func] = await Promise.all([
      queryClient
        .ensureQueryData(consoleVariablesQueryOptions(project?.region))
        .catch(() => undefined),
      queryClient.ensureQueryData(
        projectFunctionQueryOptions(projectId, functionId),
      ),
    ])
    if (!isReviewsEnabled(variables)) {
      throw redirect({
        to: '/projects/$projectId/functions/$functionId',
        params,
        replace: true,
      })
    }
    await prefetchRepositoryReviews(
      queryClient,
      projectId,
      func.installationId,
      func.providerRepositoryId,
    )
    return { function: func }
  },
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.function?.name ?? 'Function', 'Reviews'),
      },
    ],
  }),
  component: FunctionReviewsPage,
})

function FunctionReviewsPage() {
  const { projectId, functionId } = Route.useParams()
  const { data: func } = useProjectFunction(projectId, functionId)
  if (!func) return null
  return (
    <ResourceReviews
      projectId={projectId}
      resource={{ type: 'function', id: functionId }}
      installationId={func.installationId}
      providerRepositoryId={func.providerRepositoryId}
      providerRootDirectory={func.providerRootDirectory}
    />
  )
}
