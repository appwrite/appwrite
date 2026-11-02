import { createFileRoute, Outlet } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { listSearchSchema } from '@/lib/table-filters'
import {
  analyticsPropertyQueryOptions,
  fetchProject,
} from '@/lib/react-query/hooks'

/**
 * Property layout: the tabs are child routes, like Sites and Functions.
 *
 *   /analytics/$propertyId           Analytics (index): metrics
 *   /analytics/$propertyId/settings  Settings: the property only
 *
 * This loader fetches what every tab needs (project, then property), so
 * switching tabs never refetches it. Search (`?query=` filters) is declared
 * here so links to the property can carry it.
 */
export const Route = createFileRoute(
  '/_public/projects/$projectId/analytics/$propertyId',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.property?.name ?? 'Property', 'Analytics'),
      },
    ],
  }),
  validateSearch: listSearchSchema,
  // Layout data depends on path params only, not the filters.
  loaderDeps: () => ({}),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, propertyId } = params
    const { queryClient } = context
    if (!projectId || !propertyId) return

    // Project first, so the SDK has the project's region cached.
    await queryClient.ensureQueryData({
      queryKey: ['project', projectId],
      queryFn: () => fetchProject(projectId),
      staleTime: 5 * 60 * 1000,
    })
    // A missing property is handled by each tab's "not found" state.
    const property = await queryClient
      .ensureQueryData(analyticsPropertyQueryOptions(projectId, propertyId))
      .catch(() => undefined)
    return { property }
  },
  component: Outlet,
})
