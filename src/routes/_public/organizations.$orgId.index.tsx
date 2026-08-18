import { createFileRoute } from '@tanstack/react-router'
import {
  organizationMembershipsQueryOptions,
  organizationsQueryOptions,
  activeProjectsQueryOptions,
  consoleTeamQueryOptions,
  pinnedProjectsQueryOptions,
  organizationProjectScopeQueryOptions,
} from '@/lib/react-query/hooks'
import { parsePinnedProjectIds } from '@/lib/team-prefs-keys'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { urlFromRouterLocation } from '@/lib/table-filters'
import { pageTitle } from '@/lib/utils/page-title'

function parseProjectsPage(url: URL): number {
  const p = url.searchParams.get('projectsPage')
  if (p == null || p === '') return 1
  const n = Number(p)
  return Number.isInteger(n) && n >= 1 ? n : 1
}

function parseProjectsLimit(url: URL): number {
  const p = url.searchParams.get('projectsLimit')
  if (p == null || p === '') return GRID_DEFAULT_PAGE_SIZE
  const n = Number(p)
  return Number.isInteger(n) && n >= 1 ? n : GRID_DEFAULT_PAGE_SIZE
}

export const Route = createFileRoute('/_public/organizations/$orgId/')({
  head: () => ({ meta: [{ title: pageTitle('Organization') }] }),
  loader: async ({ params, context, location }) => {
    if (typeof window === 'undefined') {
      return
    }

    const { orgId } = params
    const { queryClient } = context
    const url = urlFromRouterLocation(location)
    const projectsPage = parseProjectsPage(url)
    const projectsLimit = parseProjectsLimit(url)

    await queryClient.ensureQueryData(organizationsQueryOptions())

    if (orgId) {
      const team = await queryClient.ensureQueryData(
        consoleTeamQueryOptions(orgId),
      )
      const teamPrefs = (team as { prefs?: Record<string, unknown> } | null)
        ?.prefs
      const pinnedIds = parsePinnedProjectIds(teamPrefs)

      // Resolved first so the prefetch lands on the key the overview reads.
      const projectScope = await queryClient
        .ensureQueryData(organizationProjectScopeQueryOptions(orgId))
        .catch(() => null)

      await Promise.all([
        queryClient.ensureQueryData(
          activeProjectsQueryOptions(
            orgId,
            projectsPage - 1,
            projectsLimit,
            '',
            pinnedIds,
            projectScope ?? null,
          ),
        ),
        queryClient.ensureQueryData(
          organizationMembershipsQueryOptions(
            orgId,
            0,
            GRID_DEFAULT_PAGE_SIZE,
            '',
          ),
        ),
        ...(pinnedIds.length > 0
          ? [
              queryClient.ensureQueryData(
                pinnedProjectsQueryOptions(orgId, pinnedIds),
              ),
            ]
          : []),
      ])
    }
  },
  component: OrgOverviewIndexPage,
})

// Index route doesn't need to render anything - parent OrgOverview handles the content
function OrgOverviewIndexPage() {
  return null
}
