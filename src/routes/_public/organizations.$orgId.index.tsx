import { createFileRoute } from '@tanstack/react-router'
import {
  organizationMembershipsQueryOptions,
  organizationsQueryOptions,
  activeProjectsQueryOptions,
  consoleTeamQueryOptions,
  pinnedProjectsQueryOptions,
} from '@/lib/react-query/hooks'
import { parsePinnedProjectIds } from '@/lib/team-prefs-keys'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/organizations/$orgId/')({
  head: () => ({ meta: [{ title: pageTitle('Organization') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') {
      return
    }

    const { orgId } = params
    const { queryClient } = context

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
            0,
            DEFAULT_PAGE_SIZE,
            '',
            pinnedIds,
          ),
        ),
        queryClient.ensureQueryData(
          organizationMembershipsQueryOptions(orgId, 0, DEFAULT_PAGE_SIZE, ''),
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
