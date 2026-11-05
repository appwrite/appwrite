import { createFileRoute, Outlet } from '@tanstack/react-router'
import {
  projectQueryOptions,
  projectDomainsQueryOptions,
  organizationDomainsQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/settings/domains',
)({
  head: () => ({ meta: [{ title: pageTitle('Domains', 'Settings') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId } = params
    const { queryClient } = context

    // Fetch project first (region and teamId needed for domains)
    const projectData = await queryClient.ensureQueryData(
      projectQueryOptions(projectId),
    )

    // Prefetch critical domains data before rendering to prevent layout shift
    await Promise.all([
      queryClient.ensureQueryData(
        projectDomainsQueryOptions(projectId, projectData?.region, ''),
      ),
      projectData?.teamId
        ? queryClient
            .ensureQueryData(
              organizationDomainsQueryOptions(projectData.teamId, 0, 500),
            )
            .catch(() => {
              // Ignore errors - domains API might not be available in self-hosted
            })
        : Promise.resolve(),
    ])
  },
  component: SettingsDomainsLayout,
})

function SettingsDomainsLayout() {
  return <Outlet />
}
