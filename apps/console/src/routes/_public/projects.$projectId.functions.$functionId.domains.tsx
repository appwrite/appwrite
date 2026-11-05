import { createFileRoute, Outlet } from '@tanstack/react-router'
import {
  projectQueryOptions,
  projectFunctionQueryOptions,
  functionDomainsQueryOptions,
} from '@/lib/react-query/hooks'
import { DOMAINS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/domains',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.function?.name ?? 'Function', 'Functions'),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, functionId } = params
    const { queryClient } = context

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
    await queryClient.ensureQueryData(projectQueryOptions(projectId))

    // Fetch critical data before rendering to prevent layout shifts
    await Promise.all([
      queryClient.ensureQueryData(
        projectFunctionQueryOptions(projectId, functionId),
      ),
      // Fetch first page of proxy rules - blocks navigation until ready
      queryClient.ensureQueryData(
        functionDomainsQueryOptions(
          projectId,
          functionId,
          0,
          DOMAINS_DEFAULT_PAGE_SIZE,
          '',
        ),
      ),
    ])
    const fn = queryClient.getQueryData<{ name?: string }>(
      projectFunctionQueryOptions(projectId, functionId).queryKey,
    )
    return { function: fn }
  },
  component: FunctionDomainsLayout,
})

function FunctionDomainsLayout() {
  return <Outlet />
}
