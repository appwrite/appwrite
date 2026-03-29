import { useState, useMemo, useEffect, useRef, useCallback } from 'react'
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
} from '@/lib/react-query/hooks'
import { parsePinnedProjectIds } from '@/lib/team-prefs-keys'
import {
  useQuery,
  useQueryClient,
  keepPreviousData,
} from '@tanstack/react-query'
import { getPlanBadgeColor, getPlanDisplayName } from '@/lib/utils/plan-badge'
import { truncateMiddle } from '@/lib/utils'
import { CreateProjectDialog } from '@/components/pages/organizations/$orgId/overview/CreateProjectDialog'
import { useConsoleProfile } from '@/hooks/use-console-profile'

interface ProjectSelectorProps {
  className?: string
  collapsed?: boolean
  projectId?: string
  isMobile?: boolean
}

export function ProjectSelector({
  className,
  collapsed,
  projectId,
  isMobile,
}: ProjectSelectorProps) {
  const { isCloud } = useConsoleProfile()
  const [open, setOpen] = useState(false)
  const [createProjectDialogOpen, setCreateProjectDialogOpen] = useState(false)

  // Fetch organizations and teams (for team selector)
  // Note: We only fetch teams/organizations here, NOT all projects
  const { teams, organizations, isLoading: orgsLoading } = useTeams()

  // Fetch current project separately by ID
  const { project: currentProject, isLoading: currentProjectLoading } =
    useProject(projectId)

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
  const resolvedTeam = selectedTeam ?? initialTeam
  const resolvedProject = selectedProject ?? currentProject ?? null

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

  // Fetch projects for selected team with infinite scroll (excluding pinned)
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
    pinnedIds,
  )

  const queryClient = useQueryClient()

  // Handle team selection - ensure data is ready before switching (loads team once, then pinned + unpinned in parallel)
  const handleSelectTeam = useCallback(
    async (team: Team) => {
      if (team.$id === resolvedTeam?.$id) return

      let excludeIds: string[] = []
      try {
        const teamData = await queryClient.ensureQueryData({
          ...consoleTeamQueryOptions(team.$id),
          staleTime: 5 * 60 * 1000,
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
                staleTime: 5 * 60 * 1000,
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
                staleTime: 5 * 60 * 1000,
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

  // Organization for selected team (for display); plan is fetched in CreateProjectDialog when open
  const selectedTeamOrg = useMemo(() => {
    if (!resolvedTeam) return null
    return organizations.find((org) => org.$id === resolvedTeam.orgId) || null
  }, [resolvedTeam, organizations])

  // Total project count = pinned + unpinned (from infinite query); no separate list call
  const projectsCount = pinnedIds.length + (infiniteTotal ?? 0)

  const filteredTeams = useMemo(() => {
    if (!teams.length) return []
    if (!teamSearch) return teams
    return teams.filter((t) =>
      t.name.toLowerCase().includes(teamSearch.toLowerCase()),
    )
  }, [teamSearch, teams])

  // Pinned projects in Project shape (order from pinnedIds), filtered by search
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
    const list = pinnedIds
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
    if (!projectSearch.trim()) return list
    const q = projectSearch.toLowerCase()
    return list.filter((p) => p.name?.toLowerCase().includes(q))
  }, [pinnedProjectsData, pinnedIds, resolvedTeam, projectSearch])

  // Combine: current (if same team), then pinned, then paginated (excluding current and pinned)
  const displayProjects = useMemo(() => {
    if (!resolvedTeam) return []

    const otherProjects = paginatedProjects.filter((p) => p.$id !== projectId)
    const pinnedFiltered = pinnedProjects.filter((p) => p.$id !== projectId)

    if (currentProject && currentProject.teamId === resolvedTeam.$id) {
      return [currentProject, ...pinnedFiltered, ...otherProjects]
    }
    return [...pinnedFiltered, ...otherProjects]
  }, [
    currentProject,
    pinnedProjects,
    paginatedProjects,
    projectId,
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

  // Show loading state if data is not ready
  if (
    orgsLoading ||
    currentProjectLoading ||
    !resolvedProject ||
    !resolvedTeam
  ) {
    return (
      <div
        className={cn(
          'flex items-center gap-2 rounded-md px-2 py-1.5',
          className,
        )}
      >
        <div className="h-4 w-4 animate-pulse rounded bg-muted" />
        <div className="h-4 w-24 animate-pulse rounded bg-muted" />
      </div>
    )
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
            className="w-[520px] border-border bg-popover p-0"
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
              currentProjectId={projectId}
              onCreateProject={() => setCreateProjectDialogOpen(true)}
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
            'flex w-full items-center gap-2 rounded-md border border-border bg-background px-2.5 py-2 text-left transition-colors hover:bg-accent cursor-pointer',
            className,
          )}
        >
          <InitialsAvatar
            name={resolvedProject.name}
            size="sm"
          />
          <div className="min-w-0 flex-1">
            <p
              className="truncate text-[13px] font-medium text-foreground"
              title={resolvedProject.name}
            >
              {truncateMiddle(
                resolvedProject.name,
                30,
              )}
            </p>
            <p
              className="truncate text-[11px] text-muted-foreground"
              title={currentProjectTeam?.name || resolvedTeam.name}
            >
              {truncateMiddle(
                currentProjectTeam?.name || resolvedTeam.name,
                30,
              )}
            </p>
          </div>
          {isCloud && currentProjectOrg && (
            <span
              className={cn(
                'shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium capitalize',
                getPlanBadgeColor(currentProjectOrg.plan),
              )}
            >
              {getPlanDisplayName(currentProjectOrg.plan)}
            </span>
          )}
          <ChevronDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
        </button>

        <Dialog open={open} onOpenChange={setOpen}>
          <DialogContent
            className="fixed inset-0 left-0 top-0 z-[132] flex h-[100dvh] max-h-none w-[100dvw] max-w-none translate-x-0 translate-y-0 flex-col gap-0 rounded-none border-0 p-0 sm:max-w-none sm:rounded-none"
            overlayClassName="z-[131]"
            showCloseButton={false}
          >
            <DialogTitle className="sr-only">Select Project</DialogTitle>
            {/* Header */}
            <div className="flex items-center justify-between border-b border-border px-4 py-3">
              <h2 className="text-[15px] font-semibold text-foreground">
                Select Project
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
              isFetchingNextPage={isFetchingNextPage}
              hasNextPage={hasNextPage}
              fetchNextPage={fetchNextPage}
              organizations={organizations}
              currentProjectId={projectId}
              onCreateProject={() => setCreateProjectDialogOpen(true)}
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
              'flex h-9 max-w-full min-w-0 items-center gap-2 rounded-md px-2 py-1.5 text-left transition-colors hover:bg-accent cursor-pointer',
              className,
            )}
          >
            <InitialsAvatar
              name={resolvedProject.name}
              size="sm"
            />
            <div className="min-w-0 flex flex-1 items-center gap-2 overflow-hidden">
              <p
                className="truncate text-[13px] font-medium text-foreground"
                title={`${currentProjectTeam?.name || resolvedTeam.name} / ${resolvedProject.name}`}
              >
                {truncateMiddle(
                  currentProjectTeam?.name || resolvedTeam.name,
                  20,
                )}{' '}
                /{' '}
                {truncateMiddle(
                  resolvedProject.name,
                  22,
                )}
              </p>
              {isCloud && currentProjectOrg && (
                <span
                  className={cn(
                    'shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium capitalize',
                    getPlanBadgeColor(currentProjectOrg.plan),
                  )}
                >
                  {getPlanDisplayName(currentProjectOrg.plan)}
                </span>
              )}
            </div>
            <ChevronDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
          </button>
        </PopoverTrigger>
        <PopoverContent
          side="bottom"
          align="start"
          sideOffset={8}
          className="w-[520px] border-border bg-popover p-0"
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
            currentProjectId={projectId}
            onCreateProject={() => setCreateProjectDialogOpen(true)}
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
  handleSelectProject: (project: Project) => void
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
  currentProjectId?: string
  onCreateProject: () => void
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
  currentProjectId,
  onCreateProject,
}: ProjectSelectorContentProps) {
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
        <div className="text-sm text-muted-foreground">Loading...</div>
      </div>
    )
  }

  return (
    <div className="flex divide-x divide-border">
      {/* Organizations Column */}
      <div className="flex w-1/2 flex-col">
        {/* Organization Search */}
        <div className="flex items-center gap-2 border-b border-border px-3 py-2.5">
          <Search className="h-3.5 w-3.5 text-muted-foreground" />
          <input
            type="text"
            placeholder="Find Organization..."
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
                    onClick={() => onSelectTeam(team)}
                    className={cn(
                      'flex w-full cursor-pointer items-center gap-2.5 rounded-md px-2 py-1.5 text-left transition-colors',
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
                            getPlanBadgeColor(teamOrg.plan),
                          )}
                        >
                          {getPlanDisplayName(teamOrg.plan)}
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
          <button className="flex w-full cursor-pointer items-center gap-2.5 rounded-md px-2 py-1.5 text-left text-muted-foreground transition-colors hover:bg-accent hover:text-foreground">
            <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-dashed border-muted-foreground/50">
              <Plus className="h-3.5 w-3.5" />
            </div>
            <span className="text-[13px]">Create Organization</span>
          </button>
        </div>
      </div>

      {/* Projects Column */}
      <div className="flex w-1/2 flex-col">
        {/* Project Search */}
        <div className="flex items-center gap-2 border-b border-border px-3 py-2.5">
          <Search className="h-3.5 w-3.5 text-muted-foreground" />
          <input
            type="text"
            placeholder="Find Project..."
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
                {selectedTeam ? 'No projects found' : 'Select an organization'}
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
                        'group flex w-full cursor-pointer items-center gap-2.5 rounded-md px-2 py-1.5 text-left transition-colors',
                        selectedProject?.$id === project.$id
                          ? 'bg-accent'
                          : 'hover:bg-accent/50',
                        isCurrentProject &&
                          selectedProject?.$id !== project.$id &&
                          'bg-primary/10 hover:bg-primary/20 classic:bg-sidebar-accent classic:hover:bg-sidebar-accent/80',
                      )}
                    >
                      <InitialsAvatar name={project.name} size="sm" />
                      <span className="min-w-0 flex-1 truncate text-[13px] font-medium text-foreground">
                        {project.name}
                        {isCurrentProject && (
                          <Badge
                            variant="outline"
                            className="ml-1.5 shrink-0 text-[10px] font-normal text-muted-foreground"
                          >
                            Current
                          </Badge>
                        )}
                        {project.paused && (
                          <Badge
                            variant="outline"
                            className="ml-1.5 shrink-0 text-[10px] font-normal text-muted-foreground"
                          >
                            Paused
                          </Badge>
                        )}
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
            className="flex w-full cursor-pointer items-center gap-2.5 rounded-md px-2 py-1.5 text-left text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
          >
            <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded-md border border-dashed border-muted-foreground/50">
              <Plus className="h-3.5 w-3.5" />
            </div>
            <span className="text-[13px]">Create Project</span>
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
  currentProjectId,
  onCreateProject,
}: ProjectSelectorContentProps) {
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
        <div className="text-sm text-muted-foreground">Loading...</div>
      </div>
    )
  }

  return (
    <div className="flex flex-1 flex-col overflow-hidden">
      {/* Tab Switcher */}
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

      {activeTab === 'teams' ? (
        <div className="flex flex-1 flex-col overflow-hidden">
          {/* Organization Search */}
          <div className="flex items-center gap-2 border-b border-border px-4 py-2.5">
            <Search className="h-4 w-4 text-muted-foreground" />
            <input
              type="text"
              placeholder="Find Organization..."
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
                      onClick={async () => {
                        await onSelectTeam(team)
                        setActiveTab('projects')
                      }}
                      className={cn(
                        'flex w-full cursor-pointer items-center gap-3 rounded-md px-3 py-2.5 text-left transition-colors',
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
                            getPlanBadgeColor(teamOrg.plan),
                          )}
                        >
                          {getPlanDisplayName(teamOrg.plan)}
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
            <button className="flex w-full cursor-pointer items-center gap-3 rounded-md px-3 py-2.5 text-left text-muted-foreground transition-colors hover:bg-accent hover:text-foreground">
              <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-dashed border-muted-foreground/50">
                <Plus className="h-4 w-4" />
              </div>
              <span className="text-[14px]">Create Organization</span>
            </button>
          </div>
        </div>
      ) : (
        <div className="flex flex-1 flex-col overflow-hidden">
          {/* Selected Organization Indicator */}
          <div className="flex items-center gap-2 border-b border-border bg-muted/50 px-4 py-2">
            <span className="text-[12px] text-muted-foreground">
              Organization:
            </span>
            <span className="text-[12px] font-medium text-foreground">
              {selectedTeam.name}
            </span>
            <button
              onClick={() => setActiveTab('teams')}
              className="ml-auto cursor-pointer text-[12px] text-primary hover:underline classic:text-muted-foreground"
            >
              Change
            </button>
          </div>

          {/* Project Search */}
          <div className="flex items-center gap-2 border-b border-border px-4 py-2.5">
            <Search className="h-4 w-4 text-muted-foreground" />
            <input
              type="text"
              placeholder="Find Project..."
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
                  {selectedTeam
                    ? 'No projects found'
                    : 'Select an organization'}
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
                          'flex w-full cursor-pointer items-center gap-3 rounded-md px-3 py-2.5 text-left transition-colors',
                          selectedProject?.$id === project.$id
                            ? 'bg-accent'
                            : 'hover:bg-accent/50',
                          isCurrentProject &&
                            selectedProject?.$id !== project.$id &&
                            'bg-primary/10 hover:bg-primary/20 classic:bg-sidebar-accent classic:hover:bg-sidebar-accent/80',
                        )}
                      >
                        <InitialsAvatar name={project.name} size="sm" />
                        <span className="min-w-0 flex-1 truncate text-[14px] font-medium text-foreground">
                          {project.name}
                          {isCurrentProject && (
                            <Badge
                              variant="outline"
                              className="ml-1.5 shrink-0 text-[10px] font-normal text-muted-foreground"
                            >
                              Current
                            </Badge>
                          )}
                          {project.paused && (
                            <Badge
                              variant="outline"
                              className="ml-1.5 shrink-0 text-[10px] font-normal text-muted-foreground"
                            >
                              Paused
                            </Badge>
                          )}
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
              className="flex w-full cursor-pointer items-center gap-3 rounded-md px-3 py-2.5 text-left text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            >
              <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded-md border border-dashed border-muted-foreground/50">
                <Plus className="h-4 w-4" />
              </div>
              <span className="text-[14px]">Create Project</span>
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
