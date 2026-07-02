import { useState, useMemo, useEffect, useRef, useCallback, useLayoutEffect } from 'react'
import { cn } from '@/lib/utils'
import { ChevronDown, Check, Pin, Plus, Search, X } from 'lucide-react'
import { Link } from '@tanstack/react-router'
import {
  type Project,
  type Team,
  type Organization,
} from '@/lib/utils/mock-data'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog'
import { Badge } from '@/components/ui/badge'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import {
  useTeams,
  useProject,
  useProjectsForTeamInfinite,
  useConsoleTeam,
  fetchActiveProjects,
  pinnedProjectsQueryOptions,
  consoleTeamQueryOptions,
  useOrganizationFailedInvoicePresence,
  isOrganizationBillingReadonlyStatus,
} from '@/lib/react-query/hooks'
import { FailedInvoiceWarningIcon } from '@/components/global/shared/FailedInvoiceWarningIcon'
import { ProjectSelectorPlanBadge } from '@/components/pages/projects/$projectId/shared/ProjectSelectorPlanBadge'
import { parsePinnedProjectIds } from '@/lib/team-prefs-keys'
import {
  useQuery,
  useQueryClient,
  keepPreviousData,
} from '@tanstack/react-query'
import { getPlanBadgeColor, getPlanDisplayName } from '@/lib/utils/plan-badge'
import { truncateMiddle } from '@/lib/utils'
import {
  formatProjectNameForDisplay,
  PROJECT_NAME_DISPLAY_MAX_COMPACT,
  PROJECT_NAME_DISPLAY_MAX_SELECTOR,
} from '@/lib/react-query/hooks'
import { CreateProjectDialog } from '@/components/pages/organizations/$orgId/overview/CreateProjectDialog'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useNavigate } from '@tanstack/react-router'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { openCreateOrganizationFlow } from '@/lib/open-create-organization-flow'
import { useT } from '@/lib/i18n/translate'
import {
  INITIAL_LOADER_SHELL_GATE,
  resetInitialLoaderShellGate,
  setInitialLoaderShellGate,
} from '@/lib/initial-loader/shell-gates'

const TEAM_PROJECTS_PREFETCH_STALE_MS = 5 * 60 * 1000

function stubTeamFromProject(project: Pick<Project, 'teamId'>): Team {
  return {
    $id: project.teamId,
    name: '',
    color: '',
    members: 0,
    orgId: project.teamId,
  }
}

function ProjectSelectorTriggerSkeleton({
  className,
  isMobile,
  supportsMultiTenancy,
  isCloud,
}: {
  className?: string
  isMobile?: boolean
  supportsMultiTenancy: boolean
  isCloud: boolean
}) {
  return (
    <div
      className={cn(
        'flex max-w-full min-w-0 items-center gap-2 rounded-md px-2 py-1.5',
        isMobile
          ? 'h-auto w-full border border-border bg-background px-2.5 py-2'
          : 'h-9',
        className,
      )}
      aria-hidden
    >
      <div className="h-6 w-6 shrink-0 animate-pulse rounded-full bg-muted" />
      <div className="min-w-0 flex-1 space-y-1">
        <div className="h-4 w-32 max-w-full animate-pulse rounded bg-muted" />
        {supportsMultiTenancy && isMobile ? (
          <div className="h-3 w-24 animate-pulse rounded bg-muted" />
        ) : null}
      </div>
      {isCloud ? (
        <div className="h-5 w-[4.25rem] shrink-0 animate-pulse rounded border bg-muted" />
      ) : null}
      <div className="h-3.5 w-3.5 shrink-0 animate-pulse rounded bg-muted" />
    </div>
  )
}

function ProjectSelectorPlanBadgeSlot({
  isCloud,
  org,
  billingStress,
}: {
  isCloud: boolean
  org: Organization | null
  billingStress: boolean
}) {
  if (!isCloud) return null
  if (org) {
    return (
      <ProjectSelectorPlanBadge
        plan={org.plan}
        billingStress={billingStress}
        upcomingDowngrade={!!org.billingPlanDowngrade}
      />
    )
  }
  return (
    <span
      aria-hidden
      className="invisible shrink-0 rounded border px-1.5 py-0.5 text-[10px] font-medium capitalize"
    >
      Enterprise
    </span>
  )
}

function getProjectsInfiniteNextPageParam(
  lastPage: { total?: number; projects?: unknown[] | null },
  allPages: Array<{ projects?: unknown[] | null }>,
) {
  const loadedCount = allPages.reduce(
    (sum, page) => sum + (page.projects?.length || 0),
    0,
  )
  if (lastPage.total && loadedCount < lastPage.total) {
    return allPages.length
  }
  return undefined
}

interface ProjectSelectorProps {
  className?: string
  collapsed?: boolean
  projectId?: string
  isMobile?: boolean
  /** When set (e.g. org overview layout), matches header create-org behavior */
  onCreateOrganization?: () => void
}

export function ProjectSelector({
  className,
  collapsed,
  projectId,
  isMobile,
  onCreateOrganization,
}: ProjectSelectorProps) {
  const t = useT()
  const { features, isCloud } = useConsoleProfile()
  const supportsMultiTenancy = features.multiTenancy
  const navigate = useNavigate()
  const { account } = useAuth()
  const [open, setOpen] = useState(false)
  const [createProjectDialogOpen, setCreateProjectDialogOpen] = useState(false)

  // Fetch organizations and teams (for team selector)
  // Note: We only fetch teams/organizations here, NOT all projects
  const { teams, organizations, isLoading: orgsLoading } = useTeams()

  // Fetch current project separately by ID
  const { project: currentProject, isLoading: currentProjectLoading } =
    useProject(projectId)

  const { data: routeFailedInvoicePresence, isLoading: invoicePresenceLoading } =
    useOrganizationFailedInvoicePresence(
      projectId ? currentProject?.teamId : undefined,
    )
  const billingFailureTeamId =
    routeFailedInvoicePresence?.hasFailedInvoice && currentProject?.teamId
      ? currentProject.teamId
      : null

  const billingOrgReadonly = useMemo(() => {
    if (!billingFailureTeamId) return false
    const org = organizations.find((o) => o.$id === billingFailureTeamId)
    return isOrganizationBillingReadonlyStatus(org?.status)
  }, [billingFailureTeamId, organizations])

  // Infinite scroll state for projects
  const [projectSearch, setProjectSearch] = useState('')
  const projectsPageSize = 25 // Fixed page size for infinite scroll

  // Find initial team from current project
  const initialTeam = useMemo(() => {
    if (currentProject && teams.length > 0) {
      return teams.find((t) => t.$id === currentProject.teamId) || teams[0]
    }
    return teams[0] || null
  }, [currentProject, teams])

  const [selectedTeam, setSelectedTeam] = useState<Team | null>(null)
  const [selectedProject, setSelectedProject] = useState<Project | null>(null)
  const [teamSearch, setTeamSearch] = useState('')

  // Sync UI with prefetched route data on first paint (state starts null; effects run after paint)
  const resolvedProject = useMemo(() => {
    const candidate = selectedProject ?? currentProject ?? null
    if (!candidate || !projectId) return null
    if (candidate.$id !== projectId) {
      return currentProject?.$id === projectId ? currentProject : null
    }
    return candidate
  }, [selectedProject, currentProject, projectId])

  const resolvedTeam = useMemo(() => {
    const fromSelection = selectedTeam ?? initialTeam
    if (fromSelection) return fromSelection
    if (resolvedProject?.teamId) {
      return stubTeamFromProject(resolvedProject)
    }
    return null
  }, [selectedTeam, initialTeam, resolvedProject?.teamId])

  // Note: Infinite query automatically resets when team or search changes

  // Initialize selected team when data loads
  useEffect(() => {
    if (initialTeam && !selectedTeam) {
      setSelectedTeam(initialTeam)
    }
  }, [initialTeam, selectedTeam])

  // Initialize selected project from current project
  useEffect(() => {
    if (currentProject && !selectedProject) {
      setSelectedProject(currentProject)
    }
  }, [currentProject, selectedProject])

  // Sync selected project when projectId prop changes
  useEffect(() => {
    if (currentProject) {
      setSelectedProject(currentProject)
      const team = teams.find((t) => t.$id === currentProject.teamId)
      if (team) {
        setSelectedTeam(team)
      }
    }
  }, [currentProject, teams])

  // Reset project search when team (organization) changes
  useEffect(() => {
    setProjectSearch('')
  }, [resolvedTeam?.$id])

  // Pinned projects for selected team (from team prefs)
  const { data: consoleTeam } = useConsoleTeam(resolvedTeam?.$id)
  const pinnedIds = useMemo(
    () => parsePinnedProjectIds(consoleTeam?.prefs),
    [consoleTeam?.prefs],
  )
  const { data: pinnedProjectsData, isPlaceholderData: isPinnedPlaceholder } =
    useQuery({
      ...pinnedProjectsQueryOptions(resolvedTeam?.$id ?? null, pinnedIds),
      placeholderData: keepPreviousData,
    })

  const projectSearchActive = Boolean(projectSearch.trim())
  const listExcludePinnedIds = projectSearchActive ? undefined : pinnedIds

  // Fetch projects for selected team with infinite scroll (exclude pinned only when not searching)
  const {
    projects: paginatedProjects,
    total: infiniteTotal,
    isFetchingNextPage,
    hasNextPage,
    fetchNextPage,
    isPlaceholderData: isInfinitePlaceholder,
  } = useProjectsForTeamInfinite(
    resolvedTeam?.$id,
    projectsPageSize,
    projectSearch,
    listExcludePinnedIds,
  )

  const queryClient = useQueryClient()

  const prefetchTeamProjects = useCallback(
    (teamId: string) => {
      if (!teamId || teamId === resolvedTeam?.$id) return

      void (async () => {
        let excludeIds: string[] = []
        try {
          const teamData = await queryClient.ensureQueryData({
            ...consoleTeamQueryOptions(teamId),
            staleTime: TEAM_PROJECTS_PREFETCH_STALE_MS,
          })
          excludeIds = parsePinnedProjectIds(
            (teamData as { prefs?: Record<string, unknown> })?.prefs,
          )
        } catch {
          return
        }
        const excludeKey =
          excludeIds.length > 0 ? excludeIds.slice().sort().join(',') : ''
        const infiniteQueryKey = [
          'projects',
          'team',
          'infinite',
          teamId,
          projectsPageSize,
          '',
          excludeKey,
        ]
        const pinnedOptions = pinnedProjectsQueryOptions(teamId, excludeIds)

        try {
          await Promise.all([
            queryClient.prefetchQuery({
              ...pinnedOptions,
              staleTime: TEAM_PROJECTS_PREFETCH_STALE_MS,
            }),
            queryClient.prefetchInfiniteQuery({
              queryKey: infiniteQueryKey,
              queryFn: ({ pageParam = 0 }) =>
                fetchActiveProjects(
                  teamId,
                  pageParam,
                  projectsPageSize,
                  '',
                  excludeIds,
                ),
              initialPageParam: 0,
              staleTime: TEAM_PROJECTS_PREFETCH_STALE_MS,
              getNextPageParam: getProjectsInfiniteNextPageParam,
            }),
          ])
        } catch {
          // ignore prefetch failures
        }
      })()
    },
    [queryClient, projectsPageSize, resolvedTeam?.$id],
  )

  // Handle team selection - ensure data is ready before switching (loads team once, then pinned + unpinned in parallel)
  const handleSelectTeam = useCallback(
    async (team: Team) => {
      if (team.$id === resolvedTeam?.$id) return

      let excludeIds: string[] = []
      try {
        const teamData = await queryClient.ensureQueryData({
          ...consoleTeamQueryOptions(team.$id),
          staleTime: TEAM_PROJECTS_PREFETCH_STALE_MS,
        })
        excludeIds = parsePinnedProjectIds(
          (teamData as { prefs?: Record<string, unknown> })?.prefs,
        )
      } catch {
        // use empty exclude if team prefs fail
      }
      const excludeKey =
        excludeIds.length > 0 ? excludeIds.slice().sort().join(',') : ''
      const infiniteQueryKey = [
        'projects',
        'team',
        'infinite',
        team.$id,
        projectsPageSize,
        '',
        excludeKey,
      ]
      const pinnedOptions = pinnedProjectsQueryOptions(team.$id, excludeIds)
      const hasInfiniteCache = queryClient.getQueryData(infiniteQueryKey)
      const hasPinnedCache = queryClient.getQueryData(pinnedOptions.queryKey)

      if (hasInfiniteCache && hasPinnedCache) {
        setSelectedTeam(team)
      } else {
        await Promise.all([
          hasPinnedCache
            ? Promise.resolve()
            : queryClient.fetchQuery({
                ...pinnedOptions,
                staleTime: TEAM_PROJECTS_PREFETCH_STALE_MS,
              }),
          hasInfiniteCache
            ? Promise.resolve()
            : queryClient.fetchInfiniteQuery({
                queryKey: infiniteQueryKey,
                queryFn: ({ pageParam = 0 }) =>
                  fetchActiveProjects(
                    team.$id,
                    pageParam,
                    projectsPageSize,
                    '',
                    excludeIds,
                  ),
                initialPageParam: 0,
                staleTime: TEAM_PROJECTS_PREFETCH_STALE_MS,
                getNextPageParam: getProjectsInfiniteNextPageParam,
              }),
        ])
        setSelectedTeam(team)
      }
    },
    [queryClient, resolvedTeam?.$id, projectsPageSize],
  )

  // Find the team for the current project (for display in trigger)
  const currentProjectTeam = useMemo(() => {
    if (!currentProject || !teams.length) return null
    return teams.find((t) => t.$id === currentProject.teamId) || null
  }, [currentProject, teams])

  // Find the organization for the current project's team (for display in trigger)
  const currentProjectOrg = useMemo(() => {
    if (!currentProjectTeam) return null
    return (
      organizations.find((org) => org.$id === currentProjectTeam.orgId) || null
    )
  }, [currentProjectTeam, organizations])

  const orgDisplayName = currentProjectTeam?.name || resolvedTeam?.name || ''

  const lastFullProjectsCountRef = useRef(0)
  if (!projectSearchActive && resolvedTeam?.$id) {
    lastFullProjectsCountRef.current = pinnedIds.length + (infiniteTotal ?? 0)
  }
  // Plan limit uses full org count; while searching, `infiniteTotal` is search-scoped
  const projectsCount = projectSearchActive
    ? lastFullProjectsCountRef.current
    : pinnedIds.length + (infiniteTotal ?? 0)

  const filteredTeams = useMemo(() => {
    if (!teams.length) return []
    if (!teamSearch) return teams
    return teams.filter((t) =>
      t.name.toLowerCase().includes(teamSearch.toLowerCase()),
    )
  }, [teamSearch, teams])

  // Pinned projects in Project shape (order from pinnedIds); only prepended when not searching
  const pinnedProjects = useMemo(() => {
    if (!pinnedProjectsData?.projects?.length || !resolvedTeam) return []
    const raw = pinnedProjectsData.projects as Array<{
      $id: string
      name: string
      teamId: string
      region?: string
      $createdAt?: string
      status?: string
    }>
    const byId = new Map(raw.map((p) => [p.$id, p]))
    return pinnedIds
      .map((id) => byId.get(id))
      .filter((p): p is NonNullable<typeof p> => p != null)
      .map((p) => ({
        $id: p.$id,
        name: p.name,
        teamId: p.teamId,
        region: p.region || 'unknown',
        createdAt: p.$createdAt || new Date().toISOString(),
        icon: p.name.charAt(0).toUpperCase(),
        archived: p.status === 'archived',
      })) as Project[]
  }, [pinnedProjectsData, pinnedIds, resolvedTeam])

  // Combine: current (if same team), then pinned (idle only), then paginated (excluding current)
  const displayProjects = useMemo(() => {
    if (!resolvedTeam) return []

    const otherProjects = paginatedProjects.filter((p) => p.$id !== projectId)
    const pinnedForList = projectSearchActive
      ? []
      : pinnedProjects.filter((p) => p.$id !== projectId)

    if (currentProject && currentProject.teamId === resolvedTeam.$id) {
      return [currentProject, ...pinnedForList, ...otherProjects]
    }
    return [...pinnedForList, ...otherProjects]
  }, [
    currentProject,
    pinnedProjects,
    paginatedProjects,
    projectId,
    projectSearchActive,
    resolvedTeam,
  ])

  // Show list only when both pinned and unpinned have real data (not placeholder).
  // Keep showing the previous combined list until both finish loading after org switch.
  const lastStableDisplayRef = useRef<Project[]>([])
  const lastStablePinnedIdsRef = useRef<string[]>([])
  const bothQueriesReady = !isPinnedPlaceholder && !isInfinitePlaceholder
  const stableDisplayProjects =
    bothQueriesReady && resolvedTeam
      ? (() => {
          lastStableDisplayRef.current = displayProjects
          lastStablePinnedIdsRef.current = pinnedIds
          return displayProjects
        })()
      : lastStableDisplayRef.current
  const stablePinnedIds =
    bothQueriesReady && resolvedTeam
      ? pinnedIds
      : lastStablePinnedIdsRef.current

  const handleSelectProject = (project: Project, event?: React.MouseEvent) => {
    // Only update state if it's a regular click (not Ctrl/Cmd click for new tab)
    // Middle-click and right-click "Open in new tab" won't trigger onClick, so we're safe
    if (event && (event.ctrlKey || event.metaKey || event.shiftKey)) {
      return // Let the browser handle the link naturally (opens in new tab/window)
    }
    setSelectedProject(project)
    // Update selected team to match the project's team when a project is clicked
    const projectTeam = teams.find((t) => t.$id === project.teamId)
    if (projectTeam) {
      setSelectedTeam(projectTeam)
    }
    setOpen(false)
  }

  const handleCreateOrganization = useCallback(() => {
    if (!supportsMultiTenancy) return
    const prefs = (account as { prefs?: Record<string, unknown> } | undefined)
      ?.prefs
    const orgId =
      currentProject?.teamId || (prefs?.organization as string | undefined)
    openCreateOrganizationFlow(navigate, {
      onCreateOrganization,
      orgId,
    })
    setOpen(false)
  }, [
    account,
    currentProject?.teamId,
    navigate,
    onCreateOrganization,
    supportsMultiTenancy,
  ])

  // Only skeleton when the current project is not available yet (orgs can hydrate after first paint)
  const isProjectSelectorLoading = !resolvedProject && currentProjectLoading

  const isProjectSelectorShellReady = useMemo(() => {
    if (!projectId) return true
    if (!resolvedProject || resolvedProject.$id !== projectId) return false
    if (currentProjectLoading) return false
    if (invoicePresenceLoading) return false

    if (supportsMultiTenancy) {
      if (orgsLoading) return false
      if (!currentProjectTeam?.name) return false
      if (isCloud && !currentProjectOrg) return false
    }

    return true
  }, [
    projectId,
    resolvedProject,
    currentProjectLoading,
    invoicePresenceLoading,
    supportsMultiTenancy,
    orgsLoading,
    currentProjectTeam?.name,
    isCloud,
    currentProjectOrg,
  ])

  useLayoutEffect(() => {
    if (!projectId) {
      resetInitialLoaderShellGate(INITIAL_LOADER_SHELL_GATE.projectSelector)
      return
    }

    setInitialLoaderShellGate(
      INITIAL_LOADER_SHELL_GATE.projectSelector,
      isProjectSelectorShellReady,
    )
  }, [projectId, isProjectSelectorShellReady])

  useEffect(() => {
    return () => {
      resetInitialLoaderShellGate(INITIAL_LOADER_SHELL_GATE.projectSelector)
    }
  }, [])

  if (isProjectSelectorLoading) {
    return (
      <ProjectSelectorTriggerSkeleton
        className={className}
        isMobile={isMobile}
        supportsMultiTenancy={supportsMultiTenancy}
        isCloud={isCloud}
      />
    )
  }

  if (!resolvedProject || !resolvedTeam) {
    return null
  }

  if (collapsed) {
    return (
      <>
        <Popover open={open} onOpenChange={setOpen}>
          <PopoverTrigger asChild>
            <button
              className={cn(
                'flex h-8 w-8 items-center justify-center rounded-md bg-accent text-[11px] font-medium text-muted-foreground transition-colors hover:bg-accent/80 cursor-pointer',
                className,
              )}
            >
              {resolvedProject.name.charAt(0).toUpperCase()}
            </button>
          </PopoverTrigger>
          <PopoverContent
            side="right"
            align="start"
            sideOffset={12}
            className={cn(
              'border-border bg-popover p-0',
              supportsMultiTenancy ? 'w-[520px]' : 'w-[320px]',
            )}
          >
            <ProjectSelectorContent
              selectedTeam={resolvedTeam}
              onSelectTeam={handleSelectTeam}
              selectedProject={resolvedProject}
              handleSelectProject={handleSelectProject}
              teamSearch={teamSearch}
              setTeamSearch={setTeamSearch}
              projectSearch={projectSearch}
              setProjectSearch={setProjectSearch}
              filteredTeams={filteredTeams}
              displayProjects={stableDisplayProjects}
              pinnedProjectIds={stablePinnedIds}
              isFetchingNextPage={isFetchingNextPage}
              hasNextPage={hasNextPage}
              fetchNextPage={fetchNextPage}
              organizations={organizations}
              isCloud={isCloud}
              supportsMultiTenancy={supportsMultiTenancy}
              currentProjectId={projectId}
              billingFailureTeamId={billingFailureTeamId}
              billingOrgReadonly={billingOrgReadonly}
              onCreateProject={() => setCreateProjectDialogOpen(true)}
              onCreateOrganization={handleCreateOrganization}
              prefetchTeamProjects={prefetchTeamProjects}
            />
          </PopoverContent>
        </Popover>

        <CreateProjectDialog
          open={createProjectDialogOpen}
          onOpenChange={setCreateProjectDialogOpen}
          teamId={resolvedTeam.$id}
          currentProjectsCount={projectsCount}
        />
      </>
    )
  }

  // Mobile: use fullscreen Dialog
  if (isMobile) {
    return (
      <>
        <button
          onClick={() => setOpen(true)}
          className={cn(
            'flex w-full items-center gap-2 overflow-visible rounded-md border border-border bg-background px-2.5 py-2 text-start transition-colors hover:bg-accent cursor-pointer',
            className,
          )}
        >
          <InitialsAvatar name={resolvedProject.name} size="sm" />
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-1.5">
              <p
                className="min-w-0 flex-1 truncate text-[13px] font-medium text-foreground"
                title={resolvedProject.name}
              >
                {formatProjectNameForDisplay(
                  resolvedProject.name,
                  PROJECT_NAME_DISPLAY_MAX_SELECTOR,
                )}
              </p>
            </div>
            {supportsMultiTenancy ? (
              <p
                className={cn(
                  'truncate text-[11px] text-muted-foreground',
                  !orgDisplayName && 'invisible',
                )}
                title={orgDisplayName || undefined}
              >
                {truncateMiddle(orgDisplayName || 'Organization', 30)}
              </p>
            ) : null}
          </div>
          <ProjectSelectorPlanBadgeSlot
            isCloud={isCloud}
            org={currentProjectOrg}
            billingStress={!!billingFailureTeamId}
          />
          <ChevronDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
        </button>

        <Dialog open={open} onOpenChange={setOpen}>
          <DialogContent
            className="fixed inset-0 start-0 top-0 z-[132] flex h-[100dvh] max-h-none w-[100dvw] max-w-none translate-x-0 translate-y-0 flex-col gap-0 rounded-none border-0 p-0 sm:max-w-none sm:rounded-none"
            overlayClassName="z-[131]"
            showCloseButton={false}
          >
            <DialogTitle className="sr-only">{t('Select project')}</DialogTitle>
            {/* Header */}
            <div className="flex items-center justify-between border-b border-border px-4 py-3">
              <h2 className="text-[15px] font-semibold text-foreground">
                Select project
              </h2>
              <button
                onClick={() => setOpen(false)}
                className="flex h-8 w-8 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
              >
                <X className="h-5 w-5" />
              </button>
            </div>

            {/* Content */}
            <MobileProjectSelectorContent
              selectedTeam={resolvedTeam}
              onSelectTeam={handleSelectTeam}
              selectedProject={resolvedProject}
              handleSelectProject={handleSelectProject}
              teamSearch={teamSearch}
              setTeamSearch={setTeamSearch}
              projectSearch={projectSearch}
              setProjectSearch={setProjectSearch}
              filteredTeams={filteredTeams}
              displayProjects={stableDisplayProjects}
              pinnedProjectIds={stablePinnedIds}
              isFetchingNextPage={isFetchingNextPage}
              hasNextPage={hasNextPage}
              fetchNextPage={fetchNextPage}
              organizations={organizations}
              isCloud={isCloud}
              supportsMultiTenancy={supportsMultiTenancy}
              currentProjectId={projectId}
              billingFailureTeamId={billingFailureTeamId}
              billingOrgReadonly={billingOrgReadonly}
              onCreateProject={() => setCreateProjectDialogOpen(true)}
              onCreateOrganization={handleCreateOrganization}
              prefetchTeamProjects={prefetchTeamProjects}
            />
          </DialogContent>
        </Dialog>

        <CreateProjectDialog
          open={createProjectDialogOpen}
          onOpenChange={setCreateProjectDialogOpen}
          teamId={resolvedTeam.$id}
          currentProjectsCount={projectsCount}
        />
      </>
    )
  }

  return (
    <>
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <button
            className={cn(
              'flex h-9 max-w-full min-w-0 items-center gap-2 overflow-visible rounded-md px-2 py-1.5 text-start transition-colors hover:bg-accent cursor-pointer',
              className,
            )}
          >
            <InitialsAvatar name={resolvedProject.name} size="sm" />
            <div className="min-w-0 flex flex-1 items-center gap-2 overflow-visible">
              <p
                className="min-w-0 flex-1 truncate text-[13px] font-medium text-foreground"
                title={
                  supportsMultiTenancy && orgDisplayName
                    ? `${orgDisplayName} / ${resolvedProject.name}`
                    : resolvedProject.name
                }
              >
                {supportsMultiTenancy ? (
                  <>
                    <span
                      className={cn('shrink-0', !orgDisplayName && 'invisible')}
                    >
                      {truncateMiddle(orgDisplayName || 'Organization', 20)}
                    </span>{' '}
                    /{' '}
                    {formatProjectNameForDisplay(
                      resolvedProject.name,
                      PROJECT_NAME_DISPLAY_MAX_COMPACT,
                    )}
                  </>
                ) : (
                  formatProjectNameForDisplay(
                    resolvedProject.name,
                    PROJECT_NAME_DISPLAY_MAX_SELECTOR,
                  )
                )}
              </p>
              <ProjectSelectorPlanBadgeSlot
                isCloud={isCloud}
                org={currentProjectOrg}
                billingStress={!!billingFailureTeamId}
              />
            </div>
            <ChevronDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
          </button>
        </PopoverTrigger>
        <PopoverContent
          side="bottom"
          align="start"
          sideOffset={8}
          className={cn(
            'border-border bg-popover p-0',
            supportsMultiTenancy ? 'w-[520px]' : 'w-[320px]',
          )}
        >
          <ProjectSelectorContent
            selectedTeam={resolvedTeam}
            onSelectTeam={handleSelectTeam}
            selectedProject={resolvedProject}
            handleSelectProject={handleSelectProject}
            teamSearch={teamSearch}
            setTeamSearch={setTeamSearch}
            projectSearch={projectSearch}
            setProjectSearch={setProjectSearch}
            filteredTeams={filteredTeams}
            displayProjects={stableDisplayProjects}
            pinnedProjectIds={stablePinnedIds}
            isFetchingNextPage={isFetchingNextPage}
            hasNextPage={hasNextPage}
            fetchNextPage={fetchNextPage}
            organizations={organizations}
            isCloud={isCloud}
            supportsMultiTenancy={supportsMultiTenancy}
            currentProjectId={projectId}
            billingFailureTeamId={billingFailureTeamId}
            billingOrgReadonly={billingOrgReadonly}
            onCreateProject={() => setCreateProjectDialogOpen(true)}
            onCreateOrganization={handleCreateOrganization}
            prefetchTeamProjects={prefetchTeamProjects}
          />
        </PopoverContent>
      </Popover>

      {/* Create Project Dialog */}
      <CreateProjectDialog
        open={createProjectDialogOpen}
        onOpenChange={setCreateProjectDialogOpen}
        teamId={resolvedTeam.$id}
        currentProjectsCount={projectsCount}
      />
    </>
  )
}

interface ProjectSelectorContentProps {
  selectedTeam: Team | null
  onSelectTeam: (team: Team) => Promise<void>
  selectedProject: Project | null
  handleSelectProject: (project: Project, event?: React.MouseEvent) => void
  teamSearch: string
  setTeamSearch: (search: string) => void
  projectSearch: string
  setProjectSearch: (search: string) => void
  filteredTeams: Team[]
  displayProjects: Project[]
  pinnedProjectIds: string[]
  isFetchingNextPage: boolean
  hasNextPage: boolean
  fetchNextPage: () => void
  organizations: Organization[]
  isCloud: boolean
  supportsMultiTenancy: boolean
  currentProjectId?: string
  /** Team id for the open project when that org has a failed invoice; list rows match on project.teamId */
  billingFailureTeamId: string | null
  /** That org's billing status is read-only (stronger invoice warning copy) */
  billingOrgReadonly: boolean
  onCreateProject: () => void
  onCreateOrganization: () => void
  /** Preload pinned + paginated projects when the user hovers an organization row */
  prefetchTeamProjects: (teamId: string) => void
}

function ProjectSelectorContent({
  selectedTeam,
  onSelectTeam,
  selectedProject,
  handleSelectProject,
  teamSearch,
  setTeamSearch,
  projectSearch,
  setProjectSearch,
  filteredTeams,
  displayProjects,
  pinnedProjectIds,
  isFetchingNextPage,
  hasNextPage,
  fetchNextPage,
  organizations,
  isCloud,
  supportsMultiTenancy,
  currentProjectId,
  billingFailureTeamId,
  billingOrgReadonly,
  onCreateProject,
  onCreateOrganization,
  prefetchTeamProjects,
}: ProjectSelectorContentProps) {
  const t = useT()
  const pinnedSet = useMemo(() => new Set(pinnedProjectIds), [pinnedProjectIds])
  // Ref for the scrollable container
  const projectsScrollRef = useRef<HTMLDivElement>(null)
  // Ref for the sentinel element that triggers loading
  const sentinelRef = useRef<HTMLDivElement>(null)

  // Intersection Observer to detect when user is near bottom
  useEffect(() => {
    const sentinel = sentinelRef.current
    if (!sentinel || !hasNextPage || isFetchingNextPage) return

    const observer = new IntersectionObserver(
      (entries) => {
        const [entry] = entries
        // When sentinel is visible (user scrolled near bottom), load more
        if (entry.isIntersecting && hasNextPage && !isFetchingNextPage) {
          fetchNextPage()
        }
      },
      {
        root: projectsScrollRef.current,
        rootMargin: '200px', // Start loading 200px before reaching bottom
        threshold: 0.1,
      },
    )

    observer.observe(sentinel)

    return () => {
      observer.disconnect()
    }
  }, [hasNextPage, isFetchingNextPage, fetchNextPage])

  if (!selectedTeam || !selectedProject) {
    return (
      <div className="flex h-[300px] items-center justify-center">
        <div className="text-sm text-muted-foreground">{t('Loading...')}</div>
      </div>
    )
  }

  return (
    <div
      className={cn('flex', supportsMultiTenancy && 'divide-x divide-border')}
    >
      {/* Organizations Column */}
      {supportsMultiTenancy && (
        <div className="flex w-1/2 flex-col">
          {/* Organization Search */}
          <div className="flex items-center gap-2 border-b border-border px-3 py-2.5">
            <Search className="h-3.5 w-3.5 text-muted-foreground" />
            <input
              type="text"
              placeholder={t('Find Organization...')}
              value={teamSearch}
              onChange={(e) => setTeamSearch(e.target.value)}
              className="flex-1 bg-transparent text-[13px] text-foreground placeholder:text-muted-foreground focus:outline-none"
            />
          </div>

          {/* Organizations List - scrollable */}
          <div className="min-h-[180px] max-h-[240px] flex-1 overflow-y-auto p-1.5">
            <p className="px-2 py-1.5 text-[11px] font-medium text-muted-foreground">
              Organizations
            </p>
            <div className="space-y-0.5">
              {filteredTeams.length === 0 ? (
                <p className="px-2 py-4 text-center text-[12px] text-muted-foreground">
                  No organizations found
                </p>
              ) : (
                filteredTeams.map((team) => {
                  const teamOrg = organizations.find(
                    (org) => org.$id === team.orgId,
                  )
                  return (
                    <button
                      key={team.$id}
                      type="button"
                      onClick={() => onSelectTeam(team)}
                      onMouseEnter={() => prefetchTeamProjects(team.$id)}
                      className={cn(
                        'flex w-full cursor-pointer items-center gap-2.5 rounded-md px-2 py-1.5 text-start transition-colors',
                        selectedTeam.$id === team.$id
                          ? 'bg-accent'
                          : 'hover:bg-accent/50',
                      )}
                    >
                      <InitialsAvatar name={team.name} size="sm" />
                      <div className="min-w-0 flex flex-1 items-center gap-2">
                        <span className="truncate text-[13px] font-medium text-foreground">
                          {team.name}
                        </span>
                        {isCloud && teamOrg && (
                          <span
                            className={cn(
                              'shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium capitalize',
                              teamOrg.billingPlanDowngrade
                                ? 'border border-amber-500/20 bg-amber-500/10 text-amber-600 dark:text-amber-400'
                                : getPlanBadgeColor(teamOrg.plan),
                            )}
                          >
                            {teamOrg.billingPlanDowngrade
                              ? t('Downgraded')
                              : getPlanDisplayName(teamOrg.plan)}
                          </span>
                        )}
                      </div>
                      {selectedTeam.$id === team.$id && (
                        <Check className="h-3.5 w-3.5 shrink-0 text-foreground" />
                      )}
                    </button>
                  )
                })
              )}
            </div>
          </div>

          {/* Create Organization - fixed at bottom */}
          <div className="border-t border-border p-1.5">
            <button
              type="button"
              onClick={onCreateOrganization}
              className="flex w-full cursor-pointer items-center gap-2.5 rounded-md px-2 py-1.5 text-start text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            >
              <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-dashed border-muted-foreground/50">
                <Plus className="h-3.5 w-3.5" />
              </div>
              <span className="text-[13px]">{t('Create Organization')}</span>
            </button>
          </div>
        </div>
      )}

      {/* Projects Column */}
      <div
        className={cn(
          'flex flex-col',
          supportsMultiTenancy ? 'w-1/2' : 'w-full',
        )}
      >
        {/* Project Search */}
        <div className="flex items-center gap-2 border-b border-border px-3 py-2.5">
          <Search className="h-3.5 w-3.5 text-muted-foreground" />
          <input
            type="text"
            placeholder={t('Find Project...')}
            value={projectSearch}
            onChange={(e) => setProjectSearch(e.target.value)}
            className="flex-1 bg-transparent text-[13px] text-foreground placeholder:text-muted-foreground focus:outline-none"
          />
        </div>

        {/* Projects List - scrollable */}
        <div
          ref={projectsScrollRef}
          className="min-h-[180px] max-h-[240px] flex-1 overflow-y-auto p-1.5"
        >
          <p className="px-2 py-1.5 text-[11px] font-medium text-muted-foreground">
            Projects
          </p>
          <div className="space-y-0.5">
            {displayProjects.length === 0 ? (
              <p className="px-2 py-4 text-center text-[12px] text-muted-foreground">
                {selectedTeam || !supportsMultiTenancy
                  ? t('No projects found')
                  : t('Select an organization')}
              </p>
            ) : (
              <>
                {displayProjects.map((project) => {
                  const isCurrentProject = project.$id === currentProjectId
                  const isPinned = pinnedSet.has(project.$id)
                  return (
                    <Link
                      key={project.$id}
                      to="/projects/$projectId"
                      params={{ projectId: project.$id }}
                      onClick={(e) => handleSelectProject(project, e)}
                      className={cn(
                        'group flex w-full cursor-pointer items-center gap-2.5 rounded-md px-2 py-1.5 text-start transition-colors',
                        selectedProject?.$id === project.$id
                          ? 'bg-accent'
                          : 'hover:bg-accent/50',
                        isCurrentProject &&
                          selectedProject?.$id !== project.$id &&
                          'bg-primary/10 hover:bg-primary/20 dark:bg-sidebar-accent dark:hover:bg-sidebar-accent/80',
                      )}
                    >
                      <InitialsAvatar name={project.name} size="sm" />
                      <span className="flex min-w-0 flex-1 items-center gap-1.5 overflow-hidden">
                        <span
                          className="min-w-0 flex-1 truncate text-[13px] font-medium text-foreground"
                          title={project.name}
                        >
                          {formatProjectNameForDisplay(project.name)}
                          {isCurrentProject && (
                            <Badge
                              variant="outline"
                              className="ms-1.5 shrink-0 text-[10px] font-normal text-muted-foreground"
                            >
                              Current
                            </Badge>
                          )}
                          {project.paused && (
                            <Badge
                              variant="outline"
                              className="ms-1.5 shrink-0 text-[10px] font-normal text-muted-foreground"
                            >
                              Paused
                            </Badge>
                          )}
                        </span>
                        <FailedInvoiceWarningIcon
                          show={
                            !!billingFailureTeamId &&
                            project.teamId === billingFailureTeamId
                          }
                          orgBillingReadonly={billingOrgReadonly}
                        />
                      </span>
                      {isPinned && (
                        <Pin className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                      )}
                    </Link>
                  )
                })}
                {/* Sentinel element for infinite scroll */}
                {hasNextPage && <div ref={sentinelRef} className="h-1" />}
                {/* Loading indicator when fetching next page */}
                {isFetchingNextPage && (
                  <div className="px-2 py-2 text-center text-[11px] text-muted-foreground">
                    Loading more...
                  </div>
                )}
              </>
            )}
          </div>
        </div>

        {/* Create Project - fixed at bottom */}
        <div className="border-t border-border p-1.5">
          <button
            onClick={onCreateProject}
            className="flex w-full cursor-pointer items-center gap-2.5 rounded-md px-2 py-1.5 text-start text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
          >
            <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-dashed border-muted-foreground/50">
              <Plus className="h-3.5 w-3.5" />
            </div>
            <span className="text-[13px]">{t('Create Project')}</span>
          </button>
        </div>
      </div>
    </div>
  )
}

// Mobile-optimized content with stacked layout
function MobileProjectSelectorContent({
  selectedTeam,
  onSelectTeam,
  selectedProject,
  handleSelectProject,
  teamSearch,
  setTeamSearch,
  projectSearch,
  setProjectSearch,
  filteredTeams,
  displayProjects,
  pinnedProjectIds,
  isFetchingNextPage,
  hasNextPage,
  fetchNextPage,
  organizations,
  isCloud,
  supportsMultiTenancy,
  currentProjectId,
  billingFailureTeamId,
  billingOrgReadonly,
  onCreateProject,
  onCreateOrganization,
  prefetchTeamProjects,
}: ProjectSelectorContentProps) {
  const t = useT()
  const [activeTab, setActiveTab] = useState<'teams' | 'projects'>('projects')
  const pinnedSet = useMemo(() => new Set(pinnedProjectIds), [pinnedProjectIds])

  // Ref for the scrollable container
  const projectsScrollRef = useRef<HTMLDivElement>(null)
  // Ref for the sentinel element that triggers loading
  const sentinelRef = useRef<HTMLDivElement>(null)

  // Intersection Observer to detect when user is near bottom
  // Only observe when projects tab is active
  useEffect(() => {
    const sentinel = sentinelRef.current
    if (
      !sentinel ||
      !hasNextPage ||
      isFetchingNextPage ||
      activeTab !== 'projects'
    )
      return

    const observer = new IntersectionObserver(
      (entries) => {
        const [entry] = entries
        // When sentinel is visible (user scrolled near bottom), load more
        if (entry.isIntersecting && hasNextPage && !isFetchingNextPage) {
          fetchNextPage()
        }
      },
      {
        root: projectsScrollRef.current,
        rootMargin: '200px', // Start loading 200px before reaching bottom
        threshold: 0.1,
      },
    )

    observer.observe(sentinel)

    return () => {
      observer.disconnect()
    }
  }, [hasNextPage, isFetchingNextPage, fetchNextPage, activeTab])

  if (!selectedTeam || !selectedProject) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-sm text-muted-foreground">{t('Loading...')}</div>
      </div>
    )
  }

  return (
    <div className="flex flex-1 flex-col overflow-hidden">
      {/* Tab Switcher */}
      {supportsMultiTenancy && (
        <div className="flex border-b border-border">
          <button
            onClick={() => setActiveTab('teams')}
            className={cn(
              'flex-1 cursor-pointer px-4 py-2.5 text-[13px] font-medium transition-colors',
              activeTab === 'teams'
                ? 'border-b-2 border-foreground text-foreground'
                : 'text-muted-foreground',
            )}
          >
            Organizations
          </button>
          <button
            onClick={() => setActiveTab('projects')}
            className={cn(
              'flex-1 cursor-pointer px-4 py-2.5 text-[13px] font-medium transition-colors',
              activeTab === 'projects'
                ? 'border-b-2 border-foreground text-foreground'
                : 'text-muted-foreground',
            )}
          >
            Projects
          </button>
        </div>
      )}

      {supportsMultiTenancy && activeTab === 'teams' ? (
        <div className="flex flex-1 flex-col overflow-hidden">
          {/* Organization Search */}
          <div className="flex items-center gap-2 border-b border-border px-4 py-2.5">
            <Search className="h-4 w-4 text-muted-foreground" />
            <input
              type="text"
              placeholder={t('Find Organization...')}
              value={teamSearch}
              onChange={(e) => setTeamSearch(e.target.value)}
              className="flex-1 bg-transparent text-[14px] text-foreground placeholder:text-muted-foreground focus:outline-none"
            />
          </div>

          {/* Organizations List */}
          <div className="min-h-[280px] flex-1 overflow-y-auto p-2">
            <div className="space-y-0.5">
              {filteredTeams.length === 0 ? (
                <p className="px-3 py-6 text-center text-[13px] text-muted-foreground">
                  No organizations found
                </p>
              ) : (
                filteredTeams.map((team) => {
                  const teamOrg = organizations.find(
                    (org) => org.$id === team.orgId,
                  )
                  return (
                    <button
                      key={team.$id}
                      type="button"
                      onClick={async () => {
                        await onSelectTeam(team)
                        setActiveTab('projects')
                      }}
                      onMouseEnter={() => prefetchTeamProjects(team.$id)}
                      className={cn(
                        'flex w-full cursor-pointer items-center gap-3 rounded-md px-3 py-2.5 text-start transition-colors',
                        selectedTeam.$id === team.$id
                          ? 'bg-accent'
                          : 'hover:bg-accent/50',
                      )}
                    >
                      <InitialsAvatar name={team.name} size="sm" />
                      <div className="min-w-0 flex-1">
                        <span className="block truncate text-[14px] font-medium text-foreground">
                          {team.name}
                        </span>
                      </div>
                      {isCloud && teamOrg && (
                        <span
                          className={cn(
                            'shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium capitalize',
                            teamOrg.billingPlanDowngrade
                              ? 'border border-amber-500/20 bg-amber-500/10 text-amber-600 dark:text-amber-400'
                              : getPlanBadgeColor(teamOrg.plan),
                          )}
                        >
                          {teamOrg.billingPlanDowngrade
                            ? t('Downgraded')
                            : getPlanDisplayName(teamOrg.plan)}
                        </span>
                      )}
                      {selectedTeam.$id === team.$id && (
                        <Check className="h-4 w-4 shrink-0 text-foreground" />
                      )}
                    </button>
                  )
                })
              )}
            </div>
          </div>

          {/* Create Organization */}
          <div className="border-t border-border p-2">
            <button
              type="button"
              onClick={onCreateOrganization}
              className="flex w-full cursor-pointer items-center gap-3 rounded-md px-3 py-2.5 text-start text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            >
              <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-dashed border-muted-foreground/50">
                <Plus className="h-4 w-4" />
              </div>
              <span className="text-[14px]">{t('Create Organization')}</span>
            </button>
          </div>
        </div>
      ) : (
        <div className="flex flex-1 flex-col overflow-hidden">
          {/* Selected Organization Indicator */}
          {supportsMultiTenancy && (
            <div className="flex items-center gap-2 border-b border-border bg-muted/50 px-4 py-2">
              <span className="text-[12px] text-muted-foreground">
                Organization:
              </span>
              <span className="text-[12px] font-medium text-foreground">
                {selectedTeam.name}
              </span>
              <button
                onClick={() => setActiveTab('teams')}
                className="ms-auto cursor-pointer link-neutral text-[12px] dark:text-muted-foreground"
              >
                Change
              </button>
            </div>
          )}

          {/* Project Search */}
          <div className="flex items-center gap-2 border-b border-border px-4 py-2.5">
            <Search className="h-4 w-4 text-muted-foreground" />
            <input
              type="text"
              placeholder={t('Find Project...')}
              value={projectSearch}
              onChange={(e) => setProjectSearch(e.target.value)}
              className="flex-1 bg-transparent text-[14px] text-foreground placeholder:text-muted-foreground focus:outline-none"
            />
          </div>

          {/* Projects List */}
          <div
            ref={projectsScrollRef}
            className="min-h-[280px] flex-1 overflow-y-auto p-2"
          >
            <div className="space-y-0.5">
              {displayProjects.length === 0 ? (
                <p className="px-3 py-6 text-center text-[13px] text-muted-foreground">
                  {selectedTeam || !supportsMultiTenancy
                    ? t('No projects found')
                    : t('Select an organization')}
                </p>
              ) : (
                <>
                  {displayProjects.map((project) => {
                    const isCurrentProject = project.$id === currentProjectId
                    const isPinned = pinnedSet.has(project.$id)
                    return (
                      <Link
                        key={project.$id}
                        to="/projects/$projectId"
                        params={{ projectId: project.$id }}
                        onClick={(e) => handleSelectProject(project, e)}
                        className={cn(
                          'flex w-full cursor-pointer items-center gap-3 rounded-md px-3 py-2.5 text-start transition-colors',
                          selectedProject?.$id === project.$id
                            ? 'bg-accent'
                            : 'hover:bg-accent/50',
                          isCurrentProject &&
                            selectedProject?.$id !== project.$id &&
                            'bg-primary/10 hover:bg-primary/20 dark:bg-sidebar-accent dark:hover:bg-sidebar-accent/80',
                        )}
                      >
                        <InitialsAvatar name={project.name} size="sm" />
                        <span className="flex min-w-0 flex-1 items-center gap-1.5 overflow-hidden">
                          <span
                            className="min-w-0 flex-1 truncate text-[14px] font-medium text-foreground"
                            title={project.name}
                          >
                            {formatProjectNameForDisplay(project.name)}
                            {isCurrentProject && (
                              <Badge
                                variant="outline"
                                className="ms-1.5 shrink-0 text-[10px] font-normal text-muted-foreground"
                              >
                                Current
                              </Badge>
                            )}
                            {project.paused && (
                              <Badge
                                variant="outline"
                                className="ms-1.5 shrink-0 text-[10px] font-normal text-muted-foreground"
                              >
                                Paused
                              </Badge>
                            )}
                          </span>
                          <FailedInvoiceWarningIcon
                            show={
                              !!billingFailureTeamId &&
                              project.teamId === billingFailureTeamId
                            }
                            orgBillingReadonly={billingOrgReadonly}
                          />
                        </span>
                        {isPinned && (
                          <Pin className="h-4 w-4 shrink-0 text-muted-foreground" />
                        )}
                        {selectedProject?.$id === project.$id && (
                          <Check className="h-4 w-4 shrink-0 text-foreground" />
                        )}
                      </Link>
                    )
                  })}
                  {/* Sentinel element for infinite scroll */}
                  {hasNextPage && <div ref={sentinelRef} className="h-1" />}
                  {/* Loading indicator when fetching next page */}
                  {isFetchingNextPage && (
                    <div className="px-3 py-3 text-center text-[12px] text-muted-foreground">
                      Loading more...
                    </div>
                  )}
                </>
              )}
            </div>
          </div>

          {/* Create Project */}
          <div className="border-t border-border p-2">
            <button
              onClick={onCreateProject}
              className="flex w-full cursor-pointer items-center gap-3 rounded-md px-3 py-2.5 text-start text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            >
              <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-dashed border-muted-foreground/50">
                <Plus className="h-4 w-4" />
              </div>
              <span className="text-[14px]">{t('Create Project')}</span>
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
