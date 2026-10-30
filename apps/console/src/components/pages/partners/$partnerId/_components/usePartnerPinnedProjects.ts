import { useMemo } from 'react'
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canPinProjects } from '@/lib/console-access-checks'
import { useT } from '@/lib/i18n/translate'
import {
  mapProjectToListItem,
  organizationProjectScopeQueryOptions,
  pinnedProjectsQueryOptions,
  type ProjectListItem,
  useConsoleTeam,
  useOrganizationScopes,
  useUpdateConsoleTeamPrefs,
} from '@/lib/react-query/hooks'
import {
  buildPinnedProjectIdsPrefs,
  MAX_PINNED_PROJECTS,
  parsePinnedProjectIds,
} from '@/lib/team-prefs-keys'

const EMPTY_PINNED_PROJECTS: ProjectListItem[] = []

export function usePartnerPinnedProjects(
  organizationId: string,
  listHasFilters: boolean,
) {
  const t = useT()
  const queryClient = useQueryClient()
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(organizationId)
  const { data: consoleTeam } = useConsoleTeam(organizationId)
  const teamPrefs = (consoleTeam as { prefs?: Record<string, unknown> } | null)
    ?.prefs
  const allPinnedIds = useMemo(
    () => parsePinnedProjectIds(teamPrefs),
    [teamPrefs],
  )
  const { data: orgProjectScope } = useQuery(
    organizationProjectScopeQueryOptions(organizationId),
  )
  const restrictToProjectIds = orgProjectScope ?? null
  const pinnedIds = useMemo(() => {
    if (!restrictToProjectIds) return allPinnedIds
    const allowed = new Set(restrictToProjectIds)
    const next = allPinnedIds.filter((id) => allowed.has(id))
    if (
      next.length === allPinnedIds.length &&
      next.every((id, index) => id === allPinnedIds[index])
    ) {
      return allPinnedIds
    }
    return next
  }, [allPinnedIds, restrictToProjectIds])

  const updateTeamPrefsMutation = useUpdateConsoleTeamPrefs(organizationId)
  const listExcludePinnedIds = listHasFilters ? undefined : pinnedIds
  const { data: pinnedProjectsData } = useQuery({
    ...pinnedProjectsQueryOptions(organizationId, pinnedIds),
    placeholderData: keepPreviousData,
  })

  const pinnedProjects = useMemo(() => {
    if (listHasFilters) return EMPTY_PINNED_PROJECTS
    if (!pinnedProjectsData?.projects?.length) return EMPTY_PINNED_PROJECTS
    const raw = pinnedProjectsData.projects as Models.Project[]
    const byId = new Map(raw.map((project) => [project.$id, project]))
    const next = pinnedIds
      .map((id) => byId.get(id))
      .filter((project): project is Models.Project => project != null)
      .map(mapProjectToListItem)
    return next.length > 0 ? next : EMPTY_PINNED_PROJECTS
  }, [listHasFilters, pinnedIds, pinnedProjectsData])

  const canPin = canPinProjects(access, features)
  const canPinMore = pinnedIds.length < MAX_PINNED_PROJECTS

  const handlePinProject = (projectId: string) => {
    if (!canPin) return
    updateTeamPrefsMutation.mutate(
      (freshPrefs) => {
        const currentPinnedIds = parsePinnedProjectIds(freshPrefs)
        if (
          currentPinnedIds.length >= MAX_PINNED_PROJECTS &&
          !currentPinnedIds.includes(projectId)
        ) {
          throw new Error(t('You can pin up to 6 projects'))
        }
        const next = currentPinnedIds.includes(projectId)
          ? currentPinnedIds.filter((id) => id !== projectId)
          : [...currentPinnedIds, projectId].slice(0, MAX_PINNED_PROJECTS)
        return buildPinnedProjectIdsPrefs(next)
      },
      {
        onSuccess: (prefs) => {
          queryClient.invalidateQueries({ queryKey: ['projects'] })
          const nextIds = parsePinnedProjectIds(
            prefs as Record<string, unknown> | undefined,
          )
          toast.success(
            nextIds.includes(projectId)
              ? t('Project pinned')
              : t('Project unpinned'),
          )
        },
        onError: (error) => {
          const message = error instanceof Error ? error.message : undefined
          toast.error(
            message?.startsWith('You can pin up to')
              ? message
              : t('Failed to update pinned projects'),
          )
        },
      },
    )
  }

  return {
    pinnedIds,
    pinnedProjects,
    listExcludePinnedIds,
    canPinProjects: canPin,
    canPinMore,
    isPinPending: updateTeamPrefsMutation.isPending,
    handlePinProject,
  }
}
