import { useState, useMemo, useEffect, useRef } from 'react'
import {
  useParams,
  useLocation,
  Link,
  useNavigate,
  useSearch,
} from '@tanstack/react-router'
import {
  getSearch,
  getPage,
  getLimit,
  getQueryParam,
  queryParamToMap,
  mapToQueryParam,
  buildListSearchParams,
  buildFilterQueryString,
  buildFilterTagFromCompactKey,
  getOperatorsForType,
  usersFilterColumns,
} from '@/lib/table-filters'
import type { CompactFilterKey } from '@/lib/table-filters'
import { cn } from '@/lib/utils'
import {
  Users,
  LayoutGrid,
  List,
  Filter,
  AlertCircle,
  CheckCircle2,
  XCircle,
  Mail,
  Phone,
  X,
} from 'lucide-react'
import {
  useProjectUsers,
  useProjectTeams,
  useCreateProjectUser,
  useCreateProjectTeam,
  useProject,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import { canShowAuthSecuritySettings, canCreateUser, canCreateTeam } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { LightningCollectorGame } from './LightningCollectorGame'
import { useDebugMode } from '@/components/global/providers/DebugMode'
import { CreateUserDrawer } from './CreateUserDrawer'
import { CreateTeamDrawer } from './CreateTeamDrawer'
import { Security } from './Security'
import { AuthSettings } from './Settings'
import { Templates } from './Templates'
import { toast } from 'sonner'

export type UsersListSearch = {
  search?: string
  query?: string
  page?: number
  limit?: number
}

export function View({
  usersListSearch,
}: {
  usersListSearch?: UsersListSearch
} = {}) {
  const { projectId } = useParams({
    strict: false,
  })
  const location = useLocation()
  const navigate = useNavigate()
  const search = useSearch({ strict: false }) as {
    create?: string
    search?: string
    page?: number
    limit?: number
    query?: string
  }
  const { isDebugModeOpen } = useDebugMode()

  const isAuthUsersIndex =
    location.pathname.replace(/\/$/, '') === `/projects/${projectId}/auth`

  // URL-backed list params for users tab. Prefer validated search from index route (usersListSearch) so tags and table update immediately after navigate; fallback to parsing location.
  const usersListParams = useMemo(() => {
    if (!isAuthUsersIndex) return null
    if (typeof window === 'undefined') return null
    if (usersListSearch) {
      return {
        search: usersListSearch.search,
        page: usersListSearch.page ?? 1,
        limit: usersListSearch.limit ?? 25,
        filterMap: queryParamToMap(usersListSearch.query ?? null),
      }
    }
    const url = new URL(location.pathname + location.search, window.location.origin)
    return {
      search: getSearch(url),
      page: getPage(url, 1),
      limit: getLimit(url, 25),
      filterMap: queryParamToMap(getQueryParam(url)),
    }
  }, [isAuthUsersIndex, usersListSearch, location.pathname, location.search, projectId])

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

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const showAuthSecuritySettings =
    canShowAuthSecuritySettings(access, features)

  const urlPage = usersListParams?.page ?? 1
  const urlLimit = usersListParams?.limit ?? 25
  const urlSearch = usersListParams?.search
  const usersFilterMap = usersListParams?.filterMap ?? new Map()
  const usersFilterQueries =
    usersFilterMap.size > 0 ? Array.from(usersFilterMap.values()) : undefined

  const [usersSearchInput, setUsersSearchInput] = useState('')
  const usersSearchDebounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)

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

  // Open create team drawer when ?create=team (e.g. from command center)
  useEffect(() => {
    if (search?.create === 'team' && !createTeamDialogOpen) {
      setCreateTeamDialogOpen(true)
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
  }, [search?.create, createTeamDialogOpen, navigate, location.pathname])

  // Sync users search input from URL (e.g. back button)
  useEffect(() => {
    setUsersSearchInput(urlSearch ?? '')
  }, [urlSearch])

  const usersFilterQueryString =
    usersFilterMap.size > 0 ? mapToQueryParam(usersFilterMap) : ''

  // Debounced navigate when users search input changes
  useEffect(() => {
    if (usersSearchDebounceRef.current) clearTimeout(usersSearchDebounceRef.current)
    usersSearchDebounceRef.current = setTimeout(() => {
      const trimmed = usersSearchInput.trim()
      if (trimmed === (urlSearch ?? '')) return
      navigate({
        to: '/projects/$projectId/auth/',
        params: { projectId: projectId! },
        search: (prev: Record<string, unknown>) => {
          const next = {
            ...prev,
            ...buildListSearchParams({
              search: trimmed || undefined,
              query: usersFilterQueryString || undefined,
              page: 1,
              limit: urlLimit,
            }),
          }
          // Remove search param when cleared so URL and results update
          if (!trimmed) delete next.search
          return next
        },
        replace: true,
      })
    }, 300)
    return () => {
      if (usersSearchDebounceRef.current) clearTimeout(usersSearchDebounceRef.current)
    }
  }, [
    usersSearchInput,
    projectId,
    navigate,
    urlLimit,
    urlSearch,
    usersFilterQueryString,
  ])

  const [usersDisplayedPage, setUsersDisplayedPage] = useState(urlPage)
  const [usersFiltersOpen, setUsersFiltersOpen] = useState(false)
  const [filterColumnId, setFilterColumnId] = useState<string>('')
  const [filterOperatorKey, setFilterOperatorKey] = useState<string>('')
  const [filterValue, setFilterValue] = useState<string>('')
  const [filterValueEnd, setFilterValueEnd] = useState<string>('')

  const usersFilterEntries = Array.from(usersFilterMap.entries())

  const queryClient = useQueryClient()

  // Clear selection when navigating between pages/routes or when search/filters change
  useEffect(() => {
    setSelectedUsers(new Set())
    setDeleteDialogOpen(false)
    setSelectedTeams(new Set())
    setDeleteTeamDialogOpen(false)
  }, [location.pathname, projectId, urlSearch, teamsSearchValue, usersFilterMap.size])

  // Pagination state for teams
  const [teamsRequestedPage, setTeamsRequestedPage] = useState(1)
  const [teamsDisplayedPage, setTeamsDisplayedPage] = useState(1)
  const [teamsPageSize, setTeamsPageSize] = useState(25)

  // Fetch users for the requested page (URL page - triggers load when user changes page)
  const {
    total: usersTotal,
    isLoading: usersLoading,
    isFetching: usersFetching,
  } = useProjectUsers(
    projectId,
    urlPage - 1,
    urlLimit,
    urlSearch ?? undefined,
    usersFilterQueries,
  )

  // Fetch users for the displayed page (what we show - stays until new page is ready)
  const {
    users: apiUsers,
    total: displayedUsersTotal,
    isLoading: usersDisplayedLoading,
  } = useProjectUsers(
    projectId,
    usersDisplayedPage - 1,
    urlLimit,
    urlSearch ?? undefined,
    usersFilterQueries,
  )

  // Update displayed users page only when requested page data is ready (no flash)
  useEffect(() => {
    if (
      !usersFetching &&
      urlPage !== usersDisplayedPage &&
      !usersLoading
    ) {
      setUsersDisplayedPage(urlPage)
    }
  }, [usersFetching, usersLoading, urlPage, usersDisplayedPage])

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

  const handleUsersSearchChange = (value: string) => {
    setUsersSearchInput(value)
  }

  const applyUsersFilter = () => {
    const col = usersFilterColumns.find((c) => c.id === filterColumnId)
    if (!col || !filterOperatorKey) return
    const op = getOperatorsForType(col.type).find((o) => o.key === filterOperatorKey)
    if (!op) return
    const isBetweenOp =
      filterOperatorKey === 'between' || filterOperatorKey === 'notBetween'
    let val: string | number | boolean | undefined = op.noValue
      ? undefined
      : isBetweenOp
        ? `${filterValue.trim()},${filterValueEnd.trim()}`
        : filterValue.trim() || undefined
    if (val !== undefined && val !== '' && !isBetweenOp) {
      if (col.type === 'integer') {
        const n = Number(val)
        val = Number.isNaN(n) ? String(val) : n
      } else if (col.type === 'double') {
        const n = Number(val)
        val = Number.isNaN(n) ? String(val) : n
      } else if (col.id === 'status' && (val === 'enabled' || val === 'disabled')) {
        // Appwrite user status is boolean: true = enabled, false = disabled
        val = val === 'enabled'
      }
      // datetime, enum, string etc. stay as string
    }
    const queryString = buildFilterQueryString(
      filterOperatorKey,
      filterColumnId,
      val,
    )
    const compactKey: CompactFilterKey = {
      c: filterColumnId,
      o: filterOperatorKey,
      ...(val !== undefined && val !== '' ? { v: val } : {}),
    }
    const newMap = new Map(usersFilterMap)
    newMap.set(compactKey, queryString)
    const queryEncoded = mapToQueryParam(newMap)
    navigate({
      to: '/projects/$projectId/auth/',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => ({
        ...prev,
        ...buildListSearchParams({
          search: urlSearch,
          query: queryEncoded,
          page: 1,
          limit: urlLimit,
        }),
      }),
      replace: true,
    })
    setFilterValue('')
    setFilterValueEnd('')
    setUsersFiltersOpen(false)
  }

  const removeUsersFilter = (key: CompactFilterKey) => {
    const newMap = new Map(usersFilterMap)
    newMap.delete(key)
    navigate({
      to: '/projects/$projectId/auth/',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => {
        const next = {
          ...prev,
          ...buildListSearchParams({
            search: urlSearch,
            query: newMap.size > 0 ? mapToQueryParam(newMap) : undefined,
            page: 1,
            limit: urlLimit,
          }),
        }
        if (newMap.size === 0) delete next.query
        return next
      },
      replace: true,
    })
  }

  const clearAllUsersFilters = () => {
    navigate({
      to: '/projects/$projectId/auth/',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => {
        const next = {
          ...prev,
          ...buildListSearchParams({
            search: urlSearch,
            page: 1,
            limit: urlLimit,
          }),
        }
        delete next.query
        return next
      },
      replace: true,
    })
    setUsersFiltersOpen(false)
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

  const handleUsersPageChange = (page: number) => {
    navigate({
      to: '/projects/$projectId/auth/',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => {
        const next = {
          ...prev,
          ...buildListSearchParams({
            search: urlSearch,
            query:
              usersFilterMap.size > 0 ? mapToQueryParam(usersFilterMap) : undefined,
            page,
            limit: urlLimit,
          }),
        }
        // When going to page 1, buildListSearchParams omits page so prev.page would persist; remove it explicitly
        if (page === 1) delete next.page
        return next
      },
      replace: true,
    })
    setSelectedUsers(new Set())
  }

  const handleUsersPageSizeChange = (newPageSize: number) => {
    navigate({
      to: '/projects/$projectId/auth/',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => {
        const next = {
          ...prev,
          ...buildListSearchParams({
            search: urlSearch,
            query:
              usersFilterMap.size > 0 ? mapToQueryParam(usersFilterMap) : undefined,
            page: 1,
            limit: newPageSize,
          }),
        }
        // Reset to page 1 when changing size; buildListSearchParams omits page when 1 so remove it
        delete next.page
        return next
      },
      replace: true,
    })
    setSelectedUsers(new Set())
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
      ...(showAuthSecuritySettings
        ? [
            {
              id: 'security' as const,
              label: 'Security',
              to: '/projects/$projectId/auth/security',
              params: { projectId: projectId as string },
            },
            {
              id: 'templates' as const,
              label: 'Templates',
              to: '/projects/$projectId/auth/templates',
              params: { projectId: projectId as string },
            },
            {
              id: 'settings' as const,
              label: 'Settings',
              to: '/projects/$projectId/auth/settings',
              params: { projectId: projectId as string },
            },
          ]
        : []),
    ],
    [projectId, showAuthSecuritySettings],
  )

  // Redirect from security/templates/settings when user lacks permission
  useEffect(() => {
    if (showAuthSecuritySettings || !projectId) return
    if (
      activeTab === 'security' ||
      activeTab === 'settings' ||
      activeTab === 'templates'
    ) {
      navigate({
        to: '/projects/$projectId/auth/',
        params: { projectId },
        replace: true,
      })
    }
  }, [showAuthSecuritySettings, activeTab, projectId, navigate])

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

  // Get project data for SMTP status (project, features, access already from above)
  const isSmtpEnabled = (project as unknown)?.smtpEnabled ?? false

  const noCreatePermission =
    activeTab === 'users'
      ? !canCreateUser(access, features)
      : activeTab === 'teams'
        ? !canCreateTeam(access, features)
        : false
  const createPermissionTooltip =
    noCreatePermission && activeTab === 'users'
      ? "You don't have permission to create users."
      : noCreatePermission && activeTab === 'teams'
        ? "You don't have permission to create teams."
        : undefined

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
            ? usersSearchInput
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
        createDisabled={noCreatePermission}
        createDisabledTooltip={createPermissionTooltip}
        showFilters={activeTab === 'users'}
        filterTrigger={
          activeTab === 'users' ? (
            <Popover open={usersFiltersOpen} onOpenChange={setUsersFiltersOpen}>
              <PopoverTrigger asChild>
                <Button
                  variant="outline"
                  size="sm"
                  className="h-9 shrink-0 gap-2 border-border bg-transparent text-[13px] text-muted-foreground hover:bg-accent hover:text-foreground"
                >
                  <Filter className="h-3.5 w-3.5" />
                  Filters
                  {usersFilterMap.size > 0 && (
                    <span className="ml-1 flex size-5 items-center justify-center rounded-full bg-primary/20 text-[11px] font-medium text-primary">
                      {usersFilterMap.size}
                    </span>
                  )}
                </Button>
              </PopoverTrigger>

              <PopoverContent
                className="w-72 p-0"
                align="start"
                sideOffset={6}
              >
                {/* Current filters list + Clear all (above Add condition) */}
                {usersFilterMap.size > 0 && (
                  <>
                    <div className="border-b border-border px-3 py-1.5">
                      <p className="text-[11px] font-medium text-foreground">
                        Active filters
                      </p>
                    </div>
                    <div className="max-h-40 overflow-y-auto space-y-1 p-2">
                      {usersFilterEntries.map(([key, _queryStr]) => {
                        const tag = buildFilterTagFromCompactKey(
                          key,
                          usersFilterColumns,
                        )
                        const tagLabel = tag.tag.replace(/\*\*(.*?)\*\*/g, '$1')
                        return (
                          <div
                            key={`${key.c}-${key.o}-${JSON.stringify(key.v ?? '')}`}
                            className="flex select-none items-center justify-between gap-2 rounded-md bg-muted/50 px-1.5 py-1"
                          >
                            <Tooltip>
                              <TooltipTrigger asChild>
                                <span className="truncate text-[11px] text-foreground">
                                  {tagLabel}
                                </span>
                              </TooltipTrigger>
                              <TooltipContent>
                                <p className="text-xs">{tagLabel}</p>
                              </TooltipContent>
                            </Tooltip>
                            <button
                              type="button"
                              onClick={() => removeUsersFilter(key)}
                              className="shrink-0 cursor-pointer rounded p-0.5 hover:bg-muted"
                              aria-label="Remove filter"
                            >
                              <X className="h-2.5 w-2.5" />
                            </button>
                          </div>
                        )
                      })}
                    </div>
                    <div className="border-t border-border p-2">
                      <Button
                        variant="ghost"
                        size="sm"
                        className="h-8 w-full text-[12px]"
                        onClick={() => {
                          clearAllUsersFilters()
                          setUsersFiltersOpen(false)
                        }}
                      >
                        Clear all
                      </Button>
                    </div>
                  </>
                )}

                <div
                  className={
                    usersFilterMap.size > 0
                      ? 'border-t border-border px-4 pt-4 pb-2'
                      : 'px-4 pt-4 pb-2'
                  }
                >
                  <h3 className="text-[13px] font-semibold text-foreground">
                    Add condition
                  </h3>
                  <p className="text-[12px] text-muted-foreground mt-1">
                    Filter users by column, operator and value.
                  </p>
                </div>
                  <form
                    onSubmit={(e) => {
                      e.preventDefault()
                      applyUsersFilter()
                    }}
                    className="contents"
                  >
                  <div className="border-t border-border px-4 py-3 space-y-3">
                    <div>
                      <label className="text-[12px] text-muted-foreground mb-1.5 block">
                        Column
                      </label>
                      <Select
                        value={filterColumnId}
                        onValueChange={(v) => {
                          setFilterColumnId(v)
                          setFilterValue('')
                          setFilterValueEnd('')
                          const col = usersFilterColumns.find((c) => c.id === v)
                          const firstOp = col
                            ? getOperatorsForType(col.type)[0]
                            : null
                          setFilterOperatorKey(firstOp?.key ?? '')
                        }}
                      >
                        <SelectTrigger className="h-9 text-[13px]">
                          <SelectValue placeholder="Select column" />
                        </SelectTrigger>
                        <SelectContent className="z-[200]">
                          {usersFilterColumns.map((col) => (
                            <SelectItem
                              key={col.id}
                              value={col.id}
                              className="text-[13px]"
                            >
                              {col.title}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                    <div>
                      <label className="text-[12px] text-muted-foreground mb-1.5 block">
                        Operator
                      </label>
                      <Select
                        value={filterOperatorKey}
                        onValueChange={(v) => {
                          setFilterOperatorKey(v)
                          setFilterValueEnd('')
                        }}
                        disabled={!filterColumnId}
                      >
                        <SelectTrigger className="h-9 text-[13px]">
                          <SelectValue placeholder="Operator" />
                        </SelectTrigger>
                        <SelectContent className="z-[200]">
                          {(filterColumnId
                            ? getOperatorsForType(
                                usersFilterColumns.find(
                                  (c) => c.id === filterColumnId,
                                )!.type,
                              )
                            : []
                          ).map((op) => (
                            <SelectItem
                              key={op.key}
                              value={op.key}
                              className="text-[13px]"
                            >
                              {op.label}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                    {filterColumnId &&
                      (() => {
                        const col = usersFilterColumns.find(
                          (c) => c.id === filterColumnId,
                        )
                        if (!col) return null
                        const op = getOperatorsForType(col.type).find(
                          (o) => o.key === filterOperatorKey,
                        )
                        if (op?.noValue) return null
                        const label = (
                          <label className="text-[12px] text-muted-foreground mb-1.5 block">
                            Value
                          </label>
                        )
                        const inputClass = 'h-9 text-[13px]'
                        const toDatetimeLocal = (iso: string) => {
                          if (!iso?.trim()) return ''
                          const d = new Date(iso)
                          if (Number.isNaN(d.getTime())) return ''
                          const pad = (n: number) => String(n).padStart(2, '0')
                          return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
                        }
                        const isBetweenOp =
                          filterOperatorKey === 'between' ||
                          filterOperatorKey === 'notBetween'
                        if (isBetweenOp) {
                          const subLabel = 'text-[11px] text-muted-foreground mb-1 block'
                          if (col.type === 'datetime') {
                            return (
                              <div key="value-between-datetime" className="space-y-3">
                                <div>
                                  <span className={subLabel}>Start</span>
                                  <Input
                                    type="datetime-local"
                                    className={inputClass}
                                    value={toDatetimeLocal(filterValue)}
                                    onChange={(e) => {
                                      const v = e.target.value
                                      setFilterValue(v ? new Date(v).toISOString() : '')
                                    }}
                                  />
                                </div>
                                <div>
                                  <span className={subLabel}>End</span>
                                  <Input
                                    type="datetime-local"
                                    className={inputClass}
                                    value={toDatetimeLocal(filterValueEnd)}
                                    onChange={(e) => {
                                      const v = e.target.value
                                      setFilterValueEnd(v ? new Date(v).toISOString() : '')
                                    }}
                                  />
                                </div>
                              </div>
                            )
                          }
                          if (col.type === 'integer' || col.type === 'double') {
                            return (
                              <div key="value-between-number" className="space-y-3">
                                <div>
                                  <span className={subLabel}>Start</span>
                                  <Input
                                    type="number"
                                    step={col.type === 'integer' ? 1 : 'any'}
                                    className={inputClass}
                                    value={filterValue}
                                    onChange={(e) => setFilterValue(e.target.value)}
                                    placeholder="Min"
                                  />
                                </div>
                                <div>
                                  <span className={subLabel}>End</span>
                                  <Input
                                    type="number"
                                    step={col.type === 'integer' ? 1 : 'any'}
                                    className={inputClass}
                                    value={filterValueEnd}
                                    onChange={(e) => setFilterValueEnd(e.target.value)}
                                    placeholder="Max"
                                  />
                                </div>
                              </div>
                            )
                          }
                          return null
                        }
                        if (col.type === 'enum' && col.elements?.length) {
                          return (
                            <div key="value-enum">
                              {label}
                              <Select
                                value={filterValue}
                                onValueChange={setFilterValue}
                              >
                                <SelectTrigger className={inputClass}>
                                  <SelectValue placeholder="Select value" />
                                </SelectTrigger>
                                <SelectContent className="z-[200]">
                                  {col.elements.map((el) => (
                                    <SelectItem
                                      key={String(el.value)}
                                      value={String(el.value)}
                                      className="text-[13px]"
                                    >
                                      {el.label}
                                    </SelectItem>
                                  ))}
                                </SelectContent>
                              </Select>
                            </div>
                          )
                        }
                        if (col.type === 'boolean') {
                          return (
                            <div key="value-bool">
                              {label}
                              <Select
                                value={filterValue}
                                onValueChange={setFilterValue}
                              >
                                <SelectTrigger className={inputClass}>
                                  <SelectValue placeholder="Select" />
                                </SelectTrigger>
                                <SelectContent className="z-[200]">
                                  <SelectItem value="true" className="text-[13px]">
                                    True
                                  </SelectItem>
                                  <SelectItem value="false" className="text-[13px]">
                                    False
                                  </SelectItem>
                                </SelectContent>
                              </Select>
                            </div>
                          )
                        }
                        if (col.type === 'datetime') {
                          return (
                            <div key="value-datetime">
                              {label}
                              <Input
                                type="datetime-local"
                                className={inputClass}
                                value={toDatetimeLocal(filterValue)}
                                onChange={(e) => {
                                  const v = e.target.value
                                  setFilterValue(v ? new Date(v).toISOString() : '')
                                }}
                              />
                            </div>
                          )
                        }
                        if (col.type === 'integer') {
                          return (
                            <div key="value-int">
                              {label}
                              <Input
                                type="number"
                                step={1}
                                className={inputClass}
                                value={filterValue}
                                onChange={(e) => setFilterValue(e.target.value)}
                                placeholder="Number"
                              />
                            </div>
                          )
                        }
                        if (col.type === 'double') {
                          return (
                            <div key="value-double">
                              {label}
                              <Input
                                type="number"
                                step="any"
                                className={inputClass}
                                value={filterValue}
                                onChange={(e) => setFilterValue(e.target.value)}
                                placeholder="Number"
                              />
                            </div>
                          )
                        }
                        const searchOrNotSearch =
                          filterOperatorKey === 'search' ||
                          filterOperatorKey === 'notSearch'
                        const placeholder = searchOrNotSearch
                          ? 'Min. 3 characters'
                          : filterOperatorKey === 'regex'
                            ? 'e.g. ^foo.*bar$'
                            : 'Value'
                        return (
                          <div key="value-text">
                            {label}
                            <Input
                              className={inputClass}
                              value={filterValue}
                              onChange={(e) => setFilterValue(e.target.value)}
                              placeholder={placeholder}
                            />
                          </div>
                        )
                      })()}
                  </div>
                  <div className="border-t border-border px-4 py-3 flex flex-wrap items-center gap-2 bg-muted/30">
                    <Button
                      type="submit"
                      size="sm"
                      className="h-9 text-[13px]"
                      disabled={
                        !filterColumnId ||
                        !filterOperatorKey ||
                        (() => {
                          const col = usersFilterColumns.find(
                            (c) => c.id === filterColumnId,
                          )
                          if (!col) return true
                          const op = getOperatorsForType(col.type).find(
                            (o) => o.key === filterOperatorKey,
                          )
                          if (!op) return true
                          if (op.noValue) return false
                          if (
                            filterOperatorKey === 'between' ||
                            filterOperatorKey === 'notBetween'
                          ) {
                            return !filterValue.trim() || !filterValueEnd.trim()
                          }
                          return !filterValue.trim()
                        })()
                      }
                    >
                      Add condition
                    </Button>
                  </div>
                  </form>
                </PopoverContent>
              </Popover>
          ) : undefined
        }
        fullWidthBorder
        contentAfterBorder={smtpAlert}
        beforeCreateButtons={
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
                                  ? 'bg-muted'
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
                                        variant="error"
                                        className="text-[10px] shrink-0"
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
                                                    : 'warning'
                                                }
                                                className="text-[10px] shrink-0"
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
                                                    : 'warning'
                                                }
                                                className="text-[10px] shrink-0"
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
                    pageSize={urlLimit}
                    pageSizeOptions={[10, 25, 50, 100]}
                    onPageChange={handleUsersPageChange}
                    onPageSizeChange={handleUsersPageSizeChange}
                    itemLabel="users"
                  />
                </>
              ) : (
                <EmptyState
                  icon={Users}
                  title={
                    urlSearch || usersFilterMap.size > 0
                      ? undefined
                      : 'No users yet'
                  }
                  description={
                    urlSearch
                      ? `No results for "${urlSearch}". Try a different search.`
                      : usersFilterMap.size > 0
                        ? 'No users match your filters.'
                        : 'Create your first user to get started with authentication'
                  }
                  isEmpty={!(urlSearch || usersFilterMap.size > 0)}
                  hasFilters={!!(urlSearch || usersFilterMap.size > 0)}
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
                        title={
                          urlSearch || usersFilterMap.size > 0
                            ? undefined
                            : 'No users yet'
                        }
                        description={
                          urlSearch
                            ? `No results for "${urlSearch}". Try a different search.`
                            : usersFilterMap.size > 0
                              ? 'No users match your filters.'
                              : 'Create your first user to get started with authentication'
                        }
                        isEmpty={!(urlSearch || usersFilterMap.size > 0)}
                        hasFilters={!!(urlSearch || usersFilterMap.size > 0)}
                        variant="card"
                      />
                    </div>
                  )}
                </div>
                {paginatedUsers.length > 0 && (
                  <Pagination
                    currentPage={usersDisplayedPage}
                    totalItems={displayedUsersTotal ?? usersTotal}
                    pageSize={urlLimit}
                    pageSizeOptions={[10, 25, 50, 100]}
                    onPageChange={handleUsersPageChange}
                    onPageSizeChange={handleUsersPageSizeChange}
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
                                ? 'bg-muted'
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
