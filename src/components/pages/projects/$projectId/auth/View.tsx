import { useState, useMemo, useEffect } from 'react'
import {
  useParams,
  useLocation,
  Link,
  useNavigate,
  useSearch,
} from '@tanstack/react-router'
import { cn } from '@/lib/utils'
import {
  Users,
  LayoutGrid,
  List,
  AlertCircle,
  CheckCircle2,
  XCircle,
  Mail,
  Phone,
} from 'lucide-react'
import {
  useProjectUsers,
  useProjectTeams,
  useCreateProjectUser,
  useCreateProjectTeam,
  useProject,
} from '@/lib/react-query/hooks'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  deleteProjectUser,
  deleteProjectTeam,
} from '@/lib/react-query/hooks/users'
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import { ResourceCard } from '../shared/ResourceCard'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { LightningCollectorGame } from './LightningCollectorGame'
import { useDebugMode } from '@/components/global/providers/DebugMode'
import { CreateUserDrawer } from './CreateUserDrawer'
import { CreateTeamDrawer } from './CreateTeamDrawer'
import { Security } from './Security'
import { AuthSettings } from './Settings'
import { Templates } from './Templates'
import { toast } from 'sonner'

export function View() {
  const { projectId } = useParams({
    strict: false,
  })
  const location = useLocation()
  const navigate = useNavigate()
  const search = useSearch({ strict: false }) as { create?: string }
  const { isDebugModeOpen } = useDebugMode()

  // Check if we're on a user detail route - if so, don't render this component
  const isUserDetailRoute = useMemo(() => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const authIndex = pathParts.findIndex((part) => part === 'auth')
    return (
      authIndex >= 0 &&
      pathParts[authIndex + 1] === 'users' &&
      pathParts[authIndex + 2]
    )
  }, [location.pathname])

  // Check if we're on a team detail route - if so, don't render this component
  const isTeamDetailRoute = useMemo(() => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const authIndex = pathParts.findIndex((part) => part === 'auth')
    return (
      authIndex >= 0 &&
      pathParts[authIndex + 1] === 'teams' &&
      pathParts[authIndex + 2]
    )
  }, [location.pathname])

  // Derive active tab from pathname
  const activeTab = useMemo(() => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const authIndex = pathParts.findIndex((part) => part === 'auth')

    if (authIndex >= 0) {
      // Check if there's a tab segment after 'auth'
      // pathParts structure: ['projects', 'projectId', 'auth', 'tab?']
      if (pathParts[authIndex + 1]) {
        const tabFromPath = pathParts[authIndex + 1]
        if (
          ['teams', 'security', 'templates', 'settings'].includes(tabFromPath)
        ) {
          return tabFromPath
        }
      }
    }

    // Default to users for index route (/projects/:projectId/auth or /projects/:projectId/auth/)
    return 'users'
  }, [location.pathname])
  const [usersSearchValue, setUsersSearchValue] = useState('')
  const [teamsSearchValue, setTeamsSearchValue] = useState('')
  const [usersViewMode, setUsersViewMode] = useState<'list' | 'grid'>('list')
  const [teamsViewMode, setTeamsViewMode] = useState<'list' | 'grid'>('grid')
  const [createUserDialogOpen, setCreateUserDialogOpen] = useState(false)
  const [createTeamDialogOpen, setCreateTeamDialogOpen] = useState(false)
  const [selectedUsers, setSelectedUsers] = useState<Set<string>>(new Set())
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [selectedTeams, setSelectedTeams] = useState<Set<string>>(new Set())
  const [deleteTeamDialogOpen, setDeleteTeamDialogOpen] = useState(false)

  // Open create user drawer when ?create=user (e.g. from header plus button)
  useEffect(() => {
    if (search?.create === 'user' && !createUserDialogOpen) {
      setCreateUserDialogOpen(true)
      navigate({
        to: location.pathname,
        search: (prev: Record<string, unknown>) => {
          if (!prev || typeof prev !== 'object') return {}
          const next = { ...prev }
          delete next.create
          return Object.keys(next).length === 0 ? {} : next
        },
        replace: true,
      })
    }
  }, [search?.create, createUserDialogOpen, navigate, location.pathname])

  // Pagination state for users (1-indexed for UI)
  const [usersRequestedPage, setUsersRequestedPage] = useState(1)
  const [usersDisplayedPage, setUsersDisplayedPage] = useState(1)
  const [usersPageSize, setUsersPageSize] = useState(25)

  const queryClient = useQueryClient()

  // Clear selection when navigating between pages/routes or when search changes
  useEffect(() => {
    setSelectedUsers(new Set())
    setDeleteDialogOpen(false)
    setSelectedTeams(new Set())
    setDeleteTeamDialogOpen(false)
  }, [location.pathname, projectId, usersSearchValue, teamsSearchValue])

  // Pagination state for teams
  const [teamsRequestedPage, setTeamsRequestedPage] = useState(1)
  const [teamsDisplayedPage, setTeamsDisplayedPage] = useState(1)
  const [teamsPageSize, setTeamsPageSize] = useState(25)

  // Fetch users for the requested page (triggers load when user changes page)
  const {
    total: usersTotal,
    isLoading: usersLoading,
    isFetching: usersFetching,
  } = useProjectUsers(
    projectId,
    usersRequestedPage - 1,
    usersPageSize,
    usersSearchValue,
  )

  // Fetch users for the displayed page (what we show - stays until new page is ready)
  const {
    users: apiUsers,
    total: displayedUsersTotal,
    isLoading: usersDisplayedLoading,
  } = useProjectUsers(
    projectId,
    usersDisplayedPage - 1,
    usersPageSize,
    usersSearchValue,
  )

  // Update displayed users page only when requested page data is ready (no flash)
  useEffect(() => {
    if (
      !usersFetching &&
      usersRequestedPage !== usersDisplayedPage &&
      !usersLoading
    ) {
      setUsersDisplayedPage(usersRequestedPage)
    }
  }, [usersFetching, usersLoading, usersRequestedPage, usersDisplayedPage])

  const showUsersLoading = usersDisplayedLoading && apiUsers.length === 0

  // Fetch teams for the requested page (triggers load when user changes page)
  const {
    total: teamsTotal,
    isLoading: teamsLoading,
    isFetching: teamsFetching,
  } = useProjectTeams(
    projectId,
    teamsRequestedPage - 1,
    teamsPageSize,
    teamsSearchValue,
  )

  // Fetch teams for the displayed page (what we show - stays until new page is ready)
  const {
    teams: apiTeams,
    total: displayedTeamsTotal,
    isLoading: teamsDisplayedLoading,
  } = useProjectTeams(
    projectId,
    teamsDisplayedPage - 1,
    teamsPageSize,
    teamsSearchValue,
  )

  // Update displayed teams page only when requested page data is ready (no flash)
  useEffect(() => {
    if (
      !teamsFetching &&
      teamsRequestedPage !== teamsDisplayedPage &&
      !teamsLoading
    ) {
      setTeamsDisplayedPage(teamsRequestedPage)
    }
  }, [teamsFetching, teamsLoading, teamsRequestedPage, teamsDisplayedPage])

  const showTeamsLoading = teamsDisplayedLoading && apiTeams.length === 0

  // Mutation to create a user
  const createUserMutation = useCreateProjectUser(projectId)

  // Mutation to create a team
  const createTeamMutation = useCreateProjectTeam(projectId)

  // Extend users with additional metadata for display
  const extendedUsers = useMemo(() => {
    return apiUsers.map((user) => {
      // Determine provider from user data (check for OAuth providers)
      // For now, default to 'email' - this could be enhanced to check user.labels or other fields
      const provider = 'email' // TODO: Extract from user.labels or user.providers if available

      return {
        ...user,
        provider,
        phone: (user as unknown).phone || '',
        mfaEnabled: (user as unknown).mfaEnabled || false,
        emailVerification: (user as unknown).emailVerification || false,
        phoneVerification: (user as unknown).phoneVerification || false,
      }
    })
  }, [apiUsers])

  // Paginated data - users and teams are already paginated by the API
  const paginatedUsers = extendedUsers
  const paginatedTeams = apiTeams

  const getUserVerificationStatus = (user: (typeof paginatedUsers)[number]) => {
    if (user.status === 'verified') {
      return { label: 'Verified', tone: 'success' as const }
    }

    return { label: 'Unverified', tone: 'warning' as const }
  }

  // Reset page when search changes
  const handleUsersSearchChange = (value: string) => {
    setUsersSearchValue(value)
    setUsersRequestedPage(1)
    setUsersDisplayedPage(1)
    setSelectedUsers(new Set()) // Clear selection on search change
  }

  // Bulk delete mutation
  const bulkDeleteMutation = useMutation({
    mutationFn: async (userIds: string[]) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      // Delete all users in parallel
      await Promise.all(
        userIds.map((userId) => deleteProjectUser(projectId, userId)),
      )
    },
    onSuccess: async () => {
      // Refetch users list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: ['users', 'project', projectId],
      })
      toast.success(
        `Successfully deleted ${selectedUsers.size} user${selectedUsers.size > 1 ? 's' : ''}`,
      )
      setSelectedUsers(new Set())
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to delete users')
    },
  })

  const handleBulkDelete = () => {
    if (selectedUsers.size === 0) return
    setDeleteDialogOpen(true)
  }

  const confirmBulkDelete = () => {
    if (selectedUsers.size === 0) return
    bulkDeleteMutation.mutate(Array.from(selectedUsers))
  }

  const toggleUser = (userId: string) => {
    const newSelected = new Set(selectedUsers)
    if (newSelected.has(userId)) {
      newSelected.delete(userId)
    } else {
      newSelected.add(userId)
    }
    setSelectedUsers(newSelected)
  }

  const toggleAllUsers = () => {
    if (selectedUsers.size === paginatedUsers.length) {
      setSelectedUsers(new Set())
    } else {
      setSelectedUsers(new Set(paginatedUsers.map((u) => u.$id)))
    }
  }

  const handlePageChange = (page: number) => {
    setUsersRequestedPage(page)
    setSelectedUsers(new Set()) // Clear selection on page change
  }

  const handlePageSizeChange = (newPageSize: number) => {
    setUsersPageSize(newPageSize)
    setUsersRequestedPage(1)
    setUsersDisplayedPage(1)
    setSelectedUsers(new Set()) // Clear selection on page size change
  }

  // Format last accessed date - shows relative time but only at day resolution or higher
  // Never shows hours, minutes, or seconds - uses "today" instead
  const formatLastAccessed = (dateString: string): string => {
    const date = new Date(dateString)
    const now = new Date()
    const diffMs = now.getTime() - date.getTime()
    const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24))

    // Check if it's today (same calendar day)
    const isToday =
      date.getDate() === now.getDate() &&
      date.getMonth() === now.getMonth() &&
      date.getFullYear() === now.getFullYear()

    // Check if it's yesterday (previous calendar day)
    const yesterday = new Date(now)
    yesterday.setDate(yesterday.getDate() - 1)
    const isYesterday =
      date.getDate() === yesterday.getDate() &&
      date.getMonth() === yesterday.getMonth() &&
      date.getFullYear() === yesterday.getFullYear()

    if (isToday) {
      return 'Today'
    } else if (isYesterday) {
      return 'Yesterday'
    } else if (diffDays < 7) {
      return `${diffDays} day${diffDays !== 1 ? 's' : ''} ago`
    } else if (diffDays < 30) {
      const weeks = Math.floor(diffDays / 7)
      return `${weeks} week${weeks !== 1 ? 's' : ''} ago`
    } else if (diffDays < 365) {
      const months = Math.floor(diffDays / 30)
      return `${months} month${months !== 1 ? 's' : ''} ago`
    } else {
      const years = Math.floor(diffDays / 365)
      return `${years} year${years !== 1 ? 's' : ''} ago`
    }
  }

  const handleTeamsSearchChange = (value: string) => {
    setTeamsSearchValue(value)
    setTeamsRequestedPage(1)
    setTeamsDisplayedPage(1)
    setSelectedTeams(new Set()) // Clear selection on search change
  }

  // Bulk delete mutation for teams
  const bulkDeleteTeamsMutation = useMutation({
    mutationFn: async (teamIds: string[]) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      // Delete all teams in parallel
      await Promise.all(
        teamIds.map((teamId) => deleteProjectTeam(projectId, teamId)),
      )
    },
    onSuccess: async () => {
      // Refetch teams list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: ['teams', 'project', projectId],
      })
      toast.success(
        `Successfully deleted ${selectedTeams.size} team${selectedTeams.size > 1 ? 's' : ''}`,
      )
      setSelectedTeams(new Set())
      setDeleteTeamDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to delete teams')
    },
  })

  const handleBulkDeleteTeams = () => {
    if (selectedTeams.size === 0) return
    setDeleteTeamDialogOpen(true)
  }

  const confirmBulkDeleteTeams = () => {
    if (selectedTeams.size === 0) return
    bulkDeleteTeamsMutation.mutate(Array.from(selectedTeams))
  }

  const toggleTeam = (teamId: string) => {
    const newSelected = new Set(selectedTeams)
    if (newSelected.has(teamId)) {
      newSelected.delete(teamId)
    } else {
      newSelected.add(teamId)
    }
    setSelectedTeams(newSelected)
  }

  const toggleAllTeams = () => {
    if (selectedTeams.size === paginatedTeams.length) {
      setSelectedTeams(new Set())
    } else {
      setSelectedTeams(new Set(paginatedTeams.map((t) => t.id)))
    }
  }

  const handleTeamsPageChange = (page: number) => {
    setTeamsRequestedPage(page)
    setSelectedTeams(new Set()) // Clear selection on page change
  }

  const handleTeamsPageSizeChange = (newPageSize: number) => {
    setTeamsPageSize(newPageSize)
    setTeamsRequestedPage(1)
    setTeamsDisplayedPage(1)
    setSelectedTeams(new Set()) // Clear selection on page size change
  }

  // Update tabs with dynamic user and team counts and route paths
  const tabs: Tab[] = useMemo(
    () => [
      {
        id: 'users',
        label: 'Users',
        to: '/projects/$projectId/auth/',
        params: { projectId: projectId as string },
      },
      {
        id: 'teams',
        label: 'Teams',
        to: '/projects/$projectId/auth/teams',
        params: { projectId: projectId as string },
      },
      {
        id: 'security',
        label: 'Security',
        to: '/projects/$projectId/auth/security',
        params: { projectId: projectId as string },
      },
      {
        id: 'templates',
        label: 'Templates',
        to: '/projects/$projectId/auth/templates',
        params: { projectId: projectId as string },
      },
      {
        id: 'settings',
        label: 'Settings',
        to: '/projects/$projectId/auth/settings',
        params: { projectId: projectId as string },
      },
    ],
    [projectId],
  )

  const getCreateLabel = () => {
    switch (activeTab) {
      case 'users':
        return 'Create User'
      case 'teams':
        return 'Create Team'
      case 'templates':
        return 'Create Template'
      default:
        return undefined
    }
  }

  const handleCreateUser = (userData: {
    userId?: string
    email?: string
    phone?: string
    password?: string
    name?: string
  }) => {
    createUserMutation.mutate(userData, {
      onSuccess: () => {
        toast.success('User created successfully')
        setCreateUserDialogOpen(false)
      },
      onError: (error: Error) => {
        toast.error(error.message || 'Failed to create user')
      },
    })
  }

  const handleCreateTeam = (teamData: { teamId?: string; name: string }) => {
    createTeamMutation.mutate(teamData, {
      onSuccess: () => {
        toast.success('Team created successfully')
        setCreateTeamDialogOpen(false)
      },
      onError: (error: Error) => {
        toast.error(error.message || 'Failed to create team')
      },
    })
  }

  const handleCreateClick = () => {
    if (activeTab === 'users') {
      setCreateUserDialogOpen(true)
    } else if (activeTab === 'teams') {
      setCreateTeamDialogOpen(true)
    }
  }

  // Get project data for SMTP status
  const { project } = useProject(projectId)
  const isSmtpEnabled = (project as unknown)?.smtpEnabled ?? false

  // SMTP alert for templates tab
  const smtpAlert =
    activeTab === 'templates' && !isSmtpEnabled ? (
      <div className="border-b border-border bg-amber-500/5">
        <div className="mx-auto w-full max-w-7xl px-4 py-3 sm:px-6">
          <Alert
            variant="default"
            className="border-amber-500/30 bg-transparent"
          >
            <AlertCircle className="h-4 w-4 text-amber-500" />
            <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
              SMTP server required
            </AlertTitle>
            <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
              <span className="inline">
                Custom SMTP server is required to edit email templates.{' '}
                <Link
                  to="/projects/$projectId/settings/smtp"
                  params={{ projectId: projectId as string }}
                  className="font-medium underline hover:no-underline inline"
                >
                  Set up SMTP server
                </Link>{' '}
                to customize your email templates.
              </span>
            </AlertDescription>
          </Alert>
        </div>
      </div>
    ) : undefined

  const ViewToggle = ({
    viewMode,
    onViewModeChange,
  }: {
    viewMode: 'list' | 'grid'
    onViewModeChange: (mode: 'list' | 'grid') => void
  }) => (
    <div className="flex items-center gap-1 rounded-md border border-border bg-muted/30 p-0.5">
      <Button
        variant="ghost"
        size="sm"
        className={cn(
          'h-7 w-7 p-0',
          viewMode === 'list' ? 'bg-background' : 'hover:bg-transparent',
        )}
        onClick={() => onViewModeChange('list')}
      >
        <List className="h-4 w-4" />
      </Button>
      <Button
        variant="ghost"
        size="sm"
        className={cn(
          'h-7 w-7 p-0',
          viewMode === 'grid' ? 'bg-background' : 'hover:bg-transparent',
        )}
        onClick={() => onViewModeChange('grid')}
      >
        <LayoutGrid className="h-4 w-4" />
      </Button>
    </div>
  )

  // Don't render if we're on a detail route (those have their own components)
  // This check is placed after all hooks to comply with React's rules of hooks
  if (isUserDetailRoute || isTeamDetailRoute) {
    return null
  }

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title="Auth"
        tabs={tabs}
        activeTab={activeTab}
        searchPlaceholder={
          activeTab === 'security' ||
          activeTab === 'settings' ||
          activeTab === 'templates'
            ? undefined
            : `Search ${activeTab}...`
        }
        searchValue={
          activeTab === 'users'
            ? usersSearchValue
            : activeTab === 'teams'
              ? teamsSearchValue
              : undefined
        }
        onSearchChange={
          activeTab === 'users'
            ? handleUsersSearchChange
            : activeTab === 'teams'
              ? handleTeamsSearchChange
              : undefined
        }
        createLabel={activeTab === 'templates' ? undefined : getCreateLabel()}
        onCreate={activeTab === 'templates' ? undefined : handleCreateClick}
        showFilters={activeTab === 'users'}
        fullWidthBorder
        contentAfterBorder={smtpAlert}
        rightContent={
          activeTab === 'users' ? (
            <ViewToggle
              viewMode={usersViewMode}
              onViewModeChange={setUsersViewMode}
            />
          ) : activeTab === 'teams' ? (
            <ViewToggle
              viewMode={teamsViewMode}
              onViewModeChange={setTeamsViewMode}
            />
          ) : undefined
        }
      />

      <div
        className={cn(
          'mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6',
          (activeTab === 'security' ||
            activeTab === 'templates' ||
            activeTab === 'settings') &&
            'pt-4 sm:pt-6',
        )}
      >
        {activeTab === 'users' && (
          <>
            {isDebugModeOpen && <LightningCollectorGame />}
            {showUsersLoading ? (
              <div className="rounded-lg border border-border bg-card py-12 text-center">
                <div className="text-muted-foreground">Loading users...</div>
              </div>
            ) : usersViewMode === 'list' ? (
              paginatedUsers.length > 0 ? (
                <>
                  <div className="rounded-lg border border-border bg-card overflow-hidden">
                    <Table>
                      <TableHeader>
                        <TableRow className="hover:bg-transparent border-b border-border">
                          <TableHead className="w-[40px] px-4">
                            <Checkbox
                              checked={
                                paginatedUsers.length > 0 &&
                                selectedUsers.size === paginatedUsers.length
                              }
                              onCheckedChange={toggleAllUsers}
                            />
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            User
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            Contact
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-center">
                            Verification
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-center w-[80px]">
                            MFA
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                            Joined
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                            Last Active
                          </TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {paginatedUsers.map((user) => {
                          const emailVerified = user.emailVerification || false
                          const phoneVerified = user.phoneVerification || false
                          const isBlocked = user.status === false
                          const hasEmail = !!user.email
                          const hasPhone = !!user.phone

                          return (
                            <TableRow
                              key={user.$id}
                              className={cn(
                                'cursor-pointer transition-colors border-b border-border/50',
                                selectedUsers.has(user.$id)
                                  ? 'bg-sky-100 dark:bg-sky-950'
                                  : 'hover:bg-muted/30',
                              )}
                              onClick={(e) => {
                                // Don't navigate if clicking on checkbox, link, or their containers
                                const target = e.target as HTMLElement
                                if (
                                  target.closest('button') ||
                                  target.closest('[role="checkbox"]') ||
                                  target.closest('a')
                                ) {
                                  return
                                }
                                navigate({
                                  to: '/projects/$projectId/auth/users/$userId',
                                  params: {
                                    projectId: projectId!,
                                    userId: user.$id,
                                  },
                                })
                              }}
                            >
                              <TableCell
                                onClick={(e) => e.stopPropagation()}
                                className="px-4 py-3"
                              >
                                <Checkbox
                                  checked={selectedUsers.has(user.$id)}
                                  onCheckedChange={() => toggleUser(user.$id)}
                                />
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                <Link
                                  to="/projects/$projectId/auth/users/$userId"
                                  params={{
                                    projectId: projectId!,
                                    userId: user.$id,
                                  }}
                                  className="block group"
                                >
                                  <div className="flex items-center gap-3 min-w-0">
                                    <InitialsAvatar
                                      name={user.name || user.email || ''}
                                      size="sm"
                                      className="shrink-0"
                                    />
                                    <div className="flex-1 min-w-0">
                                      <p className="truncate text-[13px] font-medium text-foreground group-hover:text-primary transition-colors">
                                        {user.name || 'No name'}
                                      </p>
                                      <div className="mt-0.5">
                                        <CopyableId id={user.$id} size="xs" />
                                      </div>
                                    </div>
                                  </div>
                                </Link>
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                <Link
                                  to="/projects/$projectId/auth/users/$userId"
                                  params={{
                                    projectId: projectId!,
                                    userId: user.$id,
                                  }}
                                  className="block"
                                >
                                  <div className="space-y-1">
                                    {hasEmail && (
                                      <div className="flex items-center gap-1.5 min-w-0">
                                        <Mail className="h-3 w-3 shrink-0 text-muted-foreground/60" />
                                        <span className="truncate text-[12px] text-foreground font-medium">
                                          {user.email}
                                        </span>
                                      </div>
                                    )}
                                    {hasPhone && (
                                      <div className="flex items-center gap-1.5 min-w-0">
                                        <Phone className="h-3 w-3 shrink-0 text-muted-foreground/60" />
                                        <span className="truncate text-[12px] text-foreground font-medium">
                                          {user.phone}
                                        </span>
                                      </div>
                                    )}
                                    {!hasEmail && !hasPhone && (
                                      <span className="text-[12px] text-muted-foreground">
                                        -
                                      </span>
                                    )}
                                  </div>
                                </Link>
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                <Link
                                  to="/projects/$projectId/auth/users/$userId"
                                  params={{
                                    projectId: projectId!,
                                    userId: user.$id,
                                  }}
                                  className="block"
                                >
                                  <div className="flex items-center justify-center gap-2 flex-wrap">
                                    {isBlocked ? (
                                      <Badge
                                        variant="destructive"
                                        className="text-[11px] font-medium border px-2 py-0.5"
                                      >
                                        Blocked
                                      </Badge>
                                    ) : (
                                      <>
                                        {hasEmail && (
                                          <Tooltip>
                                            <TooltipTrigger asChild>
                                              <Badge
                                                variant={
                                                  emailVerified
                                                    ? 'success'
                                                    : 'secondary'
                                                }
                                                className="text-[11px] font-medium border px-2 py-0.5"
                                              >
                                                {emailVerified ? (
                                                  <CheckCircle2 className="h-3 w-3" />
                                                ) : (
                                                  <XCircle className="h-3 w-3" />
                                                )}
                                                Email
                                              </Badge>
                                            </TooltipTrigger>
                                            <TooltipContent>
                                              <p className="text-xs">
                                                Email{' '}
                                                {emailVerified
                                                  ? 'verified'
                                                  : 'unverified'}
                                              </p>
                                            </TooltipContent>
                                          </Tooltip>
                                        )}
                                        {hasPhone && (
                                          <Tooltip>
                                            <TooltipTrigger asChild>
                                              <Badge
                                                variant={
                                                  phoneVerified
                                                    ? 'success'
                                                    : 'secondary'
                                                }
                                                className="text-[11px] font-medium border px-2 py-0.5"
                                              >
                                                {phoneVerified ? (
                                                  <CheckCircle2 className="h-3 w-3" />
                                                ) : (
                                                  <XCircle className="h-3 w-3" />
                                                )}
                                                Phone
                                              </Badge>
                                            </TooltipTrigger>
                                            <TooltipContent>
                                              <p className="text-xs">
                                                Phone{' '}
                                                {phoneVerified
                                                  ? 'verified'
                                                  : 'unverified'}
                                              </p>
                                            </TooltipContent>
                                          </Tooltip>
                                        )}
                                        {!hasEmail && !hasPhone && (
                                          <span className="text-[11px] text-muted-foreground">
                                            -
                                          </span>
                                        )}
                                      </>
                                    )}
                                  </div>
                                </Link>
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                <div className="flex items-center justify-center">
                                  <Link
                                    to="/projects/$projectId/auth/users/$userId"
                                    params={{
                                      projectId: projectId!,
                                      userId: user.$id,
                                    }}
                                    className="block"
                                  >
                                    {user.mfaEnabled ? (
                                      <Tooltip>
                                        <TooltipTrigger asChild>
                                          <div className="flex items-center justify-center">
                                            <CheckCircle2 className="h-4 w-4 text-green-600 dark:text-green-400" />
                                          </div>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                          <p className="text-xs">
                                            Multi-factor authentication enabled
                                          </p>
                                        </TooltipContent>
                                      </Tooltip>
                                    ) : (
                                      <Tooltip>
                                        <TooltipTrigger asChild>
                                          <div className="flex items-center justify-center">
                                            <XCircle className="h-4 w-4 text-muted-foreground/40" />
                                          </div>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                          <p className="text-xs">
                                            Multi-factor authentication not
                                            enabled
                                          </p>
                                        </TooltipContent>
                                      </Tooltip>
                                    )}
                                  </Link>
                                </div>
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                <Link
                                  to="/projects/$projectId/auth/users/$userId"
                                  params={{
                                    projectId: projectId!,
                                    userId: user.$id,
                                  }}
                                  className="block text-right"
                                >
                                  <DateTooltip
                                    date={new Date(user.createdAt)}
                                    className="text-[12px] text-muted-foreground font-mono"
                                  />
                                </Link>
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                <Link
                                  to="/projects/$projectId/auth/users/$userId"
                                  params={{
                                    projectId: projectId!,
                                    userId: user.$id,
                                  }}
                                  className="block text-right"
                                >
                                  {user.accessedAt ? (
                                    <span className="text-[12px] text-muted-foreground font-mono">
                                      {formatLastAccessed(user.accessedAt)}
                                    </span>
                                  ) : (
                                    <span className="text-[12px] text-muted-foreground/50 italic">
                                      Never
                                    </span>
                                  )}
                                </Link>
                              </TableCell>
                            </TableRow>
                          )
                        })}
                      </TableBody>
                    </Table>
                  </div>
                  <Pagination
                    currentPage={usersDisplayedPage}
                    totalItems={displayedUsersTotal ?? usersTotal}
                    pageSize={usersPageSize}
                    pageSizeOptions={[10, 25, 50, 100]}
                    onPageChange={handlePageChange}
                    onPageSizeChange={handlePageSizeChange}
                    itemLabel="users"
                  />
                </>
              ) : (
                <EmptyState
                  icon={Users}
                  title={usersSearchValue ? undefined : 'No users yet'}
                  description={
                    usersSearchValue
                      ? undefined
                      : 'Create your first user to get started with authentication'
                  }
                  isEmpty={!usersSearchValue}
                  hasFilters={!!usersSearchValue}
                  variant="card"
                />
              )
            ) : (
              <div className="flex flex-col gap-2">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                  {paginatedUsers.map((user) => {
                    const verification = getUserVerificationStatus(user)
                    const cardStatus =
                      verification.tone === 'warning' ? 'warning' : 'active'

                    const subtitle =
                      [user.email, user.phone].filter(Boolean).join(' • ') ||
                      undefined

                    return (
                      <Link
                        key={user.$id}
                        to="/projects/$projectId/auth/users/$userId"
                        params={{ projectId: projectId!, userId: user.$id }}
                      >
                        <ResourceCard
                          title={user.name || '-'}
                          subtitle={subtitle || '-'}
                          resourceId={user.$id}
                          avatar={user.name || user.email || ''}
                          status={cardStatus}
                          statusLabel={verification.label}
                          metadata={[
                            {
                              label: 'Joined',
                              value: (
                                <DateTooltip
                                  date={user.createdAt}
                                  className="text-[11px] font-medium text-muted-foreground"
                                />
                              ),
                            },
                          ]}
                        />
                      </Link>
                    )
                  })}

                  {paginatedUsers.length === 0 && (
                    <div className="col-span-full">
                      <EmptyState
                        icon={Users}
                        title={usersSearchValue ? undefined : 'No users yet'}
                        description={
                          usersSearchValue
                            ? undefined
                            : 'Create your first user to get started with authentication'
                        }
                        isEmpty={!usersSearchValue}
                        hasFilters={!!usersSearchValue}
                        variant="card"
                      />
                    </div>
                  )}
                </div>
                {paginatedUsers.length > 0 && (
                  <Pagination
                    currentPage={usersDisplayedPage}
                    totalItems={displayedUsersTotal ?? usersTotal}
                    pageSize={usersPageSize}
                    pageSizeOptions={[10, 25, 50, 100]}
                    onPageChange={handlePageChange}
                    onPageSizeChange={handlePageSizeChange}
                    itemLabel="users"
                  />
                )}
              </div>
            )}

            {/* Bulk Delete Action Bar */}
            {selectedUsers.size > 0 && (
              <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2">
                <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
                  <Badge variant="secondary" className="h-6 px-2.5">
                    {selectedUsers.size} user{selectedUsers.size > 1 ? 's' : ''}{' '}
                    selected
                  </Badge>
                  <div className="flex items-center gap-2">
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => setSelectedUsers(new Set())}
                      className="h-8 text-xs"
                    >
                      Cancel
                    </Button>
                    <Button
                      variant="destructive"
                      size="sm"
                      onClick={handleBulkDelete}
                      disabled={bulkDeleteMutation.isPending}
                      className="h-8 gap-2"
                    >
                      Delete
                    </Button>
                  </div>
                </div>
              </div>
            )}

            {/* Bulk Delete Confirmation Dialog */}
            <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
              <DialogContent className="sm:max-w-md p-0">
                <DialogHeader className="px-6 pt-6 text-left">
                  <DialogTitle>Delete Users</DialogTitle>
                  <DialogDescription className="text-[13px] mt-2">
                    Are you sure you want to delete {selectedUsers.size} user
                    {selectedUsers.size > 1 ? 's' : ''}? This action cannot be
                    undone.
                  </DialogDescription>
                </DialogHeader>

                <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                  <Button
                    variant="outline"
                    onClick={() => setDeleteDialogOpen(false)}
                    disabled={bulkDeleteMutation.isPending}
                  >
                    Cancel
                  </Button>
                  <Button
                    variant="destructive"
                    onClick={confirmBulkDelete}
                    disabled={bulkDeleteMutation.isPending}
                  >
                    Delete
                  </Button>
                </div>
              </DialogContent>
            </Dialog>
          </>
        )}

        {activeTab === 'teams' && (
          <>
            {showTeamsLoading ? (
              <div className="rounded-lg border border-border bg-card py-12 text-center">
                <div className="text-muted-foreground">Loading teams...</div>
              </div>
            ) : teamsViewMode === 'list' ? (
              paginatedTeams.length > 0 ? (
                <>
                  <div className="rounded-lg border border-border bg-card overflow-hidden">
                    <Table>
                      <TableHeader>
                        <TableRow className="hover:bg-transparent border-b border-border">
                          <TableHead className="w-[40px] px-4">
                            <Checkbox
                              checked={
                                paginatedTeams.length > 0 &&
                                selectedTeams.size === paginatedTeams.length
                              }
                              onCheckedChange={toggleAllTeams}
                            />
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            Team
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                            Created
                          </TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {paginatedTeams.map((team) => (
                          <TableRow
                            key={team.id}
                            className={cn(
                              'cursor-pointer transition-colors border-b border-border/50',
                              selectedTeams.has(team.id)
                                ? 'bg-sky-100 dark:bg-sky-950'
                                : 'hover:bg-muted/30',
                            )}
                            onClick={(e) => {
                              // Don't navigate if clicking on checkbox, link, or their containers
                              const target = e.target as HTMLElement
                              if (
                                target.closest('button') ||
                                target.closest('[role="checkbox"]') ||
                                target.closest('a')
                              ) {
                                return
                              }
                              navigate({
                                to: '/projects/$projectId/auth/teams/$teamId',
                                params: {
                                  projectId: projectId!,
                                  teamId: team.id,
                                },
                              })
                            }}
                          >
                            <TableCell
                              onClick={(e) => e.stopPropagation()}
                              className="px-4 py-3"
                            >
                              <Checkbox
                                checked={selectedTeams.has(team.id)}
                                onCheckedChange={() => toggleTeam(team.id)}
                              />
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              <Link
                                to="/projects/$projectId/auth/teams/$teamId"
                                params={{
                                  projectId: projectId!,
                                  teamId: team.id,
                                }}
                                className="block group"
                              >
                                <div className="flex items-center gap-3 min-w-0">
                                  <InitialsAvatar
                                    name={team.name || ''}
                                    size="sm"
                                    className="shrink-0"
                                  />
                                  <div className="flex-1 min-w-0">
                                    <p className="truncate text-[13px] font-medium text-foreground group-hover:text-primary transition-colors">
                                      {team.name || 'No name'}
                                    </p>
                                    <div className="mt-0.5">
                                      <CopyableId id={team.id} size="xs" />
                                    </div>
                                  </div>
                                </div>
                              </Link>
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              <Link
                                to="/projects/$projectId/auth/teams/$teamId"
                                params={{
                                  projectId: projectId!,
                                  teamId: team.id,
                                }}
                                className="block text-right"
                              >
                                <DateTooltip
                                  date={new Date(team.createdAt)}
                                  className="text-[12px] text-muted-foreground font-mono"
                                />
                              </Link>
                            </TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </div>
                  <Pagination
                    currentPage={teamsDisplayedPage}
                    totalItems={displayedTeamsTotal ?? teamsTotal}
                    pageSize={teamsPageSize}
                    pageSizeOptions={[10, 25, 50, 100]}
                    onPageChange={handleTeamsPageChange}
                    onPageSizeChange={handleTeamsPageSizeChange}
                    itemLabel="teams"
                  />
                </>
              ) : (
                <EmptyState
                  icon={Users}
                  title={teamsSearchValue ? undefined : 'No teams yet'}
                  description={
                    teamsSearchValue
                      ? undefined
                      : 'Create your first team to organize users into groups'
                  }
                  isEmpty={!teamsSearchValue}
                  hasFilters={!!teamsSearchValue}
                  variant="card"
                />
              )
            ) : (
              <div className="flex flex-col gap-2">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                  {paginatedTeams.map((team) => (
                    <Link
                      key={team.id}
                      to="/projects/$projectId/auth/teams/$teamId"
                      params={{ projectId: projectId!, teamId: team.id }}
                    >
                      <ResourceCard
                        title={team.name || '-'}
                        resourceId={team.id}
                        avatar={team.name || '-'}
                        metadata={[
                          {
                            label: 'Created',
                            value: (
                              <DateTooltip
                                date={team.createdAt}
                                className="text-[11px] font-medium text-muted-foreground"
                              />
                            ),
                          },
                        ]}
                      />
                    </Link>
                  ))}

                  {paginatedTeams.length === 0 && (
                    <div className="col-span-full">
                      <EmptyState
                        icon={Users}
                        title={teamsSearchValue ? undefined : 'No teams yet'}
                        description={
                          teamsSearchValue
                            ? undefined
                            : 'Create your first team to organize users into groups'
                        }
                        isEmpty={!teamsSearchValue}
                        hasFilters={!!teamsSearchValue}
                        variant="card"
                      />
                    </div>
                  )}
                </div>
                {paginatedTeams.length > 0 && (
                  <Pagination
                    currentPage={teamsDisplayedPage}
                    totalItems={displayedTeamsTotal ?? teamsTotal}
                    pageSize={teamsPageSize}
                    pageSizeOptions={[10, 25, 50, 100]}
                    onPageChange={handleTeamsPageChange}
                    onPageSizeChange={handleTeamsPageSizeChange}
                    itemLabel="teams"
                  />
                )}
              </div>
            )}

            {/* Bulk Delete Teams Action Bar */}
            {selectedTeams.size > 0 && (
              <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2">
                <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
                  <Badge variant="secondary" className="h-6 px-2.5">
                    {selectedTeams.size} team{selectedTeams.size > 1 ? 's' : ''}{' '}
                    selected
                  </Badge>
                  <div className="flex items-center gap-2">
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => setSelectedTeams(new Set())}
                      className="h-8 text-xs"
                    >
                      Cancel
                    </Button>
                    <Button
                      variant="destructive"
                      size="sm"
                      onClick={handleBulkDeleteTeams}
                      disabled={bulkDeleteTeamsMutation.isPending}
                      className="h-8 gap-2"
                    >
                      Delete
                    </Button>
                  </div>
                </div>
              </div>
            )}

            {/* Bulk Delete Teams Confirmation Dialog */}
            <Dialog
              open={deleteTeamDialogOpen}
              onOpenChange={setDeleteTeamDialogOpen}
            >
              <DialogContent className="sm:max-w-md p-0">
                <DialogHeader className="px-6 pt-6 text-left">
                  <DialogTitle>Delete Teams</DialogTitle>
                  <DialogDescription className="text-[13px] mt-2">
                    Are you sure you want to delete {selectedTeams.size} team
                    {selectedTeams.size > 1 ? 's' : ''}? This action cannot be
                    undone.
                  </DialogDescription>
                </DialogHeader>

                <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                  <Button
                    variant="outline"
                    onClick={() => setDeleteTeamDialogOpen(false)}
                    disabled={bulkDeleteTeamsMutation.isPending}
                  >
                    Cancel
                  </Button>
                  <Button
                    variant="destructive"
                    onClick={confirmBulkDeleteTeams}
                    disabled={bulkDeleteTeamsMutation.isPending}
                  >
                    Delete
                  </Button>
                </div>
              </DialogContent>
            </Dialog>
          </>
        )}

        {activeTab === 'security' && projectId && (
          <Security projectId={projectId} />
        )}

        {activeTab === 'templates' && projectId && (
          <Templates projectId={projectId} />
        )}

        {activeTab === 'settings' && projectId && (
          <AuthSettings projectId={projectId} />
        )}
      </div>

      <CreateUserDrawer
        open={createUserDialogOpen}
        onOpenChange={setCreateUserDialogOpen}
        onCreate={handleCreateUser}
        isLoading={createUserMutation.isPending}
      />

      <CreateTeamDrawer
        open={createTeamDialogOpen}
        onOpenChange={setCreateTeamDialogOpen}
        onCreate={handleCreateTeam}
        isLoading={createTeamMutation.isPending}
      />
    </div>
  )
}
