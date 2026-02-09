import { useState, useMemo, useEffect, useRef, useCallback } from 'react'
import { cn } from '@/lib/utils'
import { ChevronDown, Check, Plus, Search, Star, X } from 'lucide-react'
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
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import {
  useTeams,
  useProject,
  useProjectsForTeamInfinite,
  useOrganizationPlan,
  useProjectsForTeam,
  fetchActiveProjects,
} from '@/lib/react-query/hooks'
import { useQueryClient } from '@tanstack/react-query'
import { getPlanBadgeColor } from '@/lib/utils/plan-badge'
import { CreateProjectDialog } from '@/components/pages/organizations/$orgId/overview/CreateProjectDialog'

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
  }, [selectedTeam?.$id])

  // Fetch projects for selected team with infinite scroll
  const {
    projects: paginatedProjects,
    isFetchingNextPage,
    hasNextPage,
    fetchNextPage,
  } = useProjectsForTeamInfinite(
    selectedTeam?.$id,
    projectsPageSize,
    projectSearch,
  )

  // Query client for prefetching
  const queryClient = useQueryClient()

  // Prefetch projects for a team on hover
  const handlePrefetchTeamProjects = useCallback(
    (teamId: string) => {
      // Only prefetch if not already the selected team
      if (teamId === selectedTeam?.$id) return

      // Prefetch the first page of projects for this team
      queryClient.prefetchInfiniteQuery({
        queryKey: [
          'projects',
          'team',
          'infinite',
          teamId,
          projectsPageSize,
          '',
        ],
        queryFn: ({ pageParam = 0 }) =>
          fetchActiveProjects(teamId, pageParam, projectsPageSize, ''),
        initialPageParam: 0,
        staleTime: 5 * 60 * 1000, // 5 minutes
      })
    },
    [queryClient, selectedTeam?.$id, projectsPageSize],
  )

  // Handle team selection - ensure data is ready before switching
  const handleSelectTeam = useCallback(
    async (team: Team) => {
      // If same team, do nothing
      if (team.$id === selectedTeam?.$id) return

      // Check if we already have cached data for this team
      const queryKey = [
        'projects',
        'team',
        'infinite',
        team.$id,
        projectsPageSize,
        '',
      ]
      const cachedData = queryClient.getQueryData(queryKey)

      if (cachedData) {
        // Data is already cached, switch immediately
        setSelectedTeam(team)
      } else {
        // Data not cached, fetch it first then switch
        await queryClient.fetchInfiniteQuery({
          queryKey,
          queryFn: ({ pageParam = 0 }) =>
            fetchActiveProjects(team.$id, pageParam, projectsPageSize, ''),
          initialPageParam: 0,
          staleTime: 5 * 60 * 1000,
        })
        setSelectedTeam(team)
      }
    },
    [queryClient, selectedTeam?.$id, projectsPageSize],
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

  // Get organization plan and project count for selected team
  // These hooks must be called before any early returns to follow Rules of Hooks
  const selectedTeamOrg = useMemo(() => {
    if (!selectedTeam) return null
    return organizations.find((org) => org.$id === selectedTeam.orgId) || null
  }, [selectedTeam, organizations])

  const { plan: organizationPlan } = useOrganizationPlan(selectedTeamOrg?.$id)
  const { total: projectsCount } = useProjectsForTeam(
    selectedTeam?.$id,
    0,
    1,
    '',
  )

  const filteredTeams = useMemo(() => {
    if (!teams.length) return []
    if (!teamSearch) return teams
    return teams.filter((t) =>
      t.name.toLowerCase().includes(teamSearch.toLowerCase()),
    )
  }, [teamSearch, teams])

  // Combine current project (at top) with paginated projects, excluding current project from list
  const displayProjects = useMemo(() => {
    if (!selectedTeam) return []

    const otherProjects = paginatedProjects.filter((p) => p.$id !== projectId)

    // If we have a current project and it belongs to the selected team, show it at top
    if (currentProject && currentProject.teamId === selectedTeam.$id) {
      return [currentProject, ...otherProjects]
    }

    return otherProjects
  }, [currentProject, paginatedProjects, projectId, selectedTeam])

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
    !selectedProject ||
    !selectedTeam
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
              {(currentProject?.name || selectedProject.name)
                .charAt(0)
                .toUpperCase()}
            </button>
          </PopoverTrigger>
          <PopoverContent
            side="right"
            align="start"
            sideOffset={12}
            className="w-[520px] border-border bg-popover p-0"
          >
            <ProjectSelectorContent
              selectedTeam={selectedTeam}
              onSelectTeam={handleSelectTeam}
              selectedProject={selectedProject}
              handleSelectProject={handleSelectProject}
              teamSearch={teamSearch}
              setTeamSearch={setTeamSearch}
              projectSearch={projectSearch}
              setProjectSearch={setProjectSearch}
              filteredTeams={filteredTeams}
              displayProjects={displayProjects}
              isFetchingNextPage={isFetchingNextPage}
              hasNextPage={hasNextPage}
              fetchNextPage={fetchNextPage}
              organizations={organizations}
              currentProjectId={projectId}
              onCreateProject={() => setCreateProjectDialogOpen(true)}
              onPrefetchTeamProjects={handlePrefetchTeamProjects}
            />
          </PopoverContent>
        </Popover>

        <CreateProjectDialog
          open={createProjectDialogOpen}
          onOpenChange={setCreateProjectDialogOpen}
          teamId={selectedTeam?.$id}
          organizationPlan={organizationPlan}
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
            name={currentProject?.name || selectedProject.name}
            size="sm"
          />
          <div className="min-w-0 flex-1">
            <p className="truncate text-[13px] font-medium text-foreground">
              {currentProject?.name || selectedProject.name}
            </p>
            <p className="truncate text-[11px] text-muted-foreground">
              {currentProjectTeam?.name || selectedTeam.name}
            </p>
          </div>
          {currentProjectOrg && (
            <span
              className={cn(
                'shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium capitalize',
                getPlanBadgeColor(currentProjectOrg.plan),
              )}
            >
              {currentProjectOrg.plan}
            </span>
          )}
          <ChevronDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
        </button>

        <Dialog open={open} onOpenChange={setOpen}>
          <DialogContent
            className="flex h-[100dvh] max-h-none w-screen max-w-none flex-col gap-0 rounded-none border-0 p-0 sm:rounded-none"
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
                className="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
              >
                <X className="h-5 w-5" />
              </button>
            </div>

            {/* Content */}
            <MobileProjectSelectorContent
              selectedTeam={selectedTeam}
              onSelectTeam={handleSelectTeam}
              selectedProject={selectedProject}
              handleSelectProject={handleSelectProject}
              teamSearch={teamSearch}
              setTeamSearch={setTeamSearch}
              projectSearch={projectSearch}
              setProjectSearch={setProjectSearch}
              filteredTeams={filteredTeams}
              displayProjects={displayProjects}
              isFetchingNextPage={isFetchingNextPage}
              hasNextPage={hasNextPage}
              fetchNextPage={fetchNextPage}
              organizations={organizations}
              currentProjectId={projectId}
              onCreateProject={() => setCreateProjectDialogOpen(true)}
              onPrefetchTeamProjects={handlePrefetchTeamProjects}
            />
          </DialogContent>
        </Dialog>

        <CreateProjectDialog
          open={createProjectDialogOpen}
          onOpenChange={setCreateProjectDialogOpen}
          teamId={selectedTeam?.$id}
          organizationPlan={organizationPlan}
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
              'flex items-center gap-2 rounded-md px-2 py-1.5 text-left transition-colors hover:bg-accent cursor-pointer',
              className,
            )}
          >
            <InitialsAvatar
              name={currentProject?.name || selectedProject.name}
              size="sm"
            />
            <div className="min-w-0 flex items-center gap-2">
              <p className="truncate text-[13px] font-medium text-foreground">
                {currentProjectTeam?.name || selectedTeam.name} /{' '}
                {currentProject?.name || selectedProject.name}
              </p>
              {currentProjectOrg && (
                <span
                  className={cn(
                    'shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium capitalize',
                    getPlanBadgeColor(currentProjectOrg.plan),
                  )}
                >
                  {currentProjectOrg.plan}
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
            selectedTeam={selectedTeam}
            onSelectTeam={handleSelectTeam}
            selectedProject={selectedProject}
            handleSelectProject={handleSelectProject}
            teamSearch={teamSearch}
            setTeamSearch={setTeamSearch}
            projectSearch={projectSearch}
            setProjectSearch={setProjectSearch}
            filteredTeams={filteredTeams}
            displayProjects={displayProjects}
            isFetchingNextPage={isFetchingNextPage}
            hasNextPage={hasNextPage}
            fetchNextPage={fetchNextPage}
            organizations={organizations}
            currentProjectId={projectId}
            onCreateProject={() => setCreateProjectDialogOpen(true)}
            onPrefetchTeamProjects={handlePrefetchTeamProjects}
          />
        </PopoverContent>
      </Popover>

      {/* Create Project Dialog */}
      <CreateProjectDialog
        open={createProjectDialogOpen}
        onOpenChange={setCreateProjectDialogOpen}
        teamId={selectedTeam?.$id}
        organizationPlan={organizationPlan}
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
  isFetchingNextPage: boolean
  hasNextPage: boolean
  fetchNextPage: () => void
  organizations: Organization[]
  currentProjectId?: string
  onCreateProject: () => void
  onPrefetchTeamProjects: (teamId: string) => void
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
  isFetchingNextPage,
  hasNextPage,
  fetchNextPage,
  organizations,
  currentProjectId,
  onCreateProject,
  onPrefetchTeamProjects,
}: ProjectSelectorContentProps) {
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
      {/* Teams Column */}
      <div className="flex w-1/2 flex-col">
        {/* Team Search */}
        <div className="flex items-center gap-2 border-b border-border px-3 py-2.5">
          <Search className="h-3.5 w-3.5 text-muted-foreground" />
          <input
            type="text"
            placeholder="Find Team..."
            value={teamSearch}
            onChange={(e) => setTeamSearch(e.target.value)}
            className="flex-1 bg-transparent text-[13px] text-foreground placeholder:text-muted-foreground focus:outline-none"
          />
        </div>

        {/* Teams List - scrollable */}
        <div className="min-h-[180px] max-h-[240px] flex-1 overflow-y-auto p-1.5">
          <p className="px-2 py-1.5 text-[11px] font-medium text-muted-foreground">
            Teams
          </p>
          <div className="space-y-0.5">
            {filteredTeams.length === 0 ? (
              <p className="px-2 py-4 text-center text-[12px] text-muted-foreground">
                No teams found
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
                    onMouseEnter={() => onPrefetchTeamProjects(team.$id)}
                    className={cn(
                      'flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 text-left transition-colors',
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
                      {teamOrg && (
                        <span
                          className={cn(
                            'shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium capitalize',
                            getPlanBadgeColor(teamOrg.plan),
                          )}
                        >
                          {teamOrg.plan}
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

        {/* Create Team - fixed at bottom */}
        <div className="border-t border-border p-1.5">
          <button className="flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 text-left text-muted-foreground transition-colors hover:bg-accent hover:text-foreground">
            <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-dashed border-muted-foreground/50">
              <Plus className="h-3.5 w-3.5" />
            </div>
            <span className="text-[13px]">Create Team</span>
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
                {selectedTeam ? 'No projects found' : 'Select a team'}
              </p>
            ) : (
              <>
                {displayProjects.map((project) => {
                  const isCurrentProject = project.$id === currentProjectId
                  return (
                    <Link
                      key={project.$id}
                      to="/projects/$projectId"
                      params={{ projectId: project.$id }}
                      onClick={(e) => handleSelectProject(project, e)}
                      className={cn(
                        'group flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 text-left transition-colors',
                        selectedProject?.$id === project.$id
                          ? 'bg-accent'
                          : 'hover:bg-accent/50',
                        isCurrentProject && 'bg-primary/10 hover:bg-primary/20',
                      )}
                    >
                      <InitialsAvatar name={project.name} size="sm" />
                      <span className="min-w-0 flex-1 truncate text-[13px] font-medium text-foreground">
                        {project.name}
                        {isCurrentProject && (
                          <span className="ml-1.5 text-[10px] text-primary">
                            (current)
                          </span>
                        )}
                      </span>
                      <Star className="h-3.5 w-3.5 shrink-0 text-muted-foreground/50 opacity-0 transition-opacity group-hover:opacity-100" />
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
            className="flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 text-left text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
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
  isFetchingNextPage,
  hasNextPage,
  fetchNextPage,
  organizations,
  currentProjectId,
  onCreateProject,
  onPrefetchTeamProjects,
}: ProjectSelectorContentProps) {
  const [activeTab, setActiveTab] = useState<'teams' | 'projects'>('projects')

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
            'flex-1 px-4 py-2.5 text-[13px] font-medium transition-colors',
            activeTab === 'teams'
              ? 'border-b-2 border-foreground text-foreground'
              : 'text-muted-foreground',
          )}
        >
          Teams
        </button>
        <button
          onClick={() => setActiveTab('projects')}
          className={cn(
            'flex-1 px-4 py-2.5 text-[13px] font-medium transition-colors',
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
          {/* Team Search */}
          <div className="flex items-center gap-2 border-b border-border px-4 py-2.5">
            <Search className="h-4 w-4 text-muted-foreground" />
            <input
              type="text"
              placeholder="Find Team..."
              value={teamSearch}
              onChange={(e) => setTeamSearch(e.target.value)}
              className="flex-1 bg-transparent text-[14px] text-foreground placeholder:text-muted-foreground focus:outline-none"
            />
          </div>

          {/* Teams List */}
          <div className="min-h-[280px] flex-1 overflow-y-auto p-2">
            <div className="space-y-0.5">
              {filteredTeams.length === 0 ? (
                <p className="px-3 py-6 text-center text-[13px] text-muted-foreground">
                  No teams found
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
                      onMouseEnter={() => onPrefetchTeamProjects(team.$id)}
                      className={cn(
                        'flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-left transition-colors',
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
                      {teamOrg && (
                        <span
                          className={cn(
                            'shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium capitalize',
                            getPlanBadgeColor(teamOrg.plan),
                          )}
                        >
                          {teamOrg.plan}
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

          {/* Create Team */}
          <div className="border-t border-border p-2">
            <button className="flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-left text-muted-foreground transition-colors hover:bg-accent hover:text-foreground">
              <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-dashed border-muted-foreground/50">
                <Plus className="h-4 w-4" />
              </div>
              <span className="text-[14px]">Create Team</span>
            </button>
          </div>
        </div>
      ) : (
        <div className="flex flex-1 flex-col overflow-hidden">
          {/* Selected Team Indicator */}
          <div className="flex items-center gap-2 border-b border-border bg-muted/50 px-4 py-2">
            <span className="text-[12px] text-muted-foreground">Team:</span>
            <span className="text-[12px] font-medium text-foreground">
              {selectedTeam.name}
            </span>
            <button
              onClick={() => setActiveTab('teams')}
              className="ml-auto text-[12px] text-primary hover:underline"
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
                  {selectedTeam ? 'No projects found' : 'Select a team'}
                </p>
              ) : (
                <>
                  {displayProjects.map((project) => {
                    const isCurrentProject = project.$id === currentProjectId
                    return (
                      <Link
                        key={project.$id}
                        to="/projects/$projectId"
                        params={{ projectId: project.$id }}
                        onClick={(e) => handleSelectProject(project, e)}
                        className={cn(
                          'flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-left transition-colors',
                          selectedProject?.$id === project.$id
                            ? 'bg-accent'
                            : 'hover:bg-accent/50',
                          isCurrentProject &&
                            'bg-primary/10 hover:bg-primary/20',
                        )}
                      >
                        <InitialsAvatar name={project.name} size="sm" />
                        <span className="min-w-0 flex-1 truncate text-[14px] font-medium text-foreground">
                          {project.name}
                          {isCurrentProject && (
                            <span className="ml-1.5 text-[10px] text-primary">
                              (current)
                            </span>
                          )}
                        </span>
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
              className="flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-left text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
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
