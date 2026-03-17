import { createFileRoute } from '@tanstack/react-router'
import {
  organizationMembershipsQueryOptions,
  organizationsQueryOptions,
  activeProjectsQueryOptions,
  consoleTeamQueryOptions,
  pinnedProjectsQueryOptions,
} from '@/lib/react-query/hooks'
import { parsePinnedProjectIds } from '@/lib/team-prefs-keys'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
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
    const url = new URL(
      location.pathname + (location.search ?? ''),
      'http://localhost',
    )
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

      await Promise.all([
        queryClient.ensureQueryData(
          activeProjectsQueryOptions(
            orgId,
            projectsPage - 1,
            projectsLimit,
            '',
            pinnedIds,
          ),
        ),
        queryClient.ensureQueryData(
          organizationMembershipsQueryOptions(orgId, 0, GRID_DEFAULT_PAGE_SIZE, ''),
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
